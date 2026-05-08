<template>
  <div class="analytics-view">

    <!-- Нет активных уроков -->
    <div v-if="!sessions.length" class="empty-state">
      <div class="empty-icon">📊</div>
      <h3>Аналитика появится во время урока</h3>
      <p>Запусти урок на вкладке «Обзор» — здесь будут realtime-графики до самого окончания.</p>
    </div>

    <template v-else>
      <!-- Селектор урока + KPI -->
      <div class="header-row">
        <div class="session-tabs">
          <button
            v-for="s in sessions"
            :key="s.id"
            class="tab"
            :class="{ active: selectedId === s.id }"
            @click="selectedId = s.id"
          >
            <span class="tab-name">{{ s.classroom_name || 'Класс' }}</span>
            <span class="tab-sub">{{ s.subject || 'Урок' }}</span>
          </button>
        </div>
        <div class="live-badge"><span class="live-dot"></span>Live</div>
      </div>

      <div v-if="selected" class="kpi-row">
        <div class="kpi">
          <div class="kpi-value">{{ presentCount }}<span class="kpi-sub">/ {{ rosterCount }}</span></div>
          <div class="kpi-label">Студентов в кадре</div>
        </div>
        <div class="kpi">
          <div class="kpi-value" :class="avgClass(liveAvg)">{{ liveAvg }}%</div>
          <div class="kpi-label">Средняя вовлечённость</div>
        </div>
        <div class="kpi">
          <div class="kpi-value">{{ durationLabel }}</div>
          <div class="kpi-label">Длится</div>
        </div>
        <div class="kpi">
          <div class="kpi-value">{{ snapshotsCount }}</div>
          <div class="kpi-label">Точек измерений</div>
        </div>
      </div>

      <!-- Таймлайн вовлечённости -->
      <section v-if="selected" class="card">
        <div class="card-head">
          <h3>Вовлечённость в реальном времени</h3>
          <span class="card-sub">обновляется каждые 5 секунд</span>
        </div>
        <div class="chart-wrap chart-wrap--lg">
          <Line
            v-if="timelineData.labels.length"
            :data="timelineData"
            :options="timelineOptions"
          />
          <div v-else class="chart-empty">Ждём первые кадры с камеры…</div>
        </div>
      </section>

      <!-- Распределение по уровням + эмоции -->
      <div v-if="selected" class="grid-2">
        <section class="card">
          <div class="card-head">
            <h3>Уровни вовлечённости</h3>
            <span class="card-sub">сейчас в кадре</span>
          </div>
          <div class="chart-wrap">
            <Bar
              v-if="presentCount > 0"
              :data="distributionData"
              :options="distributionOptions"
            />
            <div v-else class="chart-empty">Никого не видно в кадре</div>
          </div>
        </section>

        <section class="card">
          <div class="card-head">
            <h3>Эмоции класса</h3>
            <span class="card-sub">по последнему снимку</span>
          </div>
          <div class="chart-wrap">
            <Doughnut
              v-if="hasEmotions"
              :data="emotionData"
              :options="emotionOptions"
            />
            <div v-else class="chart-empty">Эмоции появятся, как только лица будут распознаны</div>
          </div>
        </section>
      </div>

      <!-- Список студентов -->
      <section v-if="selected" class="card">
        <div class="card-head">
          <h3>Студенты в кадре</h3>
          <span class="card-sub">{{ presentList.length }} обнаружено</span>
        </div>
        <div v-if="!presentList.length" class="chart-empty">Никто пока не виден на камере</div>
        <ul v-else class="student-list">
          <li
            v-for="s in presentList"
            :key="s.student_id"
            class="student-row"
            :class="levelClass(s.score)"
          >
            <span class="student-id">#{{ shortId(s.student_id) }}</span>
            <div class="student-bar"><span :style="{ width: (s.score || 0) + '%' }"></span></div>
            <span class="student-score">{{ Math.round(s.score || 0) }}%</span>
            <span class="student-emotion">{{ emotionLabel(s.emotion) }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch, onMounted } from 'vue'
import {
  Chart as ChartJS,
  CategoryScale, LinearScale, PointElement, LineElement,
  BarElement, ArcElement, Title, Tooltip, Legend, Filler,
} from 'chart.js'
import { Line, Bar, Doughnut } from 'vue-chartjs'
import { useEngagementStore } from '@/stores/engagement'

ChartJS.register(
  CategoryScale, LinearScale, PointElement, LineElement,
  BarElement, ArcElement, Title, Tooltip, Legend, Filler,
)

const store = useEngagementStore()
const selectedId = ref(null)

const sessions = computed(() =>
  (store.activeSessions || []).filter(s => s.status === 'active')
)

const selected = computed(() =>
  sessions.value.find(s => s.id === selectedId.value) || sessions.value[0] || null
)

watch(sessions, (list) => {
  if (!selectedId.value || !list.find(s => s.id === selectedId.value)) {
    selectedId.value = list[0]?.id || null
  }
}, { immediate: true })

watch(selected, (s) => {
  if (s?.id) store.subscribeToSession(s.id)
})

onMounted(() => {
  if (selected.value?.id) store.subscribeToSession(selected.value.id)
})

// ── Вычисляемые метрики ────────────────────────────────────────────
const sessionId = computed(() => selected.value?.id)

const presentCount = computed(() => {
  if (!sessionId.value) return 0
  return store.studentsPresent[sessionId.value] ?? selected.value?.students_present ?? 0
})
const rosterCount = computed(() => selected.value?.students_count || 0)

const liveAvg = computed(() => {
  if (!sessionId.value) return 0
  const v = store.classAverages[sessionId.value] ?? selected.value?.live_avg_score
  return typeof v === 'number' ? Math.round(v) : 0
})

const distribution = computed(() => {
  if (!sessionId.value) return { high: 0, medium: 0, low: 0 }
  return store.distributions[sessionId.value] || { high: 0, medium: 0, low: 0 }
})

const emotions = computed(() => {
  if (!sessionId.value) return {}
  return store.emotions[sessionId.value] || {}
})

const hasEmotions = computed(() => Object.values(emotions.value).some(v => v > 0))

const timeline = computed(() => {
  if (!sessionId.value) return []
  return store.timelines[sessionId.value] || []
})

const snapshotsCount = computed(() => timeline.value.length)

const presentList = computed(() => {
  if (!sessionId.value) return []
  const scores = store.studentScores[sessionId.value] || {}
  return Object.values(scores)
    .filter(s => s.face_detected !== false)
    .sort((a, b) => (b.score || 0) - (a.score || 0))
})

const durationLabel = computed(() => {
  if (!selected.value?.started_at) return '—'
  const mins = Math.floor((Date.now() - new Date(selected.value.started_at)) / 60000)
  if (mins < 1) return '< 1 мин'
  if (mins < 60) return `${mins} мин`
  return `${Math.floor(mins / 60)} ч ${mins % 60} мин`
})

// ── Графики ─────────────────────────────────────────────────────────
const timelineData = computed(() => ({
  labels: timeline.value.map(p => formatTime(p.t)),
  datasets: [
    {
      label: 'Класс, %',
      data: timeline.value.map(p => Math.round(p.avg || 0)),
      borderColor: '#6366f1',
      backgroundColor: 'rgba(99,102,241,0.18)',
      tension: 0.35,
      fill: true,
      pointRadius: 0,
      pointHoverRadius: 4,
      borderWidth: 2,
    },
    {
      label: 'В кадре',
      data: timeline.value.map(p => p.present || 0),
      borderColor: '#22c55e',
      backgroundColor: 'transparent',
      tension: 0.3,
      fill: false,
      pointRadius: 0,
      pointHoverRadius: 4,
      borderWidth: 1.5,
      borderDash: [4, 4],
      yAxisID: 'y1',
    },
  ],
}))

const timelineOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { labels: { color: '#94a3b8', font: { size: 11 } } },
    tooltip: { backgroundColor: '#0f172a', borderColor: '#1e293b', borderWidth: 1 },
  },
  scales: {
    x: {
      ticks: { color: '#475569', font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
      grid: { color: 'rgba(148,163,184,0.06)' },
    },
    y: {
      min: 0, max: 100,
      ticks: { color: '#475569', font: { size: 10 }, callback: (v) => v + '%' },
      grid: { color: 'rgba(148,163,184,0.06)' },
    },
    y1: {
      position: 'right',
      min: 0, suggestedMax: Math.max(rosterCount.value || 5, 5),
      ticks: { color: '#475569', font: { size: 10 }, stepSize: 1, precision: 0 },
      grid: { drawOnChartArea: false },
    },
  },
}))

const distributionData = computed(() => ({
  labels: ['Высокая 75–100%', 'Средняя 50–74%', 'Низкая 0–49%'],
  datasets: [{
    label: 'Студентов',
    data: [distribution.value.high, distribution.value.medium, distribution.value.low],
    backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
    borderRadius: 6,
    barThickness: 28,
  }],
}))

const distributionOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a' } },
  scales: {
    x: { ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { display: false } },
    y: {
      ticks: { color: '#475569', font: { size: 10 }, stepSize: 1, precision: 0 },
      grid: { color: 'rgba(148,163,184,0.06)' },
      beginAtZero: true,
    },
  },
}

const EMOTION_LABELS = {
  happy: 'Радость', neutral: 'Нейтрально', surprised: 'Удивление',
  sad: 'Грусть', angry: 'Злость', fearful: 'Страх', disgusted: 'Отвращение',
}
const EMOTION_COLORS = {
  happy: '#22c55e', neutral: '#6366f1', surprised: '#06b6d4',
  sad: '#64748b', angry: '#ef4444', fearful: '#a855f7', disgusted: '#f59e0b',
}

const emotionData = computed(() => {
  const entries = Object.entries(emotions.value).filter(([, v]) => v > 0)
  return {
    labels: entries.map(([k]) => EMOTION_LABELS[k] || k),
    datasets: [{
      data: entries.map(([, v]) => v),
      backgroundColor: entries.map(([k]) => EMOTION_COLORS[k] || '#6366f1'),
      borderColor: '#0d1220',
      borderWidth: 2,
    }],
  }
})

const emotionOptions = {
  responsive: true,
  maintainAspectRatio: false,
  cutout: '62%',
  plugins: {
    legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 11 }, boxWidth: 10 } },
    tooltip: { backgroundColor: '#0f172a' },
  },
}

// ── Утилиты ────────────────────────────────────────────────────────
function formatTime(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
function avgClass(v) {
  if (v >= 75) return 'high'
  if (v >= 50) return 'mid'
  return 'low'
}
function levelClass(score) {
  if (score >= 75) return 'high'
  if (score >= 50) return 'mid'
  return 'low'
}
function shortId(id) { return (id || '').slice(0, 8) }
function emotionLabel(e) { return e ? (EMOTION_LABELS[e] || e) : '—' }
</script>

<style scoped>
.analytics-view { display:flex; flex-direction:column; gap:16px; }

.empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:80px 20px; text-align:center; color:#475569; }
.empty-icon { font-size:48px; margin-bottom:16px; }
.empty-state h3 { font-size:16px; font-weight:600; color:#94a3b8; margin:0 0 8px; }
.empty-state p { font-size:13px; max-width:340px; margin:0; }

.header-row { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.session-tabs { display:flex; gap:8px; flex-wrap:wrap; }
.tab { display:flex; flex-direction:column; align-items:flex-start; padding:8px 14px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:10px; color:#94a3b8; cursor:pointer; transition:all .15s; font-family:inherit; }
.tab:hover { color:#e2e8f0; border-color:rgba(99,102,241,0.4); }
.tab.active { background:rgba(99,102,241,0.15); border-color:rgba(99,102,241,0.4); color:#a5b4fc; }
.tab-name { font-size:13px; font-weight:600; }
.tab-sub  { font-size:11px; color:#64748b; }
.tab.active .tab-sub { color:#818cf8; }

.live-badge { display:flex; align-items:center; gap:6px; padding:6px 12px; background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.25); border-radius:20px; color:#22c55e; font-size:11px; font-weight:600; }
.live-dot { width:6px; height:6px; border-radius:50%; background:#22c55e; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

.kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
.kpi { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:14px 16px; }
.kpi-value { font-size:24px; font-weight:700; color:#f1f5f9; letter-spacing:-0.5px; line-height:1.1; }
.kpi-value.high { color:#22c55e; }
.kpi-value.mid  { color:#f59e0b; }
.kpi-value.low  { color:#ef4444; }
.kpi-sub { font-size:14px; color:#475569; font-weight:500; margin-left:4px; }
.kpi-label { font-size:11px; color:#64748b; margin-top:4px; }

.card { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:14px; padding:18px 20px; }
.card-head { display:flex; align-items:baseline; justify-content:space-between; gap:8px; margin-bottom:14px; }
.card-head h3 { font-size:14px; font-weight:600; color:#f1f5f9; margin:0; }
.card-sub { font-size:11px; color:#64748b; }

.chart-wrap { position:relative; height:220px; }
.chart-wrap--lg { height:260px; }
.chart-empty { display:flex; align-items:center; justify-content:center; height:100%; color:#475569; font-size:12px; }

.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

.student-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; max-height:320px; overflow-y:auto; }
.student-row { display:grid; grid-template-columns:80px 1fr 56px 100px; align-items:center; gap:12px; padding:8px 10px; background:rgba(255,255,255,0.025); border-radius:8px; }
.student-id { font-family:monospace; font-size:11px; color:#64748b; }
.student-bar { background:rgba(255,255,255,0.06); height:6px; border-radius:3px; overflow:hidden; }
.student-bar > span { display:block; height:100%; border-radius:3px; transition:width .4s; }
.student-row.high  .student-bar > span { background:#22c55e; }
.student-row.mid   .student-bar > span { background:#f59e0b; }
.student-row.low   .student-bar > span { background:#ef4444; }
.student-score { font-size:13px; font-weight:700; text-align:right; }
.student-row.high  .student-score { color:#22c55e; }
.student-row.mid   .student-score { color:#f59e0b; }
.student-row.low   .student-score { color:#ef4444; }
.student-emotion { font-size:11px; color:#94a3b8; text-align:right; }
</style>
