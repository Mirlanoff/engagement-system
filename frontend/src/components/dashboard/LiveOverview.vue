<template>
  <div class="live-overview">

    <StartSessionModal
      v-if="showModal"
      @close="showModal = false"
      @started="onSessionStarted"
    />

    <StudentRegistrationModal
      v-if="showStudentModal"
      @close="showStudentModal = false"
      @registered="onStudentRegistered"
    />

    <!-- State 1: no active lessons -->
    <div v-if="sessions.length === 0" class="empty-state">
      <div class="empty-icon">📹</div>
      <h2 class="empty-title">Нет активных уроков</h2>
      <div class="empty-actions">
        <button class="start-btn-big" @click="showModal = true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <polygon points="6,4 20,12 6,20" fill="currentColor" stroke="none"/>
          </svg>
          Начать урок
        </button>
        <button class="register-btn" @click="showStudentModal = true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18">
            <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4-4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <line x1="19" y1="8" x2="19" y2="14"/>
            <line x1="22" y1="11" x2="16" y2="11"/>
          </svg>
          Регистрация студента
        </button>
      </div>
      <p v-if="todayCount > 0" class="today-summary">
        Сегодня проведено: {{ todayCount }} {{ pluralLesson(todayCount) }}
      </p>
    </div>

    <!-- State 2: active lessons -->
    <div v-else class="sessions-area">
      <div class="top-bar">
        <h2 class="top-title">Активный урок</h2>
        <div class="top-actions">
          <button class="register-btn-sm" @click="showStudentModal = true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
              <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4-4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <line x1="19" y1="8" x2="19" y2="14"/>
              <line x1="22" y1="11" x2="16" y2="11"/>
            </svg>
            Регистрация
          </button>
          <button class="start-btn" @click="showModal = true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <polygon points="6,4 20,12 6,20" fill="currentColor" stroke="none"/>
            </svg>
            Начать урок
          </button>
        </div>
      </div>

      <!-- Compact Active Session Card -->
      <div
        v-for="session in sessions"
        :key="session.id"
        class="active-lesson-card"
      >
        <div class="lesson-title-row">
          <h3 class="lesson-name">{{ session.subject || session.classroom_name || 'Урок' }}</h3>
          <div class="live-badge">
            <span class="live-dot"></span>Live
          </div>
        </div>
        <div class="lesson-meta">
          <span class="meta-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
              <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2z"/>
              <path d="M16 2v4M8 2v4M3 10h18"/>
            </svg>
            {{ session.classroom_name || 'Класс' }}
          </span>
          <span class="meta-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
              <circle cx="12" cy="12" r="10"/>
              <path d="M12 6v6l4 2"/>
            </svg>
            {{ formatTime(session.started_at) }}
          </span>
          <span class="meta-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
              <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 00-3-3.87"/>
              <path d="M16 3.13a4 4 0 010 7.75"/>
            </svg>
            {{ getOnlineCount(session.id) }}/{{ getTotalStudents(session.id) }}
          </span>
          <span class="meta-item duration">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
              <path d="M5 3l14 9-14 9V3z"/>
            </svg>
            {{ getDuration(session.started_at) }}
          </span>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import { useEngagementStore } from '@/stores/engagement'
import { sessions as sessionsApi } from '@/api'
import StartSessionModal from './StartSessionModal.vue'
import StudentRegistrationModal from './StudentRegistrationModal.vue'

const props = defineProps({
  sessions: { type: Array,  default: () => [] },
  scores:   { type: Object, default: () => ({}) },
  averages: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['select', 'refresh', 'session-started'])

const engagementStore = useEngagementStore()
const showModal       = ref(false)
const showStudentModal = ref(false)
const todayCount      = ref(0)
const now             = ref(Date.now())
const studentsList    = ref({})

let nowTimer = null
let pollTimer = null

function onSessionStarted(session) {
  emit('refresh')
  emit('session-started', session)
}

function onStudentRegistered(student) {
  console.log('Студент зарегистрирован:', student)
}

// ── Polling: load students every 5 seconds ──────────────────
async function loadStudentsForSessions() {
  for (const session of props.sessions) {
    try {
      const { data } = await sessionsApi.students(session.id)
      const list = data.data || data || []
      // Merge with real-time scores from WebSocket
      const scores = props.scores[session.id] || {}
      studentsList.value[session.id] = list.map(s => {
        const live = scores[s.student_id || s.id] || {}
        return {
          id: s.student_id || s.id,
          name: s.name || s.student_name || 'Студент',
          engagement: Math.round(live.engagement_score ?? live.score ?? s.engagement_score ?? 0),
          emotion: live.emotion || s.emotion || 'neutral',
          face_detected: live.face_detected ?? s.face_detected ?? false,
        }
      })
    } catch (e) {
      // If endpoint fails, build from WebSocket scores
      const scores = props.scores[session.id] || {}
      if (Object.keys(scores).length > 0) {
        studentsList.value[session.id] = Object.entries(scores).map(([id, s]) => ({
          id,
          name: s.student_name || s.name || 'Студент',
          engagement: Math.round(s.engagement_score ?? s.score ?? 0),
          emotion: s.emotion || 'neutral',
          face_detected: s.face_detected ?? false,
        }))
      }
    }
  }
}

// ── Helpers ──────────────────────────────────────────────────
function formatTime(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function getDuration(dateStr) {
  if (!dateStr) return '—'
  const started = new Date(dateStr).getTime()
  const mins = Math.max(0, Math.floor((now.value - started) / 60000))
  if (mins < 1) return 'только что'
  if (mins < 60) return `${mins} мин`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return `${h} ч ${m} мин`
}

function getOnlineCount(sessionId) {
  const list = studentsList.value[sessionId] || []
  return list.filter(s => s.face_detected || s.engagement > 0).length
}

function getTotalStudents(sessionId) {
  const list = studentsList.value[sessionId] || []
  return list.length
}

function getInitials(name) {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase()
  return name[0].toUpperCase()
}

function getEngagementLevel(value) {
  if (value > 70) return 'high'
  if (value >= 50) return 'medium'
  return 'low'
}

function getEmotionLabel(emotion) {
  const map = {
    happy: 'Радость',
    neutral: 'Нейтральная',
    sad: 'Грусть',
    angry: 'Злость',
    surprise: 'Удивление',
    fear: 'Страх',
    disgust: 'Отвращение',
    focused: 'Сосредоточен',
    bored: 'Скучает',
    confused: 'Смущение',
  }
  return map[emotion] || emotion || 'Нейтральная'
}

function pluralLesson(n) {
  const m10  = n % 10
  const m100 = n % 100
  if (m100 >= 11 && m100 <= 14) return 'уроков'
  if (m10 === 1)                return 'урок'
  if (m10 >= 2 && m10 <= 4)     return 'урока'
  return 'уроков'
}

async function loadTodayCount() {
  try {
    const { data } = await sessionsApi.list({ status: 'completed' })
    const today = new Date().toISOString().slice(0, 10)
    const list  = Array.isArray(data?.data) ? data.data : []
    todayCount.value = list.filter(s => {
      const t = s.started_at || s.created_at
      return t && String(t).startsWith(today)
    }).length
  } catch (e) {
    todayCount.value = 0
  }
}

// ── WebSocket subscriptions ──────────────────────────────────
const subscribed = new Set()

watch(
  () => props.sessions.map(s => s.id).join(','),
  () => {
    for (const s of props.sessions) {
      if (s?.id && !subscribed.has(s.id)) {
        engagementStore.subscribeToSession(s.id)
        subscribed.add(s.id)
      }
    }
    // Load students when sessions change
    loadStudentsForSessions()
  },
  { immediate: true },
)

// Update student list when scores change from WebSocket
watch(
  () => JSON.stringify(props.scores),
  () => {
    // Merge live scores into studentsList
    for (const session of props.sessions) {
      const scores = props.scores[session.id] || {}
      const list = studentsList.value[session.id]
      if (list && Object.keys(scores).length > 0) {
        studentsList.value[session.id] = list.map(s => {
          const live = scores[s.id] || {}
          return {
            ...s,
            engagement: Math.round(live.engagement_score ?? live.score ?? s.engagement),
            emotion: live.emotion || s.emotion,
            face_detected: live.face_detected ?? s.face_detected,
          }
        })
      }
    }
  },
)

onMounted(() => {
  loadTodayCount()
  loadStudentsForSessions()
  // Update timer every 30s for duration display
  nowTimer = setInterval(() => { now.value = Date.now() }, 30_000)
  // Poll student data every 5 seconds
  pollTimer = setInterval(loadStudentsForSessions, 5000)
})

onBeforeUnmount(() => {
  subscribed.clear()
  if (nowTimer) clearInterval(nowTimer)
  if (pollTimer) clearInterval(pollTimer)
  nowTimer = null
  pollTimer = null
})
</script>

<style scoped>
.live-overview {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

/* Empty state */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  gap: 16px;
  text-align: center;
}
.empty-icon  { font-size: 56px; opacity: 0.85; }
.empty-title {
  font-size: 22px;
  font-weight: 600;
  color: #cbd5e1;
  margin: 0;
}
.empty-actions {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
  justify-content: center;
  margin-top: 8px;
}
.start-btn-big {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 14px 30px;
  background: linear-gradient(135deg,#6366f1,#8b5cf6);
  border: none;
  border-radius: 12px;
  color: white;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
  box-shadow: 0 8px 24px rgba(99,102,241,0.35);
}
.start-btn-big:hover  { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(99,102,241,0.45); }
.start-btn-big svg    { width: 18px; height: 18px; }

.register-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 14px 24px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(99,102,241,0.4);
  border-radius: 12px;
  color: #a5b4fc;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.register-btn:hover {
  background: rgba(99,102,241,0.1);
  border-color: rgba(99,102,241,0.6);
  color: #c7d2fe;
  transform: translateY(-1px);
}

.today-summary {
  margin-top: 6px;
  color: #64748b;
  font-size: 13px;
}

/* Sessions area */
.sessions-area {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.top-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.top-title {
  font-size: 18px;
  font-weight: 600;
  color: #f1f5f9;
  margin: 0;
}
.start-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  background: linear-gradient(135deg,#6366f1,#8b5cf6);
  border: none;
  border-radius: 9px;
  color: white;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.start-btn:hover  { transform: translateY(-1px); }
.start-btn svg    { width: 14px; height: 14px; }

.register-btn-sm {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 14px;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(99,102,241,0.35);
  border-radius: 9px;
  color: #a5b4fc;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.register-btn-sm:hover {
  background: rgba(99,102,241,0.1);
  border-color: rgba(99,102,241,0.55);
  color: #c7d2fe;
  transform: translateY(-1px);
}

/* Active lesson card */
.active-lesson-card {
  background: #1e293b;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
}

.lesson-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.lesson-name {
  font-size: 16px;
  font-weight: 700;
  color: #f1f5f9;
  margin: 0;
}
.live-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  background: rgba(239,68,68,0.1);
  border: 1px solid rgba(239,68,68,0.3);
  border-radius: 20px;
  font-size: 10px;
  color: #ef4444;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.live-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #ef4444;
  animation: pulse 1.4s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%      { opacity: 0.4; transform: scale(0.8); }
}

.lesson-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
}
.meta-item {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  color: #94a3b8;
}
.meta-item svg { flex-shrink: 0; opacity: 0.7; }
.meta-item.duration { color: #a5b4fc; font-weight: 500; }
</style>
