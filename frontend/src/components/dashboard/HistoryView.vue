<template>
  <div class="history-view">
    <div class="header">
      <div>
        <h2>История уроков</h2>
        <p class="subtitle">Все прошедшие и текущие сессии с показателями вовлечённости.</p>
      </div>
      <div class="filters">
        <select v-model="status" class="filter-select">
          <option value="">Все статусы</option>
          <option value="active">Идут</option>
          <option value="completed">Завершены</option>
          <option value="paused">На паузе</option>
        </select>
        <button class="refresh-btn" @click="load" :disabled="loading">
          {{ loading ? '…' : '↻ Обновить' }}
        </button>
      </div>
    </div>

    <div v-if="loading && !rows.length" class="loading">Загружаю историю…</div>
    <div v-else-if="error" class="error">⚠ {{ error }}</div>
    <div v-else-if="!rows.length" class="empty">
      <div class="empty-icon">🕐</div>
      <h3>История пуста</h3>
      <p>Начните урок во вкладке «Live» — он появится здесь после старта.</p>
    </div>

    <div v-else class="table-wrap">
      <table class="sessions-table">
        <thead>
          <tr>
            <th>Класс</th>
            <th>Предмет</th>
            <th>Учитель</th>
            <th>Начало</th>
            <th class="num">Длит.</th>
            <th class="num">Студентов</th>
            <th class="num">Снэпшотов</th>
            <th class="num">Avg</th>
            <th>Статус</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in rows" :key="s.id"
              :class="{ active: s.id === selectedId }">
            <td>{{ s.classroom_name || '—' }}</td>
            <td>{{ s.subject || '—' }}</td>
            <td>{{ s.teacher_name || '—' }}</td>
            <td>{{ formatDate(s.started_at) }}</td>
            <td class="num">{{ s.duration_minutes ? s.duration_minutes + ' мин' : '—' }}</td>
            <td class="num">{{ s.students_count || 0 }}</td>
            <td class="num">{{ s.total_snapshots || 0 }}</td>
            <td class="num">
              <span v-if="s.avg_engagement_score != null"
                    :class="['pill', scoreClass(s.avg_engagement_score)]">
                {{ Number(s.avg_engagement_score).toFixed(0) }}%
              </span>
              <span v-else class="muted">—</span>
            </td>
            <td><span :class="'status ' + s.status">{{ statusLabel(s.status) }}</span></td>
            <td>
              <button class="open-btn" @click="$emit('select', s)">Открыть →</button>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="pagination" v-if="meta.last_page > 1">
        <button :disabled="page <= 1"            @click="page = page - 1">← Назад</button>
        <span>{{ page }} / {{ meta.last_page }} · всего {{ meta.total }}</span>
        <button :disabled="page >= meta.last_page" @click="page = page + 1">Вперёд →</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { sessions as sessionsApi } from '@/api'

defineProps({ selectedId: { type: String, default: null } })
defineEmits(['select'])

const rows    = ref([])
const meta    = ref({ current_page: 1, last_page: 1, total: 0 })
const page    = ref(1)
const status  = ref('')
const loading = ref(false)
const error   = ref('')

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const params = { page: page.value, per_page: 20 }
    if (status.value) params.status = status.value
    const res = await sessionsApi.list(params)
    // Laravel paginate -> { data: [...], meta: {...}, links: {...} }
    const body = res.data
    rows.value = body.data || []
    meta.value = body.meta || {
      current_page: body.current_page || 1,
      last_page:    body.last_page    || 1,
      total:        body.total        || rows.value.length,
    }
  } catch (e) {
    error.value = e.response?.data?.message || e.message || 'Не удалось загрузить историю.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([page, status], load)

function scoreClass(v) { return v >= 75 ? 'high' : v >= 50 ? 'medium' : 'low' }

function statusLabel(s) {
  return ({ active: 'идёт', completed: 'завершён', paused: 'пауза' })[s] || s
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
.history-view { display:flex; flex-direction:column; gap:16px; }

.header { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.header h2 { margin:0; font-size:18px; color:#f1f5f9; }
.subtitle { margin:2px 0 0; font-size:12px; color:#64748b; }

.filters { display:flex; align-items:center; gap:8px; }
.filter-select { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:6px 10px; color:#cbd5f5; font-size:12px; font-family:inherit; }
.refresh-btn { background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.3); color:#c7d2fe; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; }
.refresh-btn:disabled { opacity:.5; cursor:not-allowed; }

.loading, .error, .empty { padding:40px; text-align:center; color:#64748b; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:12px; }
.error { color:#fca5a5; }
.empty .empty-icon { font-size:42px; margin-bottom:12px; }
.empty h3 { color:#cbd5f5; margin:0 0 8px; font-size:16px; }
.empty p { font-size:13px; max-width:340px; margin:0 auto; }

.table-wrap { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; overflow:hidden; }
.sessions-table { width:100%; border-collapse:collapse; font-size:12px; }
.sessions-table th { text-align:left; padding:10px 12px; font-weight:600; color:#94a3b8; border-bottom:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); }
.sessions-table td { padding:10px 12px; color:#cbd5f5; border-bottom:1px solid rgba(255,255,255,0.04); }
.sessions-table tr.active td { background:rgba(99,102,241,0.08); }
.sessions-table th.num, .sessions-table td.num { text-align:right; }

.pill { display:inline-block; padding:2px 8px; border-radius:10px; font-weight:600; font-size:11px; }
.pill.high   { background:rgba(34,197,94,0.15); color:#86efac; }
.pill.medium { background:rgba(245,158,11,0.15); color:#fcd34d; }
.pill.low    { background:rgba(239,68,68,0.15); color:#fca5a5; }
.muted { color:#475569; }

.status { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:#94a3b8; }
.status.active    { color:#86efac; }
.status.paused    { color:#fcd34d; }
.status.completed { color:#94a3b8; }

.open-btn { background:transparent; border:none; color:#a5b4fc; font-size:12px; cursor:pointer; font-family:inherit; padding:0; }
.open-btn:hover { color:#c7d2fe; }

.pagination { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; color:#94a3b8; }
.pagination button { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); color:#cbd5f5; padding:5px 10px; border-radius:6px; cursor:pointer; font-family:inherit; font-size:12px; }
.pagination button:disabled { opacity:.4; cursor:not-allowed; }
</style>
