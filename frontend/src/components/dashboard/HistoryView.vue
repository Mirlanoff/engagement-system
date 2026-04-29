<template>
  <div class="history-view">
    <div class="history-header">
      <div>
        <h3>История уроков</h3>
        <p>Завершённые и отменённые занятия с ключевой статистикой</p>
      </div>
      <button class="refresh-btn" @click="loadSessions" :disabled="loading">
        {{ loading ? 'Загрузка...' : 'Обновить' }}
      </button>
    </div>

    <div v-if="error" class="state-card error">{{ error }}</div>
    <div v-else-if="loading" class="state-card">Загружаем историю...</div>
    <div v-else-if="sessions.length === 0" class="state-card">
      Завершённых уроков пока нет
    </div>

    <div v-else class="sessions-list">
      <article v-for="session in sessions" :key="session.id" class="session-row">
        <div class="session-main">
          <div class="session-title">
            <strong>{{ session.classroom_name || 'Класс' }}</strong>
            <span :class="['status', session.status]">{{ statusLabel(session.status) }}</span>
          </div>
          <p>{{ session.subject || 'Урок' }} • {{ formatDate(session.started_at) }}</p>
        </div>
        <div class="session-metrics">
          <div>
            <span>Вовлечённость</span>
            <strong :class="scoreClass(session.avg_engagement_score)">{{ Math.round(session.avg_engagement_score || 0) }}%</strong>
          </div>
          <div>
            <span>Студенты</span>
            <strong>{{ session.students_count || 0 }}</strong>
          </div>
          <div>
            <span>Длительность</span>
            <strong>{{ session.duration_minutes || '—' }} мин</strong>
          </div>
          <div>
            <span>Снэпшоты</span>
            <strong>{{ session.total_snapshots || 0 }}</strong>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { sessions as sessionsApi } from '@/api'

const loading = ref(false)
const error = ref('')
const sessions = ref([])

onMounted(loadSessions)

async function loadSessions() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await sessionsApi.list({ per_page: 30 })
    sessions.value = (data.data || []).filter(s => s.status !== 'active' && s.status !== 'paused')
  } catch (e) {
    error.value = e.response?.data?.message || 'Не удалось загрузить историю уроков'
  } finally {
    loading.value = false
  }
}

function scoreClass(score = 0) {
  if (score >= 75) return 'success'
  if (score >= 50) return 'warning'
  return 'danger'
}

function statusLabel(status) {
  return {
    completed: 'Завершён',
    cancelled: 'Отменён',
    paused: 'Пауза',
    active: 'Активен',
  }[status] || status
}

function formatDate(value) {
  if (!value) return ''
  return new Date(value).toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<style scoped>
.history-view { display:flex; flex-direction:column; gap:16px; }
.history-header { display:flex; align-items:center; justify-content:space-between; gap:16px; }
.history-header h3 { margin:0; font-size:18px; color:#f1f5f9; }
.history-header p { margin:4px 0 0; font-size:12px; color:#64748b; }
.refresh-btn { padding:8px 14px; border:1px solid rgba(255,255,255,0.1); border-radius:8px; background:rgba(255,255,255,0.06); color:#e2e8f0; cursor:pointer; font-family:inherit; font-size:12px; }
.refresh-btn:disabled { opacity:.5; cursor:not-allowed; }
.state-card { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:40px; text-align:center; color:#64748b; }
.state-card.error { color:#ef4444; }
.sessions-list { display:flex; flex-direction:column; gap:10px; }
.session-row { display:flex; justify-content:space-between; gap:20px; padding:16px; background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
.session-main { min-width:220px; }
.session-title { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
.session-title strong { color:#f1f5f9; font-size:14px; }
.session-main p { margin:0; color:#64748b; font-size:12px; }
.status { font-size:10px; padding:3px 8px; border-radius:999px; background:rgba(255,255,255,0.06); color:#94a3b8; }
.status.completed { background:rgba(34,197,94,0.12); color:#4ade80; }
.status.cancelled { background:rgba(239,68,68,0.12); color:#f87171; }
.session-metrics { display:grid; grid-template-columns:repeat(4, minmax(90px, 1fr)); gap:12px; flex:1; }
.session-metrics div { display:flex; flex-direction:column; gap:4px; }
.session-metrics span { color:#64748b; font-size:11px; }
.session-metrics strong { color:#f1f5f9; font-size:14px; }
.session-metrics strong.success { color:#22c55e; }
.session-metrics strong.warning { color:#f59e0b; }
.session-metrics strong.danger { color:#ef4444; }
@media (max-width: 900px) {
  .session-row { flex-direction:column; }
  .session-metrics { grid-template-columns:repeat(2, minmax(90px, 1fr)); }
}
</style>
