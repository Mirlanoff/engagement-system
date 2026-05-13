<template>
  <div class="live-overview">

    <StartSessionModal
      v-if="showModal"
      @close="showModal = false"
      @started="onSessionStarted"
    />

    <!-- State 1: no active lessons -->
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

    <!--
      State 2: active lesson cards only.
      Per-student analytics lives on the «Аналитика» tab.
    -->
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

      <p class="hint">
        Подробная статистика по студентам — на вкладке
        <span class="hint-tab">«Аналитика»</span>.
      </p>

      <div class="sessions-grid">
        <div
          v-for="session in sessions"
          :key="session.id"
          class="grid-cell"
        >
          <SessionCard
            :session="session"
            :class-avg="averages[session.id] || 0"
            :student-scores="scores[session.id] || {}"
          />
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useEngagementStore } from '@/stores/engagement'
import { sessions as sessionsApi } from '@/api'
import SessionCard       from './SessionCard.vue'
import StartSessionModal from './StartSessionModal.vue'

const props = defineProps({
  sessions: { type: Array,  default: () => [] },
  scores:   { type: Object, default: () => ({}) },
  averages: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['select', 'refresh', 'session-started'])

const engagementStore = useEngagementStore()
const showModal       = ref(false)
const todayCount      = ref(0)

function onSessionStarted(session) {
  emit('refresh')
  emit('session-started', session)
}

// Keep cards live-updated: subscribe to every active session.
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
  },
  { immediate: true },
)

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
  const m10  = n % 10
  const m100 = n % 100
  if (m100 >= 11 && m100 <= 14) return 'уроков'
  if (m10 === 1)                return 'урок'
  if (m10 >= 2 && m10 <= 4)     return 'урока'
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
  margin-top: 10px;
}
.start-btn-big:hover  { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(99,102,241,0.45); }
.start-btn-big:active { transform: translateY(0); }
.start-btn-big svg    { width: 18px; height: 18px; }

.today-summary {
  margin-top: 6px;
  color: #64748b;
  font-size: 13px;
}

/* Sessions area */
.sessions-area {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
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

.hint {
  margin: 0;
  font-size: 12.5px;
  color: #64748b;
}
.hint-tab {
  color: #cbd5e1;
  font-weight: 600;
}

.sessions-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 14px;
}
@media (min-width: 1100px) {
  .sessions-grid { grid-template-columns: 1fr 1fr; }
}
@media (min-width: 1600px) {
  .sessions-grid { grid-template-columns: 1fr 1fr 1fr; }
}
.grid-cell { min-width: 0; }
</style>
