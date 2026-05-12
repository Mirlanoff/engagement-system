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

    <div v-else class="chart-card">
      <div class="chart-card-header">
        <h3>Средняя вовлечённость по дням</h3>
        <span class="hint">{{ trend.length }} {{ daysLabel }}</span>
      </div>
      <div class="chart-wrap">
        <Line :data="chartData" :options="chartOptions" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, inject } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import { analytics } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

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

function formatRuDate(iso) {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

const chartData = computed(() => ({
  labels: trend.value.map(p => formatRuDate(p.date)),
  datasets: [{
    label: 'Средний % вовлечённости',
    data: trend.value.map(p => Number(p.avg_score)),
    borderColor: '#6366f1',
    backgroundColor: 'rgba(99,102,241,0.18)',
    pointBackgroundColor: '#a5b4fc',
    pointBorderColor: '#6366f1',
    pointRadius: 4,
    pointHoverRadius: 6,
    tension: 0.3,
    fill: true,
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: ctx => `${ctx.parsed.y.toFixed(2)}%`,
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
}))

async function load() {
  loading.value = true
  error.value   = false
  try {
    const { data } = await analytics.overview({ from: from.value, to: to.value })
    trend.value = (data.daily_trend || []).map(p => ({
      date: p.date,
      avg_score: Number(p.avg_score),
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
.chart-wrap { position:relative; height:360px; }
</style>
