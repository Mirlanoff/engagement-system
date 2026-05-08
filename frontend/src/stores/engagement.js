import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { sessions, alerts } from '@/api'

export const useEngagementStore = defineStore('engagement', () => {
  const activeSessions   = ref([])
  const studentScores    = ref({})
  const classAverages    = ref({})
  const studentsPresent  = ref({})   // sessionId -> int
  const studentsTotal    = ref({})   // sessionId -> int
  const distributions    = ref({})   // sessionId -> { high, medium, low }
  const emotions         = ref({})   // sessionId -> { happy: n, neutral: n, ... }
  const timelines        = ref({})   // sessionId -> [{ t, avg, present }, ...]
  const activeAlerts     = ref([])
  const isConnected      = ref(false)
  let   echo             = null

  const TIMELINE_MAX_POINTS = 240   // ~20 минут при 5-секундном цикле

  const alertCount     = computed(() => activeAlerts.value.filter(a => !a.is_acknowledged).length)
  const criticalAlerts = computed(() => activeAlerts.value.filter(a => a.severity === 'critical' && !a.is_acknowledged))

  const totalStudentsPresent = computed(() =>
    activeSessions.value.reduce((sum, s) => sum + (studentsPresent.value[s.id] ?? s.students_present ?? 0), 0)
  )

  const overallClassAverage = computed(() => {
    const values = activeSessions.value
      .map(s => classAverages.value[s.id] ?? s.live_avg_score)
      .filter(v => typeof v === 'number' && !Number.isNaN(v))
    if (!values.length) return 0
    return Math.round(values.reduce((a, b) => a + b, 0) / values.length)
  })

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
        applyEngagementUpdate(sessionId, e)
      })
      .listen('.session.ended', () => {
        activeSessions.value = activeSessions.value.filter(s => s.id !== sessionId)
      })
      .listen('.engagement.alert', (e) => {
        activeAlerts.value.unshift(e)
      })
  }

  function applyEngagementUpdate(sessionId, e) {
    if (!studentScores.value[sessionId]) studentScores.value[sessionId] = {}
    e.students?.forEach(s => {
      studentScores.value[sessionId][s.student_id] = s
    })
    classAverages.value[sessionId]   = e.class_avg ?? 0
    studentsPresent.value[sessionId] = e.students_present ?? 0
    studentsTotal.value[sessionId]   = e.students_total ?? (e.students?.length ?? 0)
    distributions.value[sessionId]   = e.distribution || { high: 0, medium: 0, low: 0 }
    emotions.value[sessionId]        = e.emotions || {}

    const series = timelines.value[sessionId] || []
    series.push({
      t:       e.timestamp || new Date().toISOString(),
      avg:     e.class_avg ?? 0,
      present: e.students_present ?? 0,
    })
    if (series.length > TIMELINE_MAX_POINTS) {
      series.splice(0, series.length - TIMELINE_MAX_POINTS)
    }
    timelines.value[sessionId] = series

    // Синхронизируем кешированную сессию (для дашборда без подписки на канал).
    const sess = activeSessions.value.find(s => s.id === sessionId)
    if (sess) {
      sess.students_present = e.students_present ?? sess.students_present ?? 0
      sess.live_avg_score   = e.class_avg ?? sess.live_avg_score
    }
  }

  function unsubscribeFromSession(sessionId) {
    echo?.leave(`session.${sessionId}`)
    delete studentScores.value[sessionId]
    delete classAverages.value[sessionId]
    delete studentsPresent.value[sessionId]
    delete studentsTotal.value[sessionId]
    delete distributions.value[sessionId]
    delete emotions.value[sessionId]
    delete timelines.value[sessionId]
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

  return {
    activeSessions, studentScores, classAverages,
    studentsPresent, studentsTotal, distributions, emotions, timelines,
    activeAlerts, isConnected, alertCount, criticalAlerts,
    totalStudentsPresent, overallClassAverage,
    connectWebSocket, subscribeToSession, unsubscribeFromSession,
    loadActiveSessions, loadActiveAlerts, acknowledgeAlert, disconnect,
  }
})
