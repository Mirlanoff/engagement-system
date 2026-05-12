<template>
  <div class="insights-tab">
    <div v-if="loading" class="state">
      <div class="spinner"></div>
      <p>Считаем инсайты...</p>
    </div>

    <div v-else-if="error" class="state error">
      <p>Ошибка загрузки</p>
      <button class="retry" @click="load">Повторить</button>
    </div>

    <div v-else-if="!insights.length" class="state empty">
      <div class="empty-icon">🤖</div>
      <h3>Недостаточно данных</h3>
      <p>За выбранный период нет агрегатов для генерации инсайтов.</p>
    </div>

    <ul v-else class="insights-list">
      <li v-for="(item, i) in insights" :key="i" class="insight-item" :class="item.tone">
        <span class="insight-icon">{{ item.icon }}</span>
        <span class="insight-text">{{ item.text }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, inject } from 'vue'
import { analytics } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'

const { from, to } = useAnalyticsFilters()

const props = defineProps({
  data: { type: Object, default: null },
})

const overview = ref(props.data)
const loading  = ref(false)
const error    = ref(false)

function fmt(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return `${Number(v).toFixed(1)}%`
}

const insights = computed(() => {
  const d = overview.value
  if (!d || !d.summary) return []
  const out = []

  const classrooms = d.classrooms || []
  const summary    = d.summary || {}
  const total      = summary.total_classrooms ?? classrooms.length
  const belowHalf  = classrooms.filter(c => Number(c.avg_score) < 50).length

  out.push({
    icon: '🏫',
    tone: '',
    text: `Средняя вовлечённость по школе: ${fmt(summary.school_avg)}. ${belowHalf} из ${total} классов ниже 50%.`,
  })

  if (summary.worst_classroom) {
    const w = summary.worst_classroom
    out.push({
      icon: '⚠️',
      tone: 'danger',
      text: `${w.classroom_name} показывает худшие результаты (средний ${fmt(w.avg_score)}, макс ${fmt(w.max_score)}, сессий ${w.sessions_count}).`,
    })
  }

  if (summary.best_classroom) {
    const b = summary.best_classroom
    out.push({
      icon: '🏆',
      tone: 'success',
      text: `${b.classroom_name} — лучший за период (средний ${fmt(b.avg_score)}, сессий ${b.sessions_count}).`,
    })
  }

  return out
})

async function load() {
  loading.value = true
  error.value   = false
  try {
    const { data } = await analytics.overview({ from: from.value, to: to.value })
    overview.value = data
  } catch (e) {
    console.warn('insights load failed', e)
    error.value = true
  } finally {
    loading.value = false
  }
}

// Sync with parent if it provides fresh overview data.
watch(() => props.data, v => {
  if (v) overview.value = v
})

// On mount, only fetch if parent didn't already feed us data.
onMounted(() => {
  if (!overview.value) load()
})

// On date change, refresh.
watch([from, to], load)

// Real-time refresh trigger from AnalyticsView (WebSocket / polling).
const refreshTrigger = inject('analyticsRefreshTrigger', ref(0))
watch(refreshTrigger, () => load())
</script>

<style scoped>
.insights-tab { display:flex; flex-direction:column; gap:16px; }
.state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px; gap:12px; color:#94a3b8; text-align:center; }
.state.error { color:#fca5a5; }
.state.empty .empty-icon { font-size:40px; }
.state h3 { margin:0; font-size:16px; color:#e2e8f0; }
.state p { margin:0; font-size:13px; color:#94a3b8; }
.retry { padding:8px 16px; background:rgba(99,102,241,0.15); color:#a5b4fc; border:1px solid rgba(99,102,241,0.3); border-radius:8px; cursor:pointer; font-size:12px; font-family:inherit; }
.retry:hover { background:rgba(99,102,241,0.25); }
.spinner { width:28px; height:28px; border:3px solid rgba(255,255,255,0.08); border-top-color:#6366f1; border-radius:50%; animation:spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.insights-list { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:12px; }
.insight-item {
  display:flex; align-items:flex-start; gap:12px;
  padding:14px 18px;
  background:rgba(255,255,255,0.03);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  font-size:14px; line-height:1.5;
  color:#e2e8f0;
}
.insight-item.success { border-left:3px solid #22c55e; }
.insight-item.danger  { border-left:3px solid #ef4444; }
.insight-icon { font-size:20px; flex-shrink:0; }
.insight-text { flex:1; }
</style>
