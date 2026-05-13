<template>
  <div class="live-overview">

    <StartSessionModal
      v-if="showModal"
      @close="showModal = false"
      @started="onSessionStarted"
    />

    <!-- ─────────────────────────────────────────────────────────
         State 1: нет активных уроков
         ───────────────────────────────────────────────────────── -->
    <div v-if="sessions.length === 0" class="empty-state">
      <div class="empty-icon">📹</div>
      <h2 class="empty-title">Нет активных уроков</h2>
      <button class="start-btn-big" @click="showModal = true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <polygon points="6,4 20,12 6,20" fill="currentColor" stroke="none"/>
        </svg>
        Начать урок
      </button>
      <p v-if="todayCount > 0" class="today-summary">
        Сегодня проведено: {{ todayCount }} {{ pluralLesson(todayCount) }}
      </p>
    </div>

    <!-- ─────────────────────────────────────────────────────────
         States 2 + 3: список активных уроков, при клике
         карточка раскрывается на месте.
         ───────────────────────────────────────────────────────── -->
    <div v-else class="sessions-area">
      <div class="top-bar">
        <h2 class="top-title">Активные уроки</h2>
        <button class="start-btn" @click="showModal = true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <polygon points="6,4 20,12 6,20" fill="currentColor" stroke="none"/>
          </svg>
          Начать урок
        </button>
      </div>

      <div class="sessions-grid">
        <template v-for="session in sessions" :key="session.id">
          <div
            v-if="expandedId !== session.id"
            class="grid-cell"
          >
            <SessionCard
              :session="session"
              :class-avg="averages[session.id] || 0"
              :student-scores="scores[session.id] || {}"
              @click="expand(session.id)"
            />
          </div>

          <div
            v-else
            class="grid-cell expanded"
          >
            <LessonDetail
              :session="session"
              :class-avg="averages[session.id] || 0"
              :student-scores="scores[session.id] || {}"
              @collapse="collapse(session.id)"
            />
          </div>
        </template>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useEngagementStore } from '@/stores/engagement'
import { sessions as sessionsApi } from '@/api'
import SessionCard       from './SessionCard.vue'
import LessonDetail      from './LessonDetail.vue'
import StartSessionModal from './StartSessionModal.vue'

const props = defineProps({
  sessions: { type: Array,  default: () => [] },
  scores:   { type: Object, default: () => ({}) },
  averages: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['select', 'refresh', 'session-started'])

const engagementStore = useEngagementStore()
const showModal       = ref(false)
const expandedId      = ref(null)
const todayCount      = ref(0)

function onSessionStarted(session) {
  emit('refresh')
  emit('session-started', session)
}

// ── Подписка на live-данные для всех активных уроков ───────────
// Карточки сами должны показывать актуальную среднюю —
// поэтому подписываемся к каждому уроку как только он появляется.
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
    // Если раскрытый урок больше не активен — сворачиваем
    if (expandedId.value && !props.sessions.find(s => s.id === expandedId.value)) {
      expandedId.value = null
    }
  },
  { immediate: true },
)

function expand(sessionId) {
  expandedId.value = sessionId
  // На всякий случай переподписываемся (idempotent в store)
  engagementStore.subscribeToSession(sessionId)
  emit('select', props.sessions.find(s => s.id === sessionId))
}

function collapse() {
  expandedId.value = null
}

// ── Сводка "сегодня" для пустого состояния ────────────────────
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

function pluralLesson(n) {
  const m10 = n % 10
  const m100 = n % 100
  if (m100 >= 11 && m100 <= 14) return 'уроков'
  if (m10 === 1) return 'урок'
  if (m10 >= 2 && m10 <= 4) return 'урока'
  return 'уроков'
}

onMounted(() => {
  loadTodayCount()
})

onBeforeUnmount(() => {
  subscribed.clear()
})
</script>

<style scoped>
.live-overview {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* ── Empty state ────────────────────────────────────────────── */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  gap: 16px;
  text-align: center;
}
.empty-icon { font-size: 64px; opacity: 0.85; }
.empty-title {
  font-size: 22px;
  font-weight: 600;
  color: #cbd5e1;
  margin: 0;
}
.start-btn-big {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 14px 28px;
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
  margin-top: 6px;
}
.start-btn-big:hover  { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(99,102,241,0.45); }
.start-btn-big:active { transform: translateY(0); }
.start-btn-big svg    { width: 18px; height: 18px; }

.today-summary {
  font-size: 13px;
  color: #64748b;
  margin: 8px 0 0;
}

/* ── Top bar ────────────────────────────────────────────────── */
.top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.top-title {
  font-size: 15px;
  font-weight: 600;
  color: #cbd5e1;
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.start-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  background: linear-gradient(135deg,#6366f1,#8b5cf6);
  border: none;
  border-radius: 10px;
  color: white;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.start-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(99,102,241,0.35); }
.start-btn svg   { width: 14px; height: 14px; }

/* ── Sessions grid ──────────────────────────────────────────── */
.sessions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 16px;
}
.grid-cell {
  display: flex;
  flex-direction: column;
  transition: all 0.3s ease;
}
.grid-cell.expanded {
  grid-column: 1 / -1;
}

@media (min-width: 1600px) {
  .sessions-grid { grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); }
}
@media (max-width: 900px) {
  .sessions-grid { grid-template-columns: 1fr; }
}
</style>
