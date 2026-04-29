<template>
  <div class="history-view">
    <div class="history-header">
      <div class="history-filters">
        <select v-model="statusFilter" class="filter-select">
          <option value="">Все статусы</option>
          <option value="completed">Завершённые</option>
          <option value="cancelled">Отменённые</option>
          <option value="active">Активные</option>
        </select>
      </div>
      <span class="history-total">Всего: {{ total }}</span>
    </div>

    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <p>Загрузка...</p>
    </div>

    <div v-else-if="sessions.length === 0" class="empty-state">
      <div class="empty-icon">🕐</div>
      <h3>Нет уроков</h3>
      <p>История уроков появится после проведения первого урока</p>
    </div>

    <div v-else class="sessions-list">
      <div
        v-for="session in sessions"
        :key="session.id"
        class="session-row"
        :class="session.engagement_level || 'none'"
      >
        <div class="session-main">
          <div class="session-title">
            <span class="classroom-name">{{ session.classroom_name }}</span>
            <span class="session-subject" v-if="session.subject">{{ session.subject }}</span>
          </div>
          <div class="session-meta">
            <span>{{ formatDate(session.started_at) }}</span>
            <span v-if="session.duration_minutes">• {{ session.duration_minutes }} мин</span>
            <span>• {{ session.teacher_name }}</span>
            <span>• {{ session.students_count }} студентов</span>
          </div>
        </div>
        <div class="session-stats">
          <div class="stat" v-if="session.avg_engagement_score != null">
            <span class="stat-value" :class="levelClass(session.avg_engagement_score)">
              {{ Math.round(session.avg_engagement_score) }}%
            </span>
            <span class="stat-label">Ср. балл</span>
          </div>
          <div class="stat" v-if="session.total_snapshots">
            <span class="stat-value">{{ session.total_snapshots }}</span>
            <span class="stat-label">Снимков</span>
          </div>
        </div>
        <div class="session-status">
          <span class="status-badge" :class="session.status">
            {{ statusLabel(session.status) }}
          </span>
        </div>
      </div>
    </div>

    <div v-if="meta.last_page > 1" class="pagination">
      <button
        class="page-btn"
        :disabled="meta.current_page <= 1"
        @click="loadPage(meta.current_page - 1)"
      >←</button>
      <span class="page-info">{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button
        class="page-btn"
        :disabled="meta.current_page >= meta.last_page"
        @click="loadPage(meta.current_page + 1)"
      >→</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import api from '@/api'

const sessions     = ref([])
const loading      = ref(true)
const statusFilter = ref('')
const total        = ref(0)
const meta         = ref({ current_page: 1, last_page: 1 })

async function loadPage(page = 1) {
  loading.value = true
  try {
    const params = { page, per_page: 15 }
    if (statusFilter.value) params.status = statusFilter.value
    const { data } = await api.get('/sessions', { params })
    sessions.value = data.data || []
    meta.value = data.meta || { current_page: 1, last_page: 1 }
    total.value = data.meta?.total || sessions.value.length
  } catch (e) {
    console.warn('loadHistory failed:', e)
    sessions.value = []
  } finally {
    loading.value = false
  }
}

watch(statusFilter, () => loadPage(1))
onMounted(() => loadPage(1))

function formatDate(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short', year: 'numeric' }) +
    ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function statusLabel(status) {
  return {
    completed: 'Завершён',
    active: 'Активный',
    paused: 'На паузе',
    cancelled: 'Отменён',
    scheduled: 'Запланирован',
  }[status] || status
}

function levelClass(score) {
  if (score >= 75) return 'high'
  if (score >= 50) return 'medium'
  return 'low'
}
</script>

<style scoped>
.history-view { display:flex; flex-direction:column; gap:16px; }
.history-header { display:flex; align-items:center; justify-content:space-between; }
.history-filters { display:flex; gap:10px; }
.filter-select { padding:8px 12px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#f1f5f9; font-size:13px; font-family:inherit; }
.filter-select:focus { outline:none; border-color:#6366f1; }
.filter-select option { background:#0d1220; }
.history-total { font-size:13px; color:#64748b; }

.loading-state { display:flex; flex-direction:column; align-items:center; padding:60px; color:#64748b; }
.spinner { width:32px; height:32px; border:3px solid rgba(255,255,255,0.1); border-top-color:#6366f1; border-radius:50%; animation:spin 0.8s linear infinite; margin-bottom:12px; }
@keyframes spin { to { transform:rotate(360deg); } }

.empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:80px 20px; color:#475569; text-align:center; }
.empty-icon { font-size:48px; margin-bottom:16px; }
.empty-state h3 { font-size:16px; font-weight:600; color:#64748b; margin:0 0 8px; }
.empty-state p { font-size:13px; color:#475569; margin:0; max-width:300px; }

.sessions-list { display:flex; flex-direction:column; gap:8px; }
.session-row { display:flex; align-items:center; gap:16px; padding:16px 20px; background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:12px; transition:all 0.15s; }
.session-row:hover { border-color:rgba(99,102,241,0.3); background:#141d2e; }
.session-row.high { border-left:3px solid #22c55e; }
.session-row.medium { border-left:3px solid #f59e0b; }
.session-row.low { border-left:3px solid #ef4444; }

.session-main { flex:1; min-width:0; }
.session-title { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
.classroom-name { font-size:14px; font-weight:600; color:#f1f5f9; }
.session-subject { font-size:12px; color:#94a3b8; padding:2px 8px; background:rgba(99,102,241,0.1); border-radius:4px; }
.session-meta { font-size:12px; color:#64748b; }

.session-stats { display:flex; gap:16px; }
.stat { text-align:center; }
.stat-value { display:block; font-size:16px; font-weight:700; color:#f1f5f9; }
.stat-value.high { color:#22c55e; }
.stat-value.medium { color:#f59e0b; }
.stat-value.low { color:#ef4444; }
.stat-label { display:block; font-size:10px; color:#64748b; margin-top:2px; }

.session-status { flex-shrink:0; }
.status-badge { padding:4px 10px; border-radius:6px; font-size:11px; font-weight:600; }
.status-badge.completed { background:rgba(34,197,94,0.1); color:#22c55e; }
.status-badge.active { background:rgba(99,102,241,0.1); color:#a5b4fc; }
.status-badge.paused { background:rgba(245,158,11,0.1); color:#f59e0b; }
.status-badge.cancelled { background:rgba(239,68,68,0.1); color:#ef4444; }
.status-badge.scheduled { background:rgba(148,163,184,0.1); color:#94a3b8; }

.pagination { display:flex; align-items:center; justify-content:center; gap:12px; padding-top:8px; }
.page-btn { padding:6px 14px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#94a3b8; cursor:pointer; font-size:14px; transition:all 0.15s; }
.page-btn:hover:not(:disabled) { color:#f1f5f9; border-color:rgba(99,102,241,0.4); }
.page-btn:disabled { opacity:0.3; cursor:not-allowed; }
.page-info { font-size:13px; color:#64748b; }
</style>
