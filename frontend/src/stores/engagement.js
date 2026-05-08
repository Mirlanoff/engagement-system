import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { sessions, alerts } from '@/api'

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
  function upsertAlert(alert) {
    const alertId = alert.alert_id || alert.id
    if (!alertId) return

    const existingIndex = activeAlerts.value.findIndex(a => (a.alert_id || a.id) === alertId)
    if (existingIndex >= 0) {
      activeAlerts.value[existingIndex] = { ...activeAlerts.value[existingIndex], ...alert, alert_id: alertId }
      return
    }

    activeAlerts.value.unshift({ ...alert, alert_id: alertId })
  }

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
            cluster:           'mt1',
            enabledTransports: ['ws', 'wss'],
            authEndpoint:      '/broadcasting/auth',
            auth: {
              headers: { Authorization: `Bearer ${localStorage.getItem('token') || ''}` },
            },
          })

          echo.private(`school.${schoolId}`)
            .listen('.session.started', (e) => {
              if (!activeSessions.value.find(s => s.id === e.session_id)) {
                activeSessions.value.push({ id: e.session_id, ...e })
              }
            })
            .listen('.engagement.alert', (e) => {
              upsertAlert(e)
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
        upsertAlert(e)
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
      const { data } = await alerts.acknowledge(alertId)
      upsertAlert(data.data || { alert_id: alertId, is_acknowledged: true })
    } catch (e) {
      console.warn('acknowledge failed:', e)
    }
  }

  function disconnect() {
    echo?.disconnect()
    isConnected.value = false
    echo = null
  }

  return {
    activeSessions, studentScores, classAverages,
    activeAlerts, isConnected, alertCount, criticalAlerts,
    connectWebSocket, subscribeToSession, unsubscribeFromSession,
    loadActiveSessions, loadActiveAlerts, acknowledgeAlert, disconnect,
  }
})
