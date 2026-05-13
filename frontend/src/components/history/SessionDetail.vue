<template>
  <div class="session-detail" :class="`level-${level}`">
    <!-- ── Header ────────────────────────────────────────── -->
    <div class="sd-header">
      <div class="sd-left">
        <h3 class="sd-title">
          {{ classroomName }}
          <span v-if="subject" class="sd-subject">• {{ subject }}</span>
        </h3>
        <div class="sd-meta">
          <span class="meta-item">{{ dateTimeLabel }}</span>
          <span class="meta-item">Длительность: {{ durationLabel }}</span>
          <span class="meta-item">Студентов: {{ studentsCount }}</span>
          <span class="meta-item sd-avg" :class="`level-${level}`">
            Средняя: <span class="avg-num">{{ avgDisplay }}%</span>
          </span>
        </div>
      </div>
      <div class="sd-right">
        <button class="back-btn" @click="$emit('back')">
          <span class="arrow">←</span> Назад
        </button>
      </div>
    </div>

    <!-- ── Chart ─────────────────────────────────────────── -->
    <div class="chart-section">
      <div class="chart-title">График вовлечённости за урок (по минутам)</div>
      <div class="chart-box">
        <Line
          v-if="hasChartData"
          :data="chartData"
          :options="chartOptions"
        />
        <div v-else-if="chartLoading" class="chart-empty">Загрузка...</div>
        <div v-else class="chart-empty">Нет данных за этот урок</div>
      </div>
    </div>

    <!-- ── Students table ────────────────────────────────── -->
    <div class="students-section">
      <div class="students-title">
        <span>Студенты</span>
        <span class="students-count">{{ students.length }}</span>
      </div>

      <div v-if="studentsLoading" class="students-loading">
        <div v-for="i in 4" :key="i" class="skel-row"></div>
      </div>

      <div v-else-if="students.length === 0" class="students-empty">
        Нет данных о студентах за этот урок
      </div>

      <div v-else class="students-table-wrap">
        <table class="students-table">
          <thead>
            <tr>
              <th class="col-status">Статус</th>
              <th class="col-name">Имя</th>
              <th class="col-engagement">Средняя</th>
              <th class="col-emotion">Эмоция</th>
              <th class="col-gaze">Взгляд</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in sortedStudents"
              :key="row.student_id"
              class="student-row"
              :class="`level-${rowLevel(row)}`"
            >
              <td class="col-status">
                <span class="dot" :class="`level-${rowLevel(row)}`"></span>
              </td>
              <td class="col-name">
                <span class="name-text">{{ row.name || displayId(row.student_id) }}</span>
                <span v-if="row.code" class="name-code">{{ row.code }}</span>
              </td>
              <td class="col-engagement">
                <div class="engagement-wrap">
                  <div class="bar">
                    <div
                      class="bar-fill"
                      :class="`level-${rowLevel(row)}`"
                      :style="{ width: barWidth(row.avg_score) }"
                    ></div>
                  </div>
                  <span class="score" :class="`level-${rowLevel(row)}`">
                    {{ formatPct(row.avg_score) }}
                  </span>
                </div>
              </td>
              <td class="col-emotion">
                <span class="emotion" :title="emotionLabel(row.dominant_emotion)">
                  {{ emotionEmoji(row.dominant_emotion) }}
                </span>
              </td>
              <td class="col-gaze">
                <span class="gaze-pct" :class="`level-${pctLevel(row.gaze_on_board_pct)}`">
                  {{ formatPct(row.gaze_on_board_pct) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { sessions as sessionsApi, analytics } from '@/api'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Filler,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler)

const props = defineProps({
  session: { type: Object, required: true },
})
defineEmits(['back'])

// ── Header derived data ──────────────────────────────────────
const classroomName = computed(
  () => props.session.classroom_name || props.session.classroom?.name || 'Класс'
)
const subject = computed(() => props.session.subject || '')

const avgValue = computed(() => {
  const v = Number(props.session.avg_engagement_score)
  return Number.isFinite(v) ? v : 0
})
const avgDisplay = computed(() => avgValue.value ? Math.round(avgValue.value) : '—')
const level = computed(() => {
  const v = avgValue.value
  if (!v) return 'unknown'
  if (v > 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
})

const studentsCount = computed(() => Number(props.session.students_count) || 0)

const durationLabel = computed(() => {
  const m = Number(props.session.duration_minutes)
  if (!m || !Number.isFinite(m)) {
    if (props.session.started_at && props.session.ended_at) {
      const mins = Math.max(0, Math.round(
        (new Date(props.session.ended_at) - new Date(props.session.started_at)) / 60000
      ))
      return formatMinutes(mins)
    }
    return '—'
  }
  return formatMinutes(Math.round(m))
})

function formatMinutes(mins) {
  if (mins < 60) return `${mins} мин`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return `${h} ч ${m} мин`
}

const dateTimeLabel = computed(() => {
  const startedAt = props.session.started_at ? new Date(props.session.started_at) : null
  const endedAt   = props.session.ended_at   ? new Date(props.session.ended_at)   : null
  if (!startedAt) return '—'
  const date = startedAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
  const start = startedAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  if (endedAt) {
    const end = endedAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    return `${date}, ${start} – ${end}`
  }
  return `${date}, ${start}`
})

// ── Timeline / chart ─────────────────────────────────────────
const timeline     = ref([])
const chartLoading = ref(false)

async function loadTimeline() {
  if (!props.session?.id) return
  chartLoading.value = true
  try {
    const { data } = await sessionsApi.timeline(props.session.id)
    timeline.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[SessionDetail] timeline load failed', e)
    timeline.value = []
  } finally {
    chartLoading.value = false
  }
}

const hasChartData = computed(() => timeline.value.length > 0)

const chartLineColor = computed(() => {
  const v = avgValue.value
  if (v > 70)  return '#22c55e'
  if (v >= 50) return '#f59e0b'
  if (v > 0)   return '#ef4444'
  return '#6366f1'
})

const chartFillColor = computed(() => {
  const v = avgValue.value
  if (v > 70)  return 'rgba(34,197,94,0.18)'
  if (v >= 50) return 'rgba(245,158,11,0.18)'
  if (v > 0)   return 'rgba(239,68,68,0.18)'
  return 'rgba(99,102,241,0.18)'
})

const chartData = computed(() => ({
  labels: timeline.value.map(p => `${p.minute ?? 0} мин`),
  datasets: [{
    label:           'Средняя вовлечённость',
    data:            timeline.value.map(p => Number(p.avg_score) || 0),
    borderColor:     chartLineColor.value,
    backgroundColor: chartFillColor.value,
    borderWidth:     2,
    pointRadius:     2,
    pointHoverRadius: 5,
    tension:         0.35,
    fill:            true,
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  animation: { duration: 300, easing: 'easeOutQuart' },
  plugins: {
    legend:  { display: false },
    tooltip: {
      enabled: true,
      displayColors: false,
      callbacks: {
        title: items => items[0]?.label || '',
        label: ctx   => `${Number(ctx.parsed.y).toFixed(1)}%`,
      },
    },
  },
  scales: {
    x: { display: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#64748b', font: { size: 10 } } },
    y: { display: true, min: 0, max: 100, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#64748b', font: { size: 10 }, stepSize: 25 } },
  },
  interaction: { mode: 'nearest', intersect: false },
}))

// ── Students ─────────────────────────────────────────────────
const sessionStudents  = ref([]) // from /sessions/{id}/students
const analyticsStudents = ref([]) // from /analytics/students for emotion / gaze
const studentsLoading  = ref(false)

async function loadSessionStudents() {
  if (!props.session?.id) return
  try {
    const { data } = await sessionsApi.students(props.session.id)
    sessionStudents.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[SessionDetail] students load failed', e)
    sessionStudents.value = []
  }
}

async function loadAnalyticsBreakdown() {
  if (!props.session?.started_at || !props.session?.ended_at || !props.session?.classroom_id) {
    analyticsStudents.value = []
    return
  }
  try {
    const params = {
      from: new Date(props.session.started_at).toISOString().slice(0, 10),
      to:   new Date(props.session.ended_at).toISOString().slice(0, 10),
      classroom_id: props.session.classroom_id,
    }
    const { data } = await analytics.studentsList(params)
    analyticsStudents.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[SessionDetail] analytics breakdown failed', e)
    analyticsStudents.value = []
  }
}

async function loadStudents() {
  studentsLoading.value = true
  try {
    await Promise.all([loadSessionStudents(), loadAnalyticsBreakdown()])
  } finally {
    studentsLoading.value = false
  }
}

// Merge per-session avg + per-period emotion/gaze.
const students = computed(() => {
  const byId = {}
  for (const s of analyticsStudents.value) {
    byId[s.student_id] = s
  }
  return sessionStudents.value.map(s => {
    const extra = byId[s.student_id] || {}
    return {
      student_id:         s.student_id,
      name:               s.name,
      code:               s.code,
      avg_score:          s.avg_score,
      dominant_emotion:   extra.dominant_emotion ?? null,
      gaze_on_board_pct:  extra.gaze_on_board_pct ?? null,
    }
  })
})

const sortedStudents = computed(() =>
  [...students.value].sort((a, b) => (a.avg_score ?? -1) - (b.avg_score ?? -1))
)

function rowLevel(row) {
  const v = Number(row.avg_score) || 0
  if (!v) return 'unknown'
  if (v >= 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
}
function pctLevel(v) {
  const n = Number(v)
  if (!Number.isFinite(n) || n === 0) return 'unknown'
  if (n > 70)  return 'high'
  if (n >= 50) return 'medium'
  return 'low'
}
function barWidth(v) {
  const n = Math.max(0, Math.min(100, Number(v) || 0))
  return `${n}%`
}
function formatPct(v) {
  const n = Number(v)
  if (!Number.isFinite(n) || n === null) return '—'
  return `${Math.round(n)}%`
}
function displayId(id) {
  if (!id) return 'Лицо'
  return `Лицо ${String(id).slice(-4)}`
}

const EMOTION_EMOJI = {
  neutral: '😐', calm: '😐', happy: '😊', joy: '😊', positive: '😊',
  sad: '😢', sadness: '😢', angry: '😠', anger: '😠',
  surprised: '😮', surprise: '😮',
  fear: '😨', fearful: '😨',
  disgust: '🤢', disgusted: '🤢', confused: '🤔',
}
const EMOTION_LABEL = {
  neutral: 'Нейтрально', calm: 'Спокойствие',
  happy: 'Радость', joy: 'Радость', positive: 'Позитив',
  sad: 'Грусть', sadness: 'Грусть',
  angry: 'Злость', anger: 'Злость',
  surprised: 'Удивление', surprise: 'Удивление',
  fear: 'Тревога', fearful: 'Тревога',
  disgust: 'Отвращение', disgusted: 'Отвращение',
  confused: 'Смущение',
}
function emotionEmoji(e) {
  if (!e) return '—'
  return EMOTION_EMOJI[String(e).toLowerCase()] || '😐'
}
function emotionLabel(e) {
  if (!e) return ''
  return EMOTION_LABEL[String(e).toLowerCase()] || e
}

// ── Lifecycle ────────────────────────────────────────────────
onMounted(() => {
  loadTimeline()
  loadStudents()
})

watch(() => props.session?.id, (id, prev) => {
  if (id && id !== prev) {
    loadTimeline()
    loadStudents()
  }
})
</script>

<style scoped>
.session-detail {
  background: #1e293b;
  border: 2px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.session-detail.level-high   { border-color: #22c55e; }
.session-detail.level-medium { border-color: #f59e0b; }
.session-detail.level-low    { border-color: #ef4444; }
.session-detail.level-unknown { border-color: rgba(255,255,255,0.12); }

/* ── Header ─────────────────────────────────────────────── */
.sd-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.sd-left  { min-width: 0; flex: 1; }
.sd-right { flex-shrink: 0; }
.sd-title {
  font-size: 18px;
  font-weight: 600;
  color: #f1f5f9;
  margin: 0 0 6px;
}
.sd-subject { color: #94a3b8; font-weight: 400; }

.sd-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 12.5px;
  color: #94a3b8;
}
.sd-avg { font-weight: 600; }
.sd-avg .avg-num {
  font-size: 16px;
  font-variant-numeric: tabular-nums;
  margin-left: 4px;
}
.sd-avg.level-high   { color: #22c55e; }
.sd-avg.level-medium { color: #f59e0b; }
.sd-avg.level-low    { color: #ef4444; }

.back-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: #cbd5e1;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.back-btn:hover { background: rgba(255,255,255,0.1); color: #f1f5f9; }
.back-btn .arrow { font-size: 16px; line-height: 1; }

/* ── Chart ──────────────────────────────────────────────── */
.chart-section {
  background: rgba(255,255,255,0.025);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
  padding: 12px 16px 14px;
}
.chart-title {
  font-size: 11px;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 8px;
}
.chart-box { position: relative; height: 180px; }
.chart-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #475569;
  font-size: 13px;
  font-style: italic;
}

/* ── Students ───────────────────────────────────────────── */
.students-section { display: flex; flex-direction: column; gap: 8px; }
.students-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  color: #cbd5e1;
}
.students-count {
  background: rgba(255,255,255,0.06);
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  color: #94a3b8;
  font-variant-numeric: tabular-nums;
}

.students-loading { display: flex; flex-direction: column; gap: 6px; padding: 4px 0; }
.skel-row {
  height: 36px;
  background: linear-gradient(90deg, rgba(255,255,255,0.04), rgba(255,255,255,0.07), rgba(255,255,255,0.04));
  background-size: 200% 100%;
  border-radius: 8px;
  animation: shimmer 1.4s ease-in-out infinite;
}
@keyframes shimmer {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

.students-empty {
  padding: 24px 4px;
  text-align: center;
  font-size: 13px;
  color: #475569;
  font-style: italic;
}

.students-table-wrap {
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
  max-height: 460px;
  overflow-y: auto;
}
.students-table-wrap::-webkit-scrollbar { width: 8px; }
.students-table-wrap::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }

.students-table { width: 100%; border-collapse: collapse; }
.students-table thead th {
  position: sticky;
  top: 0;
  background: #1a2436;
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 10px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
  z-index: 1;
}
.students-table .col-status     { width: 64px; }
.students-table .col-engagement { width: 240px; }
.students-table .col-emotion    { width: 80px; text-align: center; }
.students-table .col-gaze       { width: 110px; }

.student-row td {
  padding: 10px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
  color: #e2e8f0;
  font-size: 13px;
  vertical-align: middle;
  transition: background 0.2s ease;
}
.student-row:hover td { background: rgba(255,255,255,0.04); }
.student-row:last-child td { border-bottom: none; }

.dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #475569;
}
.dot.level-high   { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,0.55); }
.dot.level-medium { background: #f59e0b; box-shadow: 0 0 8px rgba(245,158,11,0.55); }
.dot.level-low    { background: #ef4444; box-shadow: 0 0 8px rgba(239,68,68,0.55); }
.dot.level-unknown { background: #475569; }

.name-text { font-weight: 500; color: #f1f5f9; }
.name-code {
  margin-left: 6px;
  font-size: 11px;
  color: #64748b;
  background: rgba(255,255,255,0.05);
  padding: 1px 6px;
  border-radius: 999px;
}

.engagement-wrap { display: flex; align-items: center; gap: 10px; }
.bar {
  flex: 1;
  height: 8px;
  background: rgba(255,255,255,0.1);
  border-radius: 999px;
  overflow: hidden;
  min-width: 80px;
}
.bar-fill {
  height: 100%;
  width: 0%;
  background: #475569;
  border-radius: 999px;
  transition: width 0.4s ease;
}
.bar-fill.level-high    { background: #22c55e; }
.bar-fill.level-medium  { background: #f59e0b; }
.bar-fill.level-low     { background: #ef4444; }
.bar-fill.level-unknown { background: #475569; }

.score, .gaze-pct {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 13px;
  min-width: 44px;
  text-align: right;
  color: #94a3b8;
}
.score.level-high,    .gaze-pct.level-high   { color: #22c55e; }
.score.level-medium,  .gaze-pct.level-medium { color: #f59e0b; }
.score.level-low,     .gaze-pct.level-low    { color: #ef4444; }

.col-emotion { text-align: center; }
.emotion { font-size: 18px; line-height: 1; }
.col-gaze   { text-align: right; }
</style>
