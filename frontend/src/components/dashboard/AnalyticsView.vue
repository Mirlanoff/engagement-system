<template>
  <div class="analytics-view">
    <div class="analytics-toolbar">
      <div>
        <h3>Аналитика школы</h3>
        <p>{{ periodLabel }}</p>
      </div>
      <button class="refresh-btn" @click="loadData" :disabled="loading">
        {{ loading ? 'Обновляем...' : 'Обновить' }}
      </button>
    </div>

    <div v-if="error" class="state-card error">{{ error }}</div>
    <div v-else-if="loading" class="state-card">Загрузка аналитики...</div>

    <div v-else>
      <div class="summary-grid">
        <div class="metric-card">
          <span class="metric-label">Классов</span>
          <strong>{{ summary.total_classrooms || 0 }}</strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Средняя вовлечённость</span>
          <strong :class="scoreClass(summary.school_avg)">{{ Math.round(summary.school_avg || 0) }}%</strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Лучший класс</span>
          <strong>{{ summary.best_classroom?.classroom_name || '—' }}</strong>
        </div>
        <div class="metric-card">
          <span class="metric-label">Зона риска</span>
          <strong>{{ summary.worst_classroom?.classroom_name || '—' }}</strong>
        </div>
      </div>

      <section class="panel">
        <div class="panel-header">
          <h4>Сравнение классов</h4>
          <span>{{ classrooms.length }} классов</span>
        </div>
        <div v-if="classrooms.length === 0" class="empty">Нет данных за выбранный период</div>
        <div v-else class="class-list">
          <div v-for="room in classrooms" :key="room.classroom_id" class="class-row">
            <div class="class-info">
              <strong>{{ room.classroom_name }}</strong>
              <span>{{ room.points }} точек данных</span>
            </div>
            <div class="score-bar">
              <div class="score-fill" :class="scoreClass(room.avg_score)" :style="{ width: `${room.avg_score || 0}%` }"></div>
            </div>
            <span class="score-value" :class="scoreClass(room.avg_score)">{{ Math.round(room.avg_score || 0) }}%</span>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-header">
          <h4>Тренд по дням</h4>
          <span>{{ dailyTrend.length }} дней</span>
        </div>
        <div v-if="dailyTrend.length === 0" class="empty">Пока нет агрегированной статистики</div>
        <div v-else class="trend-bars">
          <div v-for="point in dailyTrend" :key="point.date" class="trend-item">
            <div class="trend-track">
              <div class="trend-fill" :class="scoreClass(point.avg_score)" :style="{ height: `${point.avg_score || 0}%` }"></div>
            </div>
            <span>{{ formatDay(point.date) }}</span>
            <strong>{{ Math.round(point.avg_score || 0) }}%</strong>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { analytics } from '@/api'

const loading = ref(false)
const error = ref('')
const overview = ref({ classrooms: [], daily_trend: [], summary: {}, period: {} })

const classrooms = computed(() => overview.value.classrooms || [])
const dailyTrend = computed(() => overview.value.daily_trend || [])
const summary = computed(() => overview.value.summary || {})
const periodLabel = computed(() => {
  const period = overview.value.period
  if (!period?.from || !period?.to) return 'Последние 7 дней'
  return `${period.from} — ${period.to}`
})

onMounted(loadData)

async function loadData() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await analytics.overview()
    overview.value = data
  } catch (e) {
    error.value = e.response?.data?.message || 'Не удалось загрузить аналитику'
  } finally {
    loading.value = false
  }
}

function scoreClass(score = 0) {
  if (score >= 75) return 'success'
  if (score >= 50) return 'warning'
  return 'danger'
}

function formatDay(date) {
  return new Date(date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}
</script>

<style scoped>
.analytics-view { display:flex; flex-direction:column; gap:16px; }
.analytics-toolbar { display:flex; align-items:center; justify-content:space-between; gap:16px; }
.analytics-toolbar h3 { margin:0; font-size:18px; color:#f1f5f9; }
.analytics-toolbar p { margin:4px 0 0; font-size:12px; color:#64748b; }
.refresh-btn { padding:8px 14px; border:1px solid rgba(255,255,255,0.1); border-radius:8px; background:rgba(255,255,255,0.06); color:#e2e8f0; cursor:pointer; font-family:inherit; font-size:12px; }
.refresh-btn:disabled { opacity:.5; cursor:not-allowed; }
.state-card, .panel, .metric-card { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
.state-card { padding:32px; text-align:center; color:#64748b; }
.state-card.error { color:#ef4444; }
.summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.metric-card { padding:16px; display:flex; flex-direction:column; gap:8px; }
.metric-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
.metric-card strong { font-size:22px; color:#f1f5f9; }
.metric-card strong.success, .score-value.success { color:#22c55e; }
.metric-card strong.warning, .score-value.warning { color:#f59e0b; }
.metric-card strong.danger, .score-value.danger { color:#ef4444; }
.panel { padding:18px; }
.panel-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.panel-header h4 { margin:0; color:#f1f5f9; font-size:15px; }
.panel-header span, .empty { color:#64748b; font-size:12px; }
.class-list { display:flex; flex-direction:column; gap:12px; }
.class-row { display:grid; grid-template-columns:180px 1fr 52px; align-items:center; gap:14px; }
.class-info { display:flex; flex-direction:column; gap:3px; min-width:0; }
.class-info strong { color:#e2e8f0; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.class-info span { color:#64748b; font-size:11px; }
.score-bar { height:8px; background:rgba(255,255,255,0.06); border-radius:999px; overflow:hidden; }
.score-fill { height:100%; border-radius:999px; }
.score-fill.success, .trend-fill.success { background:#22c55e; }
.score-fill.warning, .trend-fill.warning { background:#f59e0b; }
.score-fill.danger, .trend-fill.danger { background:#ef4444; }
.score-value { text-align:right; font-size:13px; font-weight:700; }
.trend-bars { display:flex; align-items:flex-end; gap:10px; min-height:170px; overflow-x:auto; padding-bottom:4px; }
.trend-item { min-width:56px; display:flex; flex-direction:column; align-items:center; gap:6px; }
.trend-track { width:34px; height:120px; border-radius:8px; background:rgba(255,255,255,0.06); display:flex; align-items:flex-end; overflow:hidden; }
.trend-fill { width:100%; min-height:2px; border-radius:8px 8px 0 0; }
.trend-item span { color:#64748b; font-size:11px; }
.trend-item strong { color:#e2e8f0; font-size:12px; }
@media (max-width: 900px) {
  .summary-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .class-row { grid-template-columns:1fr; gap:8px; }
  .score-value { text-align:left; }
}
</style>
