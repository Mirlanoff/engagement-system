<template>
  <div class="compare-tab">
    <div v-if="loading" class="state">
      <div class="spinner"></div>
      <p>Загружаем аналитику...</p>
    </div>

    <div v-else-if="error" class="state error">
      <p>Ошибка загрузки</p>
      <button class="retry" @click="load">Повторить</button>
    </div>

    <div v-else-if="!hasData" class="state empty">
      <div class="empty-icon">📊</div>
      <h3>Нет данных за выбранный период</h3>
      <p>Попробуйте выбрать другой диапазон дат.</p>
    </div>

    <template v-else>
      <!-- ── KPI cards ───────────────────────────────────────── -->
      <div class="kpi-row">
        <KpiCard
          label="Средняя по школе"
          :value="formatPct(summary.school_avg)"
          :tone="scoreTone(summary.school_avg)"
          :sub="deltaSub"
        />
        <KpiCard
          label="Лучший класс"
          :value="summary.best_classroom?.classroom_name || '—'"
          :sub="summary.best_classroom ? `средний ${formatPct(summary.best_classroom.avg_score)}` : ''"
          tone="success"
        />
        <KpiCard
          label="Уроков за период"
          :value="String(totalSessions)"
          :sub="`${classroomsWithData} из ${summary.total_classrooms} классов`"
        />
      </div>

      <!-- ── Distribution row ────────────────────────────────── -->
      <div class="dist-card">
        <div class="dist-header">
          <h3>Распределение вовлечённости</h3>
          <span class="hint">всего минут с детекцией: {{ totalMinutes }}</span>
        </div>
        <div class="dist-grid">
          <div class="dist-item">
            <div class="dist-meta">
              <span class="dist-dot success"></span>
              <span class="dist-label">Высокая (75%+)</span>
              <span class="dist-value">{{ distPercent.high }}%</span>
            </div>
            <div class="dist-track">
              <div class="dist-fill success" :style="{ width: distPercent.high + '%' }"></div>
            </div>
            <span class="dist-sub">{{ distTotals.high }} минут</span>
          </div>
          <div class="dist-item">
            <div class="dist-meta">
              <span class="dist-dot warning"></span>
              <span class="dist-label">Средняя (50–75%)</span>
              <span class="dist-value">{{ distPercent.medium }}%</span>
            </div>
            <div class="dist-track">
              <div class="dist-fill warning" :style="{ width: distPercent.medium + '%' }"></div>
            </div>
            <span class="dist-sub">{{ distTotals.medium }} минут</span>
          </div>
          <div class="dist-item">
            <div class="dist-meta">
              <span class="dist-dot danger"></span>
              <span class="dist-label">Низкая (&lt;50%)</span>
              <span class="dist-value">{{ distPercent.low }}%</span>
            </div>
            <div class="dist-track">
              <div class="dist-fill danger" :style="{ width: distPercent.low + '%' }"></div>
            </div>
            <span class="dist-sub">{{ distTotals.low }} минут</span>
          </div>
        </div>
      </div>

      <!-- ── Class comparison bars ──────────────────────────── -->
      <div class="chart-card">
        <div class="chart-card-header">
          <h3>Сравнение классов</h3>
          <span class="hint">{{ classroomsWithData }} из {{ summary.total_classrooms }} активны</span>
        </div>
        <ul class="class-bars">
          <li v-for="c in sortedClassroomsAll" :key="classroomKey(c)" class="class-bar">
            <div class="class-bar-name" :title="c.classroom_name">{{ c.classroom_name }}</div>
            <div class="class-bar-track">
              <div
                v-if="hasClassData(c)"
                class="class-bar-fill"
                :class="scoreTone(c.avg_score)"
                :style="{ width: Math.min(100, Math.max(2, Number(c.avg_score) || 0)) + '%' }"
              ></div>
              <span v-else class="class-bar-empty">нет данных</span>
            </div>
            <div class="class-bar-value" :class="hasClassData(c) ? scoreTone(c.avg_score) : 'muted'">
              <template v-if="hasClassData(c)">{{ formatPct(c.avg_score) }}</template>
              <template v-else>—</template>
            </div>
            <div class="class-bar-sub">
              <template v-if="hasClassData(c)">{{ c.sessions_count }} {{ sessionsWord(c.sessions_count) }}</template>
              <template v-else>0 уроков</template>
            </div>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, inject } from 'vue'
import { analytics } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'
import KpiCard from './KpiCard.vue'

const { from, to } = useAnalyticsFilters()

const loading    = ref(false)
const error      = ref(false)
const classrooms = ref([])
const summary    = ref({ total_classrooms: 0, school_avg: 0, best_classroom: null, worst_classroom: null })
const prevSchoolAvg = ref(null)

const emit = defineEmits(['loaded'])

const hasData = computed(() => classrooms.value.length > 0)

const sortedClassroomsAll = computed(() => {
  // Сначала классы с данными (по убыванию avg_score), потом без данных
  const withData = [...classrooms.value].sort((a, b) => (b.avg_score || 0) - (a.avg_score || 0))
  return withData
})

const classroomsWithData = computed(() => classrooms.value.length)

const totalSessions = computed(() =>
  classrooms.value.reduce((s, c) => s + (Number(c.sessions_count) || 0), 0)
)

const distTotals = computed(() => {
  const out = { high: 0, medium: 0, low: 0 }
  for (const c of classrooms.value) {
    out.high   += Number(c.distribution?.high)   || 0
    out.medium += Number(c.distribution?.medium) || 0
    out.low    += Number(c.distribution?.low)    || 0
  }
  return out
})

const totalMinutes = computed(() =>
  distTotals.value.high + distTotals.value.medium + distTotals.value.low
)

const distPercent = computed(() => {
  const t = totalMinutes.value
  if (!t) return { high: 0, medium: 0, low: 0 }
  return {
    high:   Math.round((distTotals.value.high   / t) * 100),
    medium: Math.round((distTotals.value.medium / t) * 100),
    low:    Math.round((distTotals.value.low    / t) * 100),
  }
})

const deltaSub = computed(() => {
  if (prevSchoolAvg.value === null) {
    return `${classroomsWithData.value} из ${summary.value.total_classrooms} классов с данными`
  }
  const cur  = Number(summary.value.school_avg) || 0
  const prev = Number(prevSchoolAvg.value)      || 0
  if (!prev) return 'нет сравнения с прошлым периодом'
  const delta = cur - prev
  if (Math.abs(delta) < 0.5) return 'без изменений по сравнению с прошлым периодом'
  const arrow = delta > 0 ? '↑' : '↓'
  return `${arrow} ${Math.abs(delta).toFixed(1)}% к прошлому периоду`
})

function formatPct(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return `${Number(v).toFixed(1)}%`
}

function scoreTone(v) {
  const n = Number(v)
  if (!n || Number.isNaN(n)) return ''
  if (n >= 75) return 'success'
  if (n >= 50) return 'warning'
  return 'danger'
}

function hasClassData(c) {
  return (Number(c?.sessions_count) || 0) > 0 || (Number(c?.avg_score) || 0) > 0
}

function classroomKey(c) {
  return c.classroom_id || c.classroom_name || Math.random()
}

function sessionsWord(n) {
  const m = Math.abs(Number(n) || 0) % 100
  const lastDigit = m % 10
  if (m >= 11 && m <= 14) return 'уроков'
  if (lastDigit === 1)    return 'урок'
  if (lastDigit >= 2 && lastDigit <= 4) return 'урока'
  return 'уроков'
}

async function load() {
  loading.value = true
  error.value   = false
  try {
    const cur = await analytics.overview({ from: from.value, to: to.value })
    classrooms.value = cur.data.classrooms || []
    summary.value    = cur.data.summary || {
      total_classrooms: 0, school_avg: 0, best_classroom: null, worst_classroom: null,
    }
    emit('loaded', cur.data)

    // Сравнение с предыдущим периодом такой же длины
    const fromMs = new Date(from.value).getTime()
    const toMs   = new Date(to.value).getTime()
    const span   = Math.max(toMs - fromMs, 24 * 60 * 60 * 1000)
    const prevFrom = new Date(fromMs - span).toISOString().slice(0, 10)
    const prevTo   = new Date(fromMs - 1   ).toISOString().slice(0, 10)
    try {
      const prev = await analytics.overview({ from: prevFrom, to: prevTo })
      prevSchoolAvg.value = Number(prev.data?.summary?.school_avg) || 0
    } catch {
      prevSchoolAvg.value = null
    }
  } catch (e) {
    console.warn('overview load failed', e)
    error.value = true
  } finally {
    loading.value = false
  }
}

const refreshTrigger = inject('analyticsRefreshTrigger', ref(0))

onMounted(load)
watch([from, to], load)
watch(refreshTrigger, () => load())
</script>

<style scoped>
.compare-tab { display:flex; flex-direction:column; gap:16px; }
.state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px; gap:12px; color:#94a3b8; text-align:center; }
.state.error { color:#fca5a5; }
.state.empty .empty-icon { font-size:40px; }
.state h3 { margin:0; font-size:16px; color:#e2e8f0; }
.state p { margin:0; font-size:13px; color:#94a3b8; }
.retry { padding:8px 16px; background:rgba(99,102,241,0.15); color:#a5b4fc; border:1px solid rgba(99,102,241,0.3); border-radius:8px; cursor:pointer; font-size:12px; font-family:inherit; }
.retry:hover { background:rgba(99,102,241,0.25); }
.spinner { width:28px; height:28px; border:3px solid rgba(255,255,255,0.08); border-top-color:#6366f1; border-radius:50%; animation:spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.kpi-row { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }

.dist-card, .chart-card {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  padding: 16px 18px;
}
.dist-header, .chart-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.dist-header h3, .chart-card-header h3 { font-size: 14px; font-weight: 600; color: #e2e8f0; margin: 0; }
.dist-header .hint, .chart-card-header .hint { font-size: 12px; color: #64748b; }

.dist-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.dist-item { display: flex; flex-direction: column; gap: 6px; }
.dist-meta {
  display: grid;
  grid-template-columns: 10px 1fr auto;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #cbd5e1;
}
.dist-dot { width: 10px; height: 10px; border-radius: 50%; }
.dist-dot.success { background: #22c55e; }
.dist-dot.warning { background: #f59e0b; }
.dist-dot.danger  { background: #ef4444; }
.dist-label { color: #e2e8f0; }
.dist-value {
  font-weight: 700;
  color: #f1f5f9;
  font-variant-numeric: tabular-nums;
}
.dist-track {
  height: 8px;
  background: rgba(255,255,255,0.06);
  border-radius: 4px;
  overflow: hidden;
}
.dist-fill { height: 100%; border-radius: 4px; transition: width 0.4s ease; }
.dist-fill.success { background: linear-gradient(90deg, #16a34a, #22c55e); }
.dist-fill.warning { background: linear-gradient(90deg, #d97706, #f59e0b); }
.dist-fill.danger  { background: linear-gradient(90deg, #dc2626, #ef4444); }
.dist-sub { font-size: 11px; color: #64748b; }

.class-bars { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.class-bar {
  display: grid;
  grid-template-columns: minmax(120px, 180px) 1fr 64px 96px;
  align-items: center;
  gap: 12px;
}
.class-bar-name {
  font-size: 13px;
  color: #e2e8f0;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.class-bar-track {
  position: relative;
  height: 18px;
  background: rgba(255,255,255,0.05);
  border-radius: 9px;
  overflow: hidden;
}
.class-bar-fill {
  height: 100%;
  border-radius: 9px;
  transition: width 0.5s ease;
}
.class-bar-fill.success { background: linear-gradient(90deg, #16a34a, #22c55e); }
.class-bar-fill.warning { background: linear-gradient(90deg, #d97706, #f59e0b); }
.class-bar-fill.danger  { background: linear-gradient(90deg, #dc2626, #ef4444); }
.class-bar-empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: #475569;
  font-style: italic;
}
.class-bar-value {
  font-size: 14px;
  font-weight: 700;
  text-align: right;
  font-variant-numeric: tabular-nums;
  color: #94a3b8;
}
.class-bar-value.success { color: #22c55e; }
.class-bar-value.warning { color: #f59e0b; }
.class-bar-value.danger  { color: #ef4444; }
.class-bar-value.muted   { color: #475569; }
.class-bar-sub {
  font-size: 11px;
  color: #64748b;
  text-align: right;
}

@media (max-width: 760px) {
  .kpi-row { grid-template-columns: 1fr; }
  .dist-grid { grid-template-columns: 1fr; }
  .class-bar {
    grid-template-columns: 1fr 56px;
    grid-template-rows: auto auto auto;
    grid-template-areas:
      "name value"
      "track track"
      "sub sub";
  }
  .class-bar-name { grid-area: name; }
  .class-bar-track { grid-area: track; }
  .class-bar-value { grid-area: value; }
  .class-bar-sub   { grid-area: sub; }
}
</style>
