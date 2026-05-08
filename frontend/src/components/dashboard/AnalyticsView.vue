<template>
  <div class="analytics-view">
    <!-- Заголовок + переключатель периода -->
    <div class="header">
      <div>
        <h2>Аналитика школы</h2>
        <p class="subtitle">Сводка вовлечённости по урокам, классам и времени.</p>
      </div>
      <div class="period-switch">
        <button
          v-for="p in periods"
          :key="p.value"
          :class="['period-btn', { active: period === p.value }]"
          @click="period = p.value"
        >{{ p.label }}</button>
      </div>
    </div>

    <!-- Состояния -->
    <div v-if="loading && !data" class="loading">Загружаю аналитику…</div>
    <div v-else-if="error"        class="error">⚠ {{ error }}</div>
    <div v-else-if="!hasData"     class="empty">
      <div class="empty-icon">📊</div>
      <h3>Пока нет данных</h3>
      <p>
        Аналитика появится автоматически после первого урока.
        Нажмите «Начать урок» во вкладке «Live», камера откроется и снэпшоты
        начнут попадать в БД.
      </p>
    </div>

    <template v-else>
      <!-- KPI -->
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-label">Средняя вовлечённость</div>
          <div class="kpi-value" :class="scoreClass(summary.avg_score)">
            {{ summary.avg_score.toFixed(1) }}%
          </div>
          <div class="kpi-sub">мин {{ summary.min_score.toFixed(0) }}% / макс {{ summary.max_score.toFixed(0) }}%</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-label">Уроков</div>
          <div class="kpi-value">{{ summary.total_sessions }}</div>
          <div class="kpi-sub">{{ summary.active_sessions }} активных, {{ summary.completed_sessions }} завершённых</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-label">Студентов</div>
          <div class="kpi-value">{{ summary.students_total }}</div>
          <div class="kpi-sub">за период</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-label">Снэпшотов</div>
          <div class="kpi-value">{{ formatNumber(summary.snapshots_total) }}</div>
          <div class="kpi-sub">кадров проанализировано</div>
        </div>
      </div>

      <!-- Распределение -->
      <div class="distribution">
        <div class="dist-title">Распределение по уровням</div>
        <div class="dist-bar" v-if="distTotal > 0">
          <div class="dist-seg high"   :style="{ width: pct(distribution.high)   + '%' }">
            <span v-if="pct(distribution.high) > 6">{{ pct(distribution.high) }}%</span>
          </div>
          <div class="dist-seg medium" :style="{ width: pct(distribution.medium) + '%' }">
            <span v-if="pct(distribution.medium) > 6">{{ pct(distribution.medium) }}%</span>
          </div>
          <div class="dist-seg low"    :style="{ width: pct(distribution.low)    + '%' }">
            <span v-if="pct(distribution.low) > 6">{{ pct(distribution.low) }}%</span>
          </div>
        </div>
        <div class="dist-legend">
          <span><i class="dot high"/>≥ 75% (хорошо) · {{ distribution.high }}</span>
          <span><i class="dot medium"/>50–74% · {{ distribution.medium }}</span>
          <span><i class="dot low"/>&lt; 50% · {{ distribution.low }}</span>
        </div>
      </div>

      <!-- Time series -->
      <div class="card">
        <div class="card-title">
          {{ period === 'today' ? 'Динамика за сегодня (по часам)' : 'Динамика по дням' }}
        </div>
        <div v-if="!timeSeries.length" class="card-empty">Нет данных за период.</div>
        <div v-else class="chart-wrap">
          <Line :data="lineData" :options="lineOptions" />
        </div>
      </div>

      <!-- Сравнение классов -->
      <div class="card">
        <div class="card-title">Классы</div>
        <div v-if="!classrooms.length" class="card-empty">Нет данных по классам.</div>
        <div v-else class="rooms">
          <div v-for="c in classrooms" :key="c.classroom_id" class="room">
            <div class="room-name">{{ c.classroom_name }}</div>
            <div class="room-bar">
              <div class="room-fill" :class="scoreClass(c.avg_score)"
                   :style="{ width: Math.min(100, c.avg_score) + '%' }"/>
              <span class="room-pct">{{ c.avg_score.toFixed(0) }}%</span>
            </div>
            <div class="room-meta">
              {{ c.sessions }} {{ pluralRu(c.sessions, 'урок', 'урока', 'уроков') }}
              · {{ c.snapshots }} снэпшотов
              · ↑{{ c.high }} ↓{{ c.low }}
            </div>
          </div>
        </div>
      </div>

      <!-- Топ уроков -->
      <div class="card" v-if="topSessions.length">
        <div class="card-title">Лучшие уроки за период</div>
        <table class="sessions-table">
          <thead>
            <tr>
              <th>Класс</th><th>Предмет</th><th>Начало</th><th class="num">Студентов</th><th class="num">Avg</th><th>Статус</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in topSessions" :key="s.id">
              <td>{{ s.classroom_name || '—' }}</td>
              <td>{{ s.subject || '—' }}</td>
              <td>{{ formatDate(s.started_at) }}</td>
              <td class="num">{{ s.students_count }}</td>
              <td class="num"><span :class="['pill', scoreClass(s.avg_score)]">{{ s.avg_score.toFixed(0) }}%</span></td>
              <td><span :class="'status ' + s.status">{{ statusLabel(s.status) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  Title, Tooltip, Legend, LineElement, PointElement,
  CategoryScale, LinearScale, Filler,
} from 'chart.js'
import { analytics } from '@/api'

ChartJS.register(Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale, Filler)

const periods = [
  { value: 'today', label: 'Сегодня' },
  { value: 'week',  label: '7 дней' },
  { value: 'month', label: '30 дней' },
]

const period  = ref('today')
const data    = ref(null)
const loading = ref(false)
const error   = ref('')
let timer = null

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const res = await analytics.overview({ period: period.value })
    data.value = res.data
  } catch (e) {
    error.value = e.response?.data?.message || e.message || 'Не удалось загрузить аналитику.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  // Авто-обновление раз в 30 сек, чтобы видно было живой прогресс урока
  timer = setInterval(load, 30000)
})
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
watch(period, load)

// ── Производные данные ───────────────────────────────────────────
const summary       = computed(() => data.value?.summary       || {})
const distribution  = computed(() => data.value?.distribution  || { high: 0, medium: 0, low: 0 })
const timeSeries    = computed(() => data.value?.time_series   || [])
const classrooms    = computed(() => data.value?.classrooms    || [])
const topSessions   = computed(() => data.value?.top_sessions  || [])

const distTotal = computed(() => distribution.value.high + distribution.value.medium + distribution.value.low)
const hasData = computed(() => (summary.value.total_sessions || 0) > 0 || (summary.value.snapshots_total || 0) > 0)

function pct(v) {
  if (!distTotal.value) return 0
  return Math.round((v / distTotal.value) * 100)
}

const lineData = computed(() => ({
  labels: timeSeries.value.map(p => formatBucket(p.at, period.value)),
  datasets: [{
    label: 'Средняя вовлечённость',
    data: timeSeries.value.map(p => p.avg_score),
    borderColor: '#8b5cf6',
    backgroundColor: 'rgba(139,92,246,0.18)',
    tension: 0.3,
    fill: true,
    pointRadius: 3,
  }],
}))

const lineOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    y: { suggestedMin: 0, suggestedMax: 100, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } },
    x: { ticks: { color: '#94a3b8', maxRotation: 0 }, grid: { display: false } },
  },
}

// ── Хелперы ──────────────────────────────────────────────────────
function scoreClass(v) { return v >= 75 ? 'high' : v >= 50 ? 'medium' : 'low' }

function formatNumber(n) {
  if (!n) return '0'
  if (n >= 1000) return (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k'
  return String(n)
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function formatBucket(iso, p) {
  const d = new Date(iso)
  if (p === 'today') return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

function pluralRu(n, one, few, many) {
  const mod10  = n % 10
  const mod100 = n % 100
  if (mod10 === 1 && mod100 !== 11) return one
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return few
  return many
}

function statusLabel(s) {
  return ({ active: 'идёт', completed: 'завершён', paused: 'пауза' })[s] || s
}
</script>

<style scoped>
.analytics-view { display:flex; flex-direction:column; gap:16px; }

.header { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
.header h2 { margin:0; font-size:18px; color:#f1f5f9; }
.subtitle { margin:2px 0 0; font-size:12px; color:#64748b; }

.period-switch { display:inline-flex; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:10px; padding:3px; }
.period-btn { background:transparent; border:none; color:#94a3b8; padding:6px 14px; font-size:12px; font-weight:600; border-radius:8px; cursor:pointer; font-family:inherit; }
.period-btn.active { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; }

.loading, .error, .empty { padding:40px; text-align:center; color:#64748b; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:12px; }
.error { color:#fca5a5; }
.empty .empty-icon { font-size:42px; margin-bottom:12px; }
.empty h3 { color:#cbd5f5; margin:0 0 8px; font-size:16px; }
.empty p { max-width:420px; margin:0 auto; font-size:13px; line-height:1.5; }

.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
.kpi-card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:16px; }
.kpi-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
.kpi-value { font-size:28px; font-weight:700; color:#f1f5f9; margin-top:4px; letter-spacing:-0.5px; }
.kpi-value.high   { color:#22c55e; }
.kpi-value.medium { color:#f59e0b; }
.kpi-value.low    { color:#ef4444; }
.kpi-sub { font-size:11px; color:#64748b; margin-top:4px; }

.distribution { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:14px 16px; }
.dist-title { font-size:13px; color:#cbd5f5; margin-bottom:10px; }
.dist-bar { display:flex; height:18px; border-radius:9px; overflow:hidden; background:rgba(255,255,255,0.05); }
.dist-seg { display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:600; color:white; min-width:0; }
.dist-seg.high   { background:#16a34a; }
.dist-seg.medium { background:#d97706; }
.dist-seg.low    { background:#dc2626; }
.dist-legend { display:flex; gap:16px; flex-wrap:wrap; margin-top:10px; font-size:12px; color:#94a3b8; }
.dist-legend .dot { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:6px; vertical-align:middle; }
.dist-legend .dot.high   { background:#16a34a; }
.dist-legend .dot.medium { background:#d97706; }
.dist-legend .dot.low    { background:#dc2626; }

.card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:16px; }
.card-title { font-size:13px; font-weight:600; color:#cbd5f5; margin-bottom:12px; }
.card-empty { padding:24px; text-align:center; color:#64748b; font-size:12px; }
.chart-wrap { height:260px; }

.rooms { display:flex; flex-direction:column; gap:10px; }
.room { display:grid; grid-template-columns:160px 1fr auto; gap:12px; align-items:center; }
.room-name { font-size:13px; color:#e2e8f0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.room-bar { position:relative; height:8px; background:rgba(255,255,255,0.05); border-radius:4px; overflow:hidden; }
.room-fill { height:100%; border-radius:4px; transition:width .3s; }
.room-fill.high   { background:linear-gradient(90deg,#10b981,#22c55e); }
.room-fill.medium { background:linear-gradient(90deg,#f59e0b,#fb923c); }
.room-fill.low    { background:linear-gradient(90deg,#ef4444,#f87171); }
.room-pct { position:absolute; right:6px; top:-18px; font-size:11px; color:#94a3b8; }
.room-meta { font-size:11px; color:#64748b; white-space:nowrap; }

.sessions-table { width:100%; border-collapse:collapse; font-size:12px; }
.sessions-table th { text-align:left; padding:8px 10px; font-weight:600; color:#94a3b8; border-bottom:1px solid rgba(255,255,255,0.08); }
.sessions-table td { padding:8px 10px; color:#cbd5f5; border-bottom:1px solid rgba(255,255,255,0.04); }
.sessions-table th.num, .sessions-table td.num { text-align:right; }
.pill { display:inline-block; padding:2px 8px; border-radius:10px; font-weight:600; font-size:11px; }
.pill.high   { background:rgba(34,197,94,0.15); color:#86efac; }
.pill.medium { background:rgba(245,158,11,0.15); color:#fcd34d; }
.pill.low    { background:rgba(239,68,68,0.15); color:#fca5a5; }
.status { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:#94a3b8; }
.status.active { color:#86efac; }
.status.paused { color:#fcd34d; }
</style>
