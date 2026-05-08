import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { sessions, alerts, admin } from '@/api'

export const useEngagementStore = defineStore('engagement', () => {
  const activeSessions  = ref([])
  const studentScores   = ref({})
  const classAverages   = ref({})
  const activeAlerts    = ref([])
  const isConnected     = ref(false)
  let   echo            = null

  const alertCount     = computed(() => activeAlerts.value.filter(a => !a.is_acknowledged).length)
  const criticalAlerts = computed(() => activeAlerts.value.filter(a => a.severity === 'critical' && !a.is_acknowledged))

  // ── WebSocket ─────────────────────────────────────────────────
  function connectWebSocket(schoolId) {
    try {
      // Динамический импорт чтобы не падал при ошибке
      import('laravel-echo').then(({ default: Echo }) => {
        import('pusher-js').then(({ default: Pusher }) => {
          window.Pusher = Pusher

          echo = new Echo({
            broadcaster:       'pusher',
            key:               import.meta.env.VITE_PUSHER_APP_KEY || 'engagement_key',
            wsHost:            window.location.hostname,
            wsPort:            6001,
            wssPort:           6001,
            forceTLS:          false,
            disableStats:      true,
            cluster:           'mt1',          // ← обязательный параметр
            enabledTransports: ['ws', 'wss'],
          })

          echo.private(`school.${schoolId}`)
            .listen('.session.started', (e) => {
              if (!activeSessions.value.find(s => s.id === e.session_id)) {
                activeSessions.value.push({ id: e.session_id, ...e })
              }
            })
            .listen('.engagement.alert', (e) => {
              activeAlerts.value.unshift(e)
              if (e.severity === 'critical') playAlertSound()
            })

          echo.channel('dashboard')
            .listen('.dashboard.reset', () => {
              clearLocalState()
            })

          isConnected.value = true
          console.log('[WS] Connected to school channel', schoolId)
        })
      })
    } catch (e) {
      console.warn('[WS] Connection failed:', e)
    }
  }

  function subscribeToSession(sessionId) {
    if (!echo) return
    echo.join(`session.${sessionId}`)
      .listen('.engagement.update', (e) => {
        if (!studentScores.value[sessionId]) studentScores.value[sessionId] = {}
        e.students?.forEach(s => {
          studentScores.value[sessionId][s.student_id] = s
        })
        classAverages.value[sessionId] = e.class_avg
      })
      .listen('.session.ended', () => {
        activeSessions.value = activeSessions.value.filter(s => s.id !== sessionId)
      })
      .listen('.engagement.alert', (e) => {
        activeAlerts.value.unshift(e)
      })
  }

  function unsubscribeFromSession(sessionId) {
    echo?.leave(`session.${sessionId}`)
    delete studentScores.value[sessionId]
    delete classAverages.value[sessionId]
  }

  // ── Загрузка данных ───────────────────────────────────────────
  async function loadActiveSessions() {
    try {
      const { data } = await sessions.active()
      activeSessions.value = data.data || []
    } catch (e) {
      console.warn('loadActiveSessions failed:', e)
      activeSessions.value = []
    }
  }

  async function loadActiveAlerts() {
    try {
      const { data } = await alerts.active()
      activeAlerts.value = data.data || []
    } catch (e) {
      activeAlerts.value = []
    }
  }

  async function acknowledgeAlert(alertId) {
    try {
      await alerts.acknowledge(alertId)
      const a = activeAlerts.value.find(x => x.alert_id === alertId)
      if (a) a.is_acknowledged = true
    } catch (e) {
      console.warn('acknowledge failed:', e)
    }
  }

  function playAlertSound() {
    try {
      const ctx  = new AudioContext()
      const osc  = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.connect(gain); gain.connect(ctx.destination)
      osc.frequency.setValueAtTime(880, ctx.currentTime)
      gain.gain.setValueAtTime(0.3, ctx.currentTime)
      gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5)
      osc.start(); osc.stop(ctx.currentTime + 0.5)
    } catch {}
  }

  function disconnect() {
    echo?.disconnect()
    isConnected.value = false
    echo = null
  }

  function clearLocalState() {
    activeSessions.value = []
    studentScores.value  = {}
    classAverages.value  = {}
    activeAlerts.value   = []
  }

  async function resetDashboard({ keepCompleted = false } = {}) {
    const { data } = await admin.resetDashboard(keepCompleted)
    clearLocalState()
    return data
  }

  return {
    activeSessions, studentScores, classAverages,
    activeAlerts, isConnected, alertCount, criticalAlerts,
    connectWebSocket, subscribeToSession, unsubscribeFromSession,
    loadActiveSessions, loadActiveAlerts, acknowledgeAlert, disconnect,
    resetDashboard, clearLocalState,
  }
})
