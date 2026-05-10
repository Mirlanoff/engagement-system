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
      <div class="kpi-row">
        <KpiCard
          label="Средняя по школе"
          :value="formatScore(summary.school_avg)"
          :tone="scoreTone(summary.school_avg)"
          :sub="`${classrooms.length} из ${summary.total_classrooms} классов с данными`"
        />
        <KpiCard
          label="Лучший класс"
          :value="summary.best_classroom?.classroom_name || '—'"
          :sub="summary.best_classroom ? `средний ${formatScore(summary.best_classroom.avg_score)}` : ''"
          tone="success"
        />
        <KpiCard
          label="Худший класс"
          :value="summary.worst_classroom?.classroom_name || '—'"
          :sub="summary.worst_classroom ? `средний ${formatScore(summary.worst_classroom.avg_score)}` : ''"
          tone="danger"
        />
      </div>

      <div class="chart-card">
        <div class="chart-card-header">
          <h3>Средняя вовлечённость по классам</h3>
        </div>
        <div class="chart-wrap">
          <Bar :data="chartData" :options="chartOptions" />
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Класс</th>
              <th class="num">Средний</th>
              <th class="num">Мин</th>
              <th class="num">Макс</th>
              <th class="num">Сессий</th>
              <th class="num">Выс / Сред / Низк</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in sortedClassrooms" :key="c.classroom_id">
              <td>{{ c.classroom_name }}</td>
              <td class="num" :class="scoreTone(c.avg_score)">{{ formatScore(c.avg_score) }}</td>
              <td class="num">{{ formatScore(c.min_score) }}</td>
              <td class="num">{{ formatScore(c.max_score) }}</td>
              <td class="num">{{ c.sessions_count }}</td>
              <td class="num">{{ c.distribution.high }} / {{ c.distribution.medium }} / {{ c.distribution.low }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'
import { analytics } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'
import KpiCard from './KpiCard.vue'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

const { from, to } = useAnalyticsFilters()

const loading    = ref(false)
const error      = ref(false)
const classrooms = ref([])
const summary    = ref({ total_classrooms: 0, school_avg: 0, best_classroom: null, worst_classroom: null })

const emit = defineEmits(['loaded'])

const hasData = computed(() => classrooms.value.length > 0)

const sortedClassrooms = computed(() =>
  [...classrooms.value].sort((a, b) => b.avg_score - a.avg_score)
)

function formatScore(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return `${Number(v).toFixed(1)}%`
}

function scoreTone(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return ''
  if (n >= 75) return 'success'
  if (n >= 50) return 'warning'
  return 'danger'
}

const chartData = computed(() => ({
  labels: sortedClassrooms.value.map(c => c.classroom_name),
  datasets: [{
    label: 'Средний % вовлечённости',
    data: sortedClassrooms.value.map(c => Number(c.avg_score) || 0),
    backgroundColor: sortedClassrooms.value.map(c => {
      const n = Number(c.avg_score)
      if (n >= 75) return 'rgba(34,197,94,0.7)'
      if (n >= 50) return 'rgba(245,158,11,0.7)'
      return 'rgba(239,68,68,0.7)'
    }),
    borderRadius: 6,
    borderSkipped: false,
  }],
}))

const chartOptions = computed(() => ({
  indexAxis: 'y',
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: ctx => `${ctx.parsed.x.toFixed(1)}%`,
      },
    },
  },
  scales: {
    x: {
      beginAtZero: true,
      max: 100,
      ticks: { color: '#94a3b8', callback: v => `${v}%` },
      grid:  { color: 'rgba(255,255,255,0.06)' },
    },
    y: {
      ticks: { color: '#e2e8f0' },
      grid:  { display: false },
    },
  },
}))

async function load() {
  loading.value = true
  error.value   = false
  try {
    const { data } = await analytics.overview({ from: from.value, to: to.value })
    classrooms.value = data.classrooms || []
    summary.value    = data.summary || {
      total_classrooms: 0, school_avg: 0, best_classroom: null, worst_classroom: null,
    }
    emit('loaded', data)
  } catch (e) {
    console.warn('overview load failed', e)
    error.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([from, to], load)
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

.chart-card, .table-card {
  background:rgba(255,255,255,0.03);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  padding:16px 18px;
}
.chart-card-header h3 { font-size:14px; font-weight:600; color:#e2e8f0; margin:0 0 12px; }
.chart-wrap { position:relative; height:340px; }

.table-card { padding:0; overflow:hidden; }
table { width:100%; border-collapse:collapse; }
th, td { padding:12px 16px; text-align:left; font-size:13px; border-bottom:1px solid rgba(255,255,255,0.05); }
th { color:#94a3b8; font-weight:500; font-size:12px; background:rgba(255,255,255,0.02); }
tbody tr:last-child td { border-bottom:none; }
tbody td { color:#e2e8f0; }
td.num, th.num { text-align:right; font-variant-numeric: tabular-nums; }
td.success { color:#22c55e; }
td.warning { color:#f59e0b; }
td.danger  { color:#ef4444; }
</style>
