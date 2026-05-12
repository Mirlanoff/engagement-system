<template>
  <div class="live-overview">

    <StartSessionModal
      v-if="showModal"
      @close="showModal = false"
      @started="onSessionStarted"
    />

    <!-- ── Верхняя KPI-полоса школы ─────────────────────────── -->
    <div class="top-bar">
      <div class="kpi-bar">
        <div class="kpi-hero">
          <div class="hero-value" :class="schoolLevel">
            {{ schoolAvgDisplay }}<span class="hero-pct">%</span>
          </div>
          <div class="hero-label">
            <span class="hero-title">Средняя вовлечённость по школе</span>
            <span class="hero-sub">{{ schoolStatusLabel }}</span>
          </div>
        </div>

        <div class="kpi-tiles">
          <div class="kpi-tile">
            <span class="tile-value">{{ sessions.length }}</span>
            <span class="tile-label">Активных уроков</span>
          </div>
          <div class="kpi-tile">
            <span class="tile-value">{{ totalDetected }}</span>
            <span class="tile-label">Студентов на камере</span>
            <span class="tile-sub">из {{ totalEnrolled }}</span>
          </div>
          <div class="kpi-tile" :class="{ danger: alertCount > 0 }">
            <span class="tile-value">{{ alertCount }}</span>
            <span class="tile-label">Активных алертов</span>
          </div>
        </div>
      </div>

      <button class="start-btn" @click="showModal = true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polygon points="5,3 19,12 5,21"/>
        </svg>
        Начать урок
      </button>
    </div>

    <!-- ── Пустое состояние ────────────────────────────────── -->
    <div v-if="sessions.length === 0" class="empty-state">
      <div class="empty-icon">📹</div>
      <h3>Нет активных уроков</h3>
      <p>Нажми «Начать урок» чтобы запустить мониторинг класса</p>
    </div>

    <!-- ── Карточки активных уроков ────────────────────────── -->
    <div v-else class="sessions-grid">
      <SessionCard
        v-for="session in sessions"
        :key="session.id"
        :session="session"
        :class-avg="averages[session.id] || session.avg_engagement_score || 0"
        :student-scores="scores[session.id] || {}"
        @click="$emit('select', session)"
      />
    </div>

  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useEngagementStore } from '@/stores/engagement'
import SessionCard       from './SessionCard.vue'
import StartSessionModal from './StartSessionModal.vue'

const props = defineProps({
  sessions: { type: Array,  default: () => [] },
  scores:   { type: Object, default: () => ({}) },
  averages: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['select', 'refresh', 'session-started'])

const engagementStore = useEngagementStore()
const showModal = ref(false)

function onSessionStarted(session) {
  emit('refresh')
  emit('session-started', session)
}

// ── Считаем агрегаты по школе ─────────────────────────────────
const totalEnrolled = computed(() =>
  props.sessions.reduce((s, sess) => s + (sess.students_count || 0), 0)
)

const totalDetected = computed(() => {
  let count = 0
  for (const sess of props.sessions) {
    const sc = props.scores[sess.id] || {}
    for (const id of Object.keys(sc)) {
      if (sc[id]?.face_detected !== false) count++
    }
  }
  return count
})

const schoolAvg = computed(() => {
  if (!props.sessions.length) return 0
  const avgs = props.sessions
    .map(s => props.averages[s.id] || s.avg_engagement_score || 0)
    .filter(v => v > 0)
  if (!avgs.length) return 0
  return Math.round(avgs.reduce((a, b) => a + b, 0) / avgs.length)
})

const schoolAvgDisplay = computed(() =>
  schoolAvg.value > 0 ? schoolAvg.value : '—'
)

const schoolLevel = computed(() => {
  if (!schoolAvg.value) return 'unknown'
  if (schoolAvg.value >= 70) return 'high'
  if (schoolAvg.value >= 50) return 'medium'
  return 'low'
})

const schoolStatusLabel = computed(() => {
  if (!props.sessions.length) return 'Уроки не запущены'
  if (!schoolAvg.value)       return 'Ожидаем первые данные'
  if (schoolAvg.value >= 70)  return 'Высокая вовлечённость'
  if (schoolAvg.value >= 50)  return 'Средняя вовлечённость'
  return 'Требуется внимание'
})

const alertCount = computed(() => engagementStore.alertCount || 0)

// ── Подписываемся на каждый активный урок (live %, студенты) ─
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

onBeforeUnmount(() => {
  // Отписку от каналов выполняет DashboardView через store.disconnect() при logout,
  // а данные нужны и для других вкладок — поэтому здесь ничего не выключаем.
  subscribed.clear()
})
</script>

<style scoped>
.live-overview { display:flex; flex-direction:column; gap:20px; }

/* ── Top bar ────────────────────────────────────────────────── */
.top-bar {
  display: flex;
  align-items: stretch;
  gap: 16px;
}
.kpi-bar {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(260px, 1fr) 2fr;
  gap: 12px;
  background: #1e293b;
  border: 1px solid rgba(255,255,255,0.07);
  border-radius: 14px;
  padding: 16px 18px;
}
.kpi-hero {
  display: flex;
  align-items: center;
  gap: 14px;
}
.hero-value {
  font-size: 48px;
  font-weight: 700;
  letter-spacing: -1.5px;
  line-height: 1;
  color: #94a3b8;
  font-variant-numeric: tabular-nums;
  transition: color 0.3s;
}
.hero-pct { font-size: 20px; font-weight: 500; margin-left: 2px; }
.hero-value.high    { color: #22c55e; }
.hero-value.medium  { color: #f59e0b; }
.hero-value.low     { color: #ef4444; }
.hero-value.unknown { color: #475569; }

.hero-label { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.hero-title {
  font-size: 12px;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.hero-sub {
  font-size: 13px;
  color: #cbd5e1;
  font-weight: 500;
}

.kpi-tiles {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}
.kpi-tile {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.kpi-tile.danger { border-color: rgba(239,68,68,0.4); }
.tile-value {
  font-size: 22px;
  font-weight: 700;
  color: #f1f5f9;
  letter-spacing: -0.5px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.kpi-tile.danger .tile-value { color: #ef4444; }
.tile-label { font-size: 11px; color: #64748b; }
.tile-sub   { font-size: 11px; color: #475569; }

.start-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 20px;
  background: linear-gradient(135deg,#6366f1,#8b5cf6);
  border: none;
  border-radius: 12px;
  color: white;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: opacity .15s, transform .15s;
  font-family: inherit;
  align-self: stretch;
}
.start-btn:hover  { opacity: .92; transform: translateY(-1px); }
.start-btn svg    { width: 14px; height: 14px; }

/* ── Empty state ────────────────────────────────────────────── */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  color: #475569;
  text-align: center;
}
.empty-icon { font-size: 48px; margin-bottom: 16px; }
.empty-state h3 { font-size: 16px; font-weight: 600; color: #64748b; margin: 0 0 8px; }
.empty-state p  { font-size: 13px; color: #475569; margin: 0; }

/* ── Cards grid ─────────────────────────────────────────────── */
.sessions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
  gap: 16px;
}

@media (max-width: 1100px) {
  .kpi-bar { grid-template-columns: 1fr; }
  .kpi-tiles { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 720px) {
  .kpi-tiles { grid-template-columns: 1fr 1fr; }
  .sessions-grid { grid-template-columns: 1fr; }
}
</style>
