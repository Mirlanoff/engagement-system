import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { sessions, alerts } from '@/api'

export const useEngagementStore = defineStore('engagement', () => {
  const activeSessions  = ref([])
  const studentScores   = ref({})   // { sessionId: { studentId: {...} } }
  const classAverages   = ref({})   // { sessionId: score }
  const activeAlerts    = ref([])
  const echo            = ref(null)
  const isConnected     = ref(false)

  // ── Геттеры ───────────────────────────────────────────────────

  const alertCount = computed(() => activeAlerts.value.filter(a => !a.is_acknowledged).length)

  const criticalAlerts = computed(() =>
    activeAlerts.value.filter(a => a.severity === 'critical' && !a.is_acknowledged)
  )

  // ── WebSocket подключение ─────────────────────────────────────

  function connectWebSocket(schoolId) {
    window.Pusher = Pusher

    echo.value = new Echo({
      broadcaster:  'pusher',
      key:          import.meta.env.VITE_PUSHER_APP_KEY || 'engagement_key',
      wsHost:       window.location.hostname,
      wsPort:       6001,
      wssPort:      6001,
      forceTLS:     false,
      disableStats: true,
      enabledTransports: ['ws'],
    })

    // Канал школы — все сессии и алерты
    echo.value
      .private(`school.${schoolId}`)
      .listen('session.started', (e) => {
        activeSessions.value.push(e)
      })
      .listen('engagement.alert', (e) => {
        activeAlerts.value.unshift(e)
        // Звуковой сигнал для критических алертов
        if (e.severity === 'critical') playAlertSound()
      })

    isConnected.value = true
  }

  function subscribeToSession(sessionId) {
    if (!echo.value) return

    echo.value
      .join(`session.${sessionId}`)
      .listen('engagement.update', (e) => {
        // Обновляем scores студентов
        if (!studentScores.value[sessionId]) {
          studentScores.value[sessionId] = {}
        }
        e.students.forEach(s => {
          studentScores.value[sessionId][s.student_id] = s
        })
        classAverages.value[sessionId] = e.class_avg
      })
      .listen('session.ended', () => {
        activeSessions.value = activeSessions.value.filter(s => s.session_id !== sessionId)
      })
      .listen('engagement.alert', (e) => {
        activeAlerts.value.unshift(e)
      })
  }

  function unsubscribeFromSession(sessionId) {
    echo.value?.leave(`session.${sessionId}`)
    delete studentScores.value[sessionId]
    delete classAverages.value[sessionId]
  }

  // ── Загрузка данных ───────────────────────────────────────────

  async function loadActiveSessions() {
    const { data } = await sessions.active()
    activeSessions.value = data.data
  }

  async function loadActiveAlerts() {
    const { data } = await alerts.active()
    activeAlerts.value = data.data || []
  }

  async function acknowledgeAlert(alertId) {
    await alerts.acknowledge(alertId)
    const alert = activeAlerts.value.find(a => a.alert_id === alertId)
    if (alert) alert.is_acknowledged = true
  }

  function playAlertSound() {
    try {
      const ctx = new AudioContext()
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.connect(gain)
      gain.connect(ctx.destination)
      osc.frequency.setValueAtTime(880, ctx.currentTime)
      gain.gain.setValueAtTime(0.3, ctx.currentTime)
      gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5)
      osc.start(ctx.currentTime)
      osc.stop(ctx.currentTime + 0.5)
    } catch {}
  }

  function disconnect() {
    echo.value?.disconnect()
    isConnected.value = false
  }

  return {
    activeSessions, studentScores, classAverages,
    activeAlerts, isConnected, alertCount, criticalAlerts,
    connectWebSocket, subscribeToSession, unsubscribeFromSession,
    loadActiveSessions, loadActiveAlerts, acknowledgeAlert, disconnect,
  }
})
