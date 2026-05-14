<template>
  <div class="live-analytics" :class="`level-${level}`">
    <!-- ── Header ────────────────────────────────────────── -->
    <div class="la-header">
      <div class="la-left">
        <h3 class="la-title">
          Аналитика
          <span class="la-classroom">• {{ classroomName }}</span>
          <span v-if="subject" class="la-subject">• {{ subject }}</span>
        </h3>
        <div class="la-meta">
          <span class="meta-live"><span class="live-dot"></span>Live</span>
          <span v-if="teacherName" class="meta-item">Учитель: {{ teacherName }}</span>
          <span class="meta-item">{{ durationLabel }}</span>
        </div>
      </div>
      <div class="la-right">
        <div class="la-avg" :class="`level-${level}`">
          Средняя: <span class="avg-num">{{ avgDisplay }}%</span>
        </div>
      </div>
    </div>

    <!-- ── Chart ─────────────────────────────────────────── -->
    <div class="chart-section">
      <div class="chart-title">График вовлечённости по минутам</div>
      <div class="chart-box">
        <Line
          v-if="hasChartData"
          :data="chartData"
          :options="chartOptions"
        />
        <div v-else class="chart-empty">
          Ждём первую минуту данных...
        </div>
      </div>
    </div>

    <!-- ── Students table ────────────────────────────────── -->
    <div class="students-section">
      <div class="students-title">
        <span>Студенты <span class="muted">(обновляется каждые 5 сек)</span></span>
        <span class="students-count">{{ studentList.length }}</span>
      </div>

      <div v-if="studentList.length === 0" class="students-empty">
        Ожидаем данные с камеры...
      </div>

      <div v-else class="students-table-wrap">
        <table class="students-table">
          <thead>
            <tr>
              <th class="col-status">Статус</th>
              <th class="col-name">Имя</th>
              <th class="col-engagement">Вовлечённость</th>
              <th class="col-emotion">Эмоция</th>
              <th class="col-gaze">Взгляд</th>
              <th class="col-pose">Поза</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(s, i) in studentList"
              :key="s.student_id || i"
              class="student-row"
              :class="[`level-${studentLevel(s)}`, { absent: !s.face_detected }]"
            >
              <td class="col-status">
                <span class="dot" :class="`level-${studentLevel(s)}`"></span>
              </td>
              <td class="col-name">
                <span class="name-text">{{ displayName(s, i) }}</span>
              </td>
              <td class="col-engagement">
                <div class="engagement-wrap">
                  <div class="bar">
                    <div
                      class="bar-fill"
                      :class="`level-${studentLevel(s)}`"
                      :style="{ width: barWidth(s) }"
                    ></div>
                  </div>
                  <span class="score" :class="`level-${studentLevel(s)}`">
                    <template v-if="s.face_detected">{{ Math.round(s.score || 0) }}%</template>
                    <template v-else>—</template>
                  </span>
                </div>
              </td>
              <td class="col-emotion">
                <span class="emotion" :title="emotionLabel(s)">{{ emotionEmoji(s) }}</span>
              </td>
              <td class="col-gaze">
                <span class="gaze">{{ gazeText(s) }}</span>
              </td>
              <td class="col-pose">
                <span class="pose">{{ poseText(s) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { sessions as sessionsApi } from '@/api'
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
  session:       { type: Object, required: true },
  classAvg:      { type: Number, default: 0 },
  studentScores: { type: Object, default: () => ({}) },
})

// ── Header ───────────────────────────────────────────────────
const classroomName = computed(
  () => props.session.classroom_name || props.session.classroom?.name || 'Класс'
)
const subject     = computed(() => props.session.subject || '')
const teacherName = computed(
  () => props.session.teacher_name || props.session.teacher || ''
)

const avgDisplay = computed(() => {
  const v = Number(props.classAvg) || 0
  if (!v || Number.isNaN(v)) return 0
  return Math.round(v)
})
const level = computed(() => {
  const v = avgDisplay.value
  if (!v) return 'unknown'
  if (v > 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
})

// ── Duration ─────────────────────────────────────────────────
const startedAt = computed(() =>
  props.session.started_at ? new Date(props.session.started_at) : null
)
const now = ref(Date.now())
let nowTimer = null

const durationLabel = computed(() => {
  if (!startedAt.value) return ''
  const mins = Math.max(0, Math.floor((now.value - startedAt.value.getTime()) / 60000))
  if (mins < 1)  return 'только что'
  if (mins < 60) return `${mins} мин`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return `${h} ч ${m} мин`
})

// ── Baseline students (names) ────────────────────────────────
const baselineStudents = ref([])

async function loadBaselineStudents() {
  if (!props.session?.id) return
  try {
    const { data } = await sessionsApi.students(props.session.id)
    baselineStudents.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[LiveAnalytics] students load failed', e)
  }
}

const studentList = computed(() => {
  const live = props.studentScores || {}
  const liveIds = Object.keys(live)
  const baselineById = Object.fromEntries(
    baselineStudents.value.map(s => [s.student_id, s])
  )

  if (liveIds.length === 0) {
    return baselineStudents.value
      .map(s => ({
        student_id:    s.student_id,
        name:          s.name,
        score:         s.avg_score || 0,
        face_detected: false,
        emotion:       null,
        gaze_on_board: null,
        head_on_board: null,
      }))
      .sort((a, b) => (a.score || 0) - (b.score || 0))
  }

  return liveIds
    .map(id => {
      const liveRow = live[id] || {}
      const base    = baselineById[id] || {}
      return {
        student_id:    id,
        name:          base.name || liveRow.name || null,
        score:         Number(liveRow.score) || 0,
        emotion:       liveRow.emotion ?? null,
        gaze_on_board: liveRow.gaze_on_board ?? null,
        head_on_board: liveRow.head_on_board ?? null,
        face_detected: liveRow.face_detected !== false,
      }
    })
    .sort((a, b) => (a.score || 0) - (b.score || 0))
})

// ── Per-student helpers ──────────────────────────────────────
function studentLevel(s) {
  if (!s.face_detected) return 'unknown'
  const v = Number(s.score) || 0
  if (v >= 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
}

function displayName(s, idx) {
  if (s.name) return s.name
  if (s.student_id) return `Лицо ${String(s.student_id).slice(-4)}`
  return `Лицо ${idx + 1}`
}

function barWidth(s) {
  if (!s.face_detected) return '0%'
  const v = Math.max(0, Math.min(100, Number(s.score) || 0))
  return `${v}%`
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
function emotionEmoji(s) {
  if (!s.face_detected) return '—'
  const e = (s.emotion || '').toLowerCase()
  return EMOTION_LABEL[e] || s.emotion || 'Нейтрально'
}
function emotionLabel(s) {
  if (!s.face_detected) return 'Лицо не найдено'
  const e = (s.emotion || '').toLowerCase()
  return EMOTION_LABEL[e] || (s.emotion || 'Нейтрально')
}

function gazeText(s) {
  if (!s.face_detected) return '—'
  if (s.gaze_on_board === true)  return '👁 на доске'
  if (s.gaze_on_board === false) return '➜ в сторону'
  return '—'
}

function poseText(s) {
  if (!s.face_detected) return '—'
  if (s.head_on_board === true)  return '✓ прямо'
  if (s.head_on_board === false) return '↗ отвлёкся'
  // No head pose data in current WS payload — use gaze as proxy.
  if (s.gaze_on_board === true)  return '✓ прямо'
  if (s.gaze_on_board === false) return '↗ отвлёкся'
  return '—'
}

// ── Timeline / chart ─────────────────────────────────────────
const timeline = ref([])
let timelineTimer = null
const lastPushedMinute = ref(null)

async function loadTimeline() {
  if (!props.session?.id) return
  try {
    const { data } = await sessionsApi.timeline(props.session.id)
    timeline.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[LiveAnalytics] timeline load failed', e)
  }
}

// Append a new point every minute from incoming class_avg
watch(
  () => props.classAvg,
  (v) => {
    if (!v || v <= 0) return
    const currentMinute = Math.floor(Date.now() / 60_000)
    if (lastPushedMinute.value !== currentMinute) {
      lastPushedMinute.value = currentMinute
      timeline.value = [...timeline.value, {
        minute:    timeline.value.length,
        avg_score: Number(v),
      }]
    }
  }
)

const hasChartData = computed(() => timeline.value.length > 0)

const chartLineColor = computed(() => {
  if (!timeline.value.length) return '#6366f1'
  const last = Number(timeline.value[timeline.value.length - 1]?.avg_score) || 0
  if (last > 70)  return '#22c55e'
  if (last >= 50) return '#f59e0b'
  return '#ef4444'
})

const chartFillColor = computed(() => {
  const last = Number(timeline.value[timeline.value.length - 1]?.avg_score) || 0
  if (last > 70)  return 'rgba(34,197,94,0.18)'
  if (last >= 50) return 'rgba(245,158,11,0.18)'
  return 'rgba(239,68,68,0.18)'
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
    x: {
      display: true,
      grid:    { color: 'rgba(255,255,255,0.04)' },
      ticks:   { color: '#64748b', font: { size: 10 } },
    },
    y: {
      display: true,
      min:     0,
      max:     100,
      grid:    { color: 'rgba(255,255,255,0.04)' },
      ticks:   { color: '#64748b', font: { size: 10 }, stepSize: 25 },
    },
  },
  interaction: { mode: 'nearest', intersect: false },
}))

// ── Lifecycle ────────────────────────────────────────────────
onMounted(() => {
  loadBaselineStudents()
  loadTimeline()
  // Refresh timeline from server every 30s — minute-level aggregates appear with lag.
  timelineTimer = setInterval(loadTimeline, 30_000)
  nowTimer = setInterval(() => { now.value = Date.now() }, 30_000)
})

onBeforeUnmount(() => {
  if (timelineTimer) clearInterval(timelineTimer)
  if (nowTimer)      clearInterval(nowTimer)
  timelineTimer = null
  nowTimer = null
})

watch(() => props.session?.id, (id, prev) => {
  if (id && id !== prev) {
    loadBaselineStudents()
    loadTimeline()
    lastPushedMinute.value = null
  }
})
</script>

<style scoped>
.live-analytics {
  background: #1e293b;
  border: 2px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  transition: border-color 0.3s ease;
}
.live-analytics.level-high    { border-color: #22c55e; }
.live-analytics.level-medium  { border-color: #f59e0b; }
.live-analytics.level-low     { border-color: #ef4444; }
.live-analytics.level-unknown { border-color: rgba(255,255,255,0.12); }

/* ── Header ─────────────────────────────────────────────── */
.la-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.la-left { min-width: 0; flex: 1; }
.la-title {
  font-size: 18px;
  font-weight: 600;
  color: #f1f5f9;
  margin: 0 0 6px;
}
.la-classroom { color: #cbd5e1; font-weight: 500; }
.la-subject   { color: #94a3b8; font-weight: 400; }

.la-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 12.5px;
  color: #94a3b8;
}
.meta-live {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: #ef4444;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.live-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ef4444;
  animation: pulse 1.4s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%      { opacity: 0.45; transform: scale(0.85); }
}

.la-right { flex-shrink: 0; display: flex; align-items: center; }
.la-avg {
  font-size: 14px;
  font-weight: 500;
  color: #94a3b8;
  transition: color 0.3s ease;
}
.la-avg .avg-num {
  font-size: 20px;
  font-weight: 700;
  margin-left: 4px;
  font-variant-numeric: tabular-nums;
}
.la-avg.level-high    { color: #22c55e; }
.la-avg.level-medium  { color: #f59e0b; }
.la-avg.level-low     { color: #ef4444; }
.la-avg.level-unknown { color: #94a3b8; }

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
.chart-box {
  position: relative;
  height: 180px;
}
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
.students-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.students-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  color: #cbd5e1;
}
.students-title .muted { color: #64748b; font-weight: 400; }
.students-count {
  background: rgba(255,255,255,0.06);
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  color: #94a3b8;
  font-variant-numeric: tabular-nums;
}

.students-empty {
  padding: 24px 4px;
  text-align: center;
  font-size: 13px;
  color: #475569;
  font-style: italic;
}

.students-table-wrap {
  max-height: 400px;
  overflow-y: auto;
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
}
.students-table-wrap::-webkit-scrollbar { width: 8px; }
.students-table-wrap::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,0.1);
  border-radius: 4px;
}

.students-table {
  width: 100%;
  border-collapse: collapse;
}
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
.students-table .col-emotion    { width: 70px; text-align: center; }
.students-table .col-gaze       { width: 140px; }
.students-table .col-pose       { width: 130px; }

.student-row td {
  padding: 10px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
  color: #e2e8f0;
  font-size: 13px;
  vertical-align: middle;
  transition: background 0.2s ease;
}
.student-row:hover td  { background: rgba(255,255,255,0.04); }
.student-row.absent td { color: #64748b; }
.student-row:last-child td { border-bottom: none; }

.dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #475569;
  transition: background 0.3s ease, box-shadow 0.3s ease;
}
.dot.level-high   { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,0.6); }
.dot.level-medium { background: #f59e0b; box-shadow: 0 0 8px rgba(245,158,11,0.6); }
.dot.level-low    { background: #ef4444; box-shadow: 0 0 8px rgba(239,68,68,0.6); }
.dot.level-unknown { background: #475569; }

.name-text { font-weight: 500; color: #f1f5f9; }

.engagement-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
}
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
  transition: width 0.3s ease, background-color 0.3s ease;
}
.bar-fill.level-high    { background: #22c55e; }
.bar-fill.level-medium  { background: #f59e0b; }
.bar-fill.level-low     { background: #ef4444; }
.bar-fill.level-unknown { background: #475569; }

.score {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 13px;
  min-width: 44px;
  text-align: right;
  color: #94a3b8;
  transition: color 0.3s ease;
}
.score.level-high   { color: #22c55e; }
.score.level-medium { color: #f59e0b; }
.score.level-low    { color: #ef4444; }

.col-emotion { text-align: center; }
.emotion { font-size: 12px; line-height: 1; color: #cbd5e1; font-weight: 500; }

.gaze, .pose {
  font-size: 12.5px;
  color: #cbd5e1;
  white-space: nowrap;
}

@media (max-width: 900px) {
  .la-header { flex-direction: column; align-items: stretch; }
  .students-table .col-engagement { width: 180px; }
  .students-table .col-gaze, .students-table .col-pose { width: 110px; }
}
</style>
