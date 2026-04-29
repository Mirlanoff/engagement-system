<template>
  <div class="analytics-view">
    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <p>Загрузка аналитики...</p>
    </div>

    <template v-else>
      <!-- Общая статистика школы -->
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-icon">📚</div>
          <div class="stat-info">
            <div class="stat-value">{{ overview.total_sessions }}</div>
            <div class="stat-label">Проведено уроков</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">📊</div>
          <div class="stat-info">
            <div class="stat-value" :class="levelClass(overview.school_avg)">
              {{ overview.school_avg }}%
            </div>
            <div class="stat-label">Средняя вовлечённость</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">🏫</div>
          <div class="stat-info">
            <div class="stat-value">{{ overview.classrooms?.length || 0 }}</div>
            <div class="stat-label">Активных классов</div>
          </div>
        </div>
      </div>

      <!-- Таблица классов -->
      <div class="section">
        <h3 class="section-title">Статистика по классам</h3>
        <div v-if="overview.classrooms?.length > 0" class="classrooms-table">
          <div class="table-header">
            <span class="col-name">Класс</span>
            <span class="col-sessions">Уроков</span>
            <span class="col-score">Ср. балл</span>
            <span class="col-students">Студентов</span>
            <span class="col-last">Последний урок</span>
          </div>
          <div
            v-for="c in overview.classrooms"
            :key="c.classroom_id"
            class="table-row"
          >
            <span class="col-name">{{ c.classroom_name }}</span>
            <span class="col-sessions">{{ c.total_sessions }}</span>
            <span class="col-score" :class="levelClass(c.avg_score)">
              {{ c.avg_score > 0 ? c.avg_score + '%' : '—' }}
            </span>
            <span class="col-students">{{ c.total_students }}</span>
            <span class="col-last">{{ formatDate(c.last_session) }}</span>
          </div>
        </div>
        <div v-else class="empty-section">
          <p>Нет данных — проведите хотя бы один урок</p>
        </div>
      </div>

      <!-- Сравнение классов (визуальные бары) -->
      <div class="section" v-if="classroomsWithScore.length > 0">
        <h3 class="section-title">Сравнение классов</h3>
        <div class="comparison-bars">
          <div
            v-for="c in classroomsWithScore"
            :key="c.classroom_id"
            class="comparison-row"
          >
            <span class="bar-label">{{ c.classroom_name }}</span>
            <div class="bar-track">
              <div
                class="bar-fill"
                :class="levelClass(c.avg_score)"
                :style="{ width: c.avg_score + '%' }"
              ></div>
            </div>
            <span class="bar-value" :class="levelClass(c.avg_score)">{{ c.avg_score }}%</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api'

const loading  = ref(true)
const overview = ref({
  total_sessions: 0,
  school_avg: 0,
  classrooms: [],
})

const classroomsWithScore = computed(() =>
  (overview.value.classrooms || []).filter(c => c.avg_score > 0)
)

onMounted(async () => {
  try {
    const { data } = await api.get('/analytics/overview')
    overview.value = data.data || data || {}
  } catch (e) {
    console.warn('analytics load failed:', e)
  } finally {
    loading.value = false
  }
})

function formatDate(ts) {
  if (!ts) return '—'
  const d = new Date(ts)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}

function levelClass(score) {
  if (score >= 75) return 'high'
  if (score >= 50) return 'medium'
  return 'low'
}
</script>

<style scoped>
.analytics-view { display:flex; flex-direction:column; gap:24px; }

.loading-state { display:flex; flex-direction:column; align-items:center; padding:60px; color:#64748b; }
.spinner { width:32px; height:32px; border:3px solid rgba(255,255,255,0.1); border-top-color:#6366f1; border-radius:50%; animation:spin 0.8s linear infinite; margin-bottom:12px; }
@keyframes spin { to { transform:rotate(360deg); } }

.stats-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
.stat-card { display:flex; align-items:center; gap:14px; padding:20px; background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
.stat-icon { font-size:28px; }
.stat-info { }
.stat-value { font-size:24px; font-weight:700; color:#f1f5f9; letter-spacing:-0.5px; }
.stat-value.high { color:#22c55e; }
.stat-value.medium { color:#f59e0b; }
.stat-value.low { color:#ef4444; }
.stat-label { font-size:12px; color:#64748b; margin-top:2px; }

.section { }
.section-title { font-size:15px; font-weight:600; color:#94a3b8; margin:0 0 12px; }

.classrooms-table { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:12px; overflow:hidden; }
.table-header, .table-row { display:grid; grid-template-columns:2fr 1fr 1fr 1fr 1.5fr; padding:12px 20px; align-items:center; }
.table-header { background:rgba(255,255,255,0.03); font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
.table-row { border-top:1px solid rgba(255,255,255,0.05); font-size:13px; color:#e2e8f0; transition:background 0.1s; }
.table-row:hover { background:rgba(255,255,255,0.03); }
.col-name { font-weight:600; }
.col-score.high { color:#22c55e; font-weight:700; }
.col-score.medium { color:#f59e0b; font-weight:700; }
.col-score.low { color:#ef4444; font-weight:700; }
.col-last { color:#64748b; font-size:12px; }

.empty-section { padding:40px; text-align:center; color:#475569; font-size:13px; }

.comparison-bars { display:flex; flex-direction:column; gap:12px; }
.comparison-row { display:flex; align-items:center; gap:12px; }
.bar-label { width:120px; font-size:13px; font-weight:500; color:#94a3b8; flex-shrink:0; }
.bar-track { flex:1; height:24px; background:rgba(255,255,255,0.05); border-radius:6px; overflow:hidden; }
.bar-fill { height:100%; border-radius:6px; transition:width 0.8s ease; }
.bar-fill.high { background:linear-gradient(90deg,#16a34a,#22c55e); }
.bar-fill.medium { background:linear-gradient(90deg,#d97706,#f59e0b); }
.bar-fill.low { background:linear-gradient(90deg,#dc2626,#ef4444); }
.bar-value { width:50px; text-align:right; font-size:14px; font-weight:700; }
.bar-value.high { color:#22c55e; }
.bar-value.medium { color:#f59e0b; }
.bar-value.low { color:#ef4444; }
</style>
