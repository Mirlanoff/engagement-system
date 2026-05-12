<template>
  <div class="trends-tab">
    <div v-if="loading" class="state">
      <div class="spinner"></div>
      <p>Загружаем тренды...</p>
    </div>

    <div v-else-if="error" class="state error">
      <p>Ошибка загрузки</p>
      <button class="retry" @click="load">Повторить</button>
    </div>

    <div v-else-if="trend.length === 0" class="state empty">
      <div class="empty-icon">📉</div>
      <h3>За этот период нет данных</h3>
      <p>Выберите другой диапазон дат, чтобы увидеть тренд.</p>
    </div>

    <template v-else>
      <div class="chart-card">
        <div class="chart-card-header">
          <h3>Вовлечённость по дням</h3>
          <span class="hint">
            средняя {{ averageLabel }} · {{ trend.length }} {{ daysLabel }}
          </span>
        </div>
        <div class="chart-wrap">
          <Line :data="lineData" :options="lineOptions" />
        </div>
      </div>

      <div class="chart-card">
        <div class="chart-card-header">
          <h3>Количество уроков по дням</h3>
          <span class="hint">всего {{ totalSessions }} {{ sessionsWord(totalSessions) }}</span>
        </div>
        <div class="chart-wrap small">
          <Bar :data="barData" :options="barOptions" />
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, inject } from 'vue'
import { Line, Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import { analytics } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'

ChartJS.register(
  CategoryScale, LinearScale, PointElement, LineElement, BarElement,
  Title, Tooltip, Legend, Filler,
)

const { from, to } = useAnalyticsFilters()

const loading = ref(false)
const error   = ref(false)
const trend   = ref([])

const daysLabel = computed(() => {
  const n = trend.value.length
  if (n === 1) return 'день'
  if (n >= 2 && n <= 4) return 'дня'
  return 'дней'
})

const averageLabel = computed(() => {
  const scores = trend.value.map(p => Number(p.avg_score) || 0).filter(v => v > 0)
  if (!scores.length) return '—'
  const avg = scores.reduce((a, b) => a + b, 0) / scores.length
  return `${avg.toFixed(1)}%`
})

const totalSessions = computed(() =>
  trend.value.reduce((s, p) => s + (Number(p.sessions_count) || 0), 0)
)

function sessionsWord(n) {
  const m = Math.abs(Number(n) || 0) % 100
  const lastDigit = m % 10
  if (m >= 11 && m <= 14) return 'уроков'
  if (lastDigit === 1)    return 'урок'
  if (lastDigit >= 2 && lastDigit <= 4) return 'урока'
  return 'уроков'
}

function formatRuDate(iso) {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

// ── Line chart: area-filled engagement trend ──────────────────
const lineData = computed(() => ({
  labels: trend.value.map(p => formatRuDate(p.date)),
  datasets: [{
    label: 'Средний % вовлечённости',
    data: trend.value.map(p => Number(p.avg_score) || 0),
    borderColor: '#22c55e',
    backgroundColor: ctx => {
      const { chart } = ctx
      const { ctx: c, chartArea } = chart
      if (!chartArea) return 'rgba(34,197,94,0.15)'
      const grad = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
      grad.addColorStop(0,    'rgba(34,197,94,0.45)')
      grad.addColorStop(0.5,  'rgba(34,197,94,0.18)')
      grad.addColorStop(1,    'rgba(34,197,94,0.00)')
      return grad
    },
    pointBackgroundColor: '#bbf7d0',
    pointBorderColor: '#22c55e',
    pointBorderWidth: 2,
    pointRadius: 4,
    pointHoverRadius: 6,
    tension: 0.4,
    fill: true,
    borderWidth: 2.5,
  }],
}))

const lineOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      displayColors: false,
      callbacks: {
        label: ctx => ` ${ctx.parsed.y.toFixed(2)}%`,
      },
    },
  },
  scales: {
    x: {
      ticks: { color: '#94a3b8' },
      grid:  { color: 'rgba(255,255,255,0.05)' },
    },
    y: {
      beginAtZero: true,
      max: 100,
      ticks: { color: '#94a3b8', callback: v => `${v}%` },
      grid:  { color: 'rgba(255,255,255,0.06)' },
    },
  },
  interaction: { mode: 'nearest', intersect: false },
}))

// ── Bar chart: sessions per day ───────────────────────────────
const barData = computed(() => ({
  labels: trend.value.map(p => formatRuDate(p.date)),
  datasets: [{
    label: 'Уроков',
    data: trend.value.map(p => Number(p.sessions_count) || 0),
    backgroundColor: 'rgba(99,102,241,0.55)',
    hoverBackgroundColor: 'rgba(99,102,241,0.85)',
    borderRadius: 4,
    borderSkipped: false,
    maxBarThickness: 32,
  }],
}))

const barOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      displayColors: false,
      callbacks: {
        label: ctx => ` ${ctx.parsed.y} ${sessionsWord(ctx.parsed.y)}`,
      },
    },
  },
  scales: {
    x: {
      ticks: { color: '#94a3b8' },
      grid:  { display: false },
    },
    y: {
      beginAtZero: true,
      ticks: { color: '#94a3b8', precision: 0 },
      grid:  { color: 'rgba(255,255,255,0.05)' },
    },
  },
}))

async function load() {
  loading.value = true
  error.value   = false
  try {
    const { data } = await analytics.overview({ from: from.value, to: to.value })
    trend.value = (data.daily_trend || []).map(p => ({
      date: p.date,
      avg_score: Number(p.avg_score),
      sessions_count: Number(p.sessions_count) || 0,
    }))
  } catch (e) {
    console.warn('trends load failed', e)
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
.trends-tab { display:flex; flex-direction:column; gap:16px; }
.state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px; gap:12px; color:#94a3b8; text-align:center; }
.state.error { color:#fca5a5; }
.state.empty .empty-icon { font-size:40px; }
.state h3 { margin:0; font-size:16px; color:#e2e8f0; }
.state p { margin:0; font-size:13px; color:#94a3b8; }
.retry { padding:8px 16px; background:rgba(99,102,241,0.15); color:#a5b4fc; border:1px solid rgba(99,102,241,0.3); border-radius:8px; cursor:pointer; font-size:12px; font-family:inherit; }
.retry:hover { background:rgba(99,102,241,0.25); }
.spinner { width:28px; height:28px; border:3px solid rgba(255,255,255,0.08); border-top-color:#6366f1; border-radius:50%; animation:spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.chart-card { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px 18px; }
.chart-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.chart-card-header h3 { font-size:14px; font-weight:600; color:#e2e8f0; margin:0; }
.chart-card-header .hint { font-size:12px; color:#64748b; }
.chart-wrap { position:relative; height:340px; }
.chart-wrap.small { height: 180px; }
</style>
