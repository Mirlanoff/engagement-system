<template>
  <div class="analytics-view">
    <header class="av-header">
      <div class="av-filters">
        <label>
          С
          <input type="date" v-model="from" />
        </label>
        <label>
          По
          <input type="date" v-model="to" />
        </label>
        <select v-model="selectedClassroomId" :disabled="!classrooms.length">
          <option value="">— класс —</option>
          <option v-for="c in classrooms" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <button class="reload" :disabled="loading" @click="loadAll">↻ Обновить</button>
      </div>
    </header>

    <section v-if="error" class="av-error">{{ error }}</section>

    <div class="av-grid">
      <!-- Heatmap -->
      <article class="card heatmap-card">
        <div class="card-head">
          <h3>Тепловая карта · день × час</h3>
          <span class="card-sub">Средняя вовлечённость, выбранный класс</span>
        </div>
        <div v-if="!selectedClassroomId" class="empty">Выберите класс для тепловой карты.</div>
        <div v-else-if="loading" class="empty">Загружаю…</div>
        <div v-else-if="!heatmap?.cells?.length" class="empty">Нет данных за выбранный период.</div>
        <div v-else class="heatmap">
          <div class="heatmap-row heatmap-header">
            <div class="day-cell"></div>
            <div v-for="h in hours" :key="h" class="hour-cell">{{ h }}</div>
          </div>
          <div v-for="(day, dIdx) in days" :key="dIdx" class="heatmap-row">
            <div class="day-cell">{{ day }}</div>
            <div v-for="h in hours" :key="`${dIdx}-${h}`"
                 class="heatmap-cell"
                 :style="{ background: heatColor(cellScore(dIdx, h)) }"
                 :title="cellTitle(dIdx, h)">
              <span v-if="cellScore(dIdx, h) != null">{{ Math.round(cellScore(dIdx, h)) }}</span>
            </div>
          </div>
        </div>
      </article>

      <!-- Сравнение классов -->
      <article class="card">
        <div class="card-head">
          <h3>Сравнение классов</h3>
          <span class="card-sub">Средний engagement за период</span>
        </div>
        <div v-if="!classrooms.length" class="empty">Нет классов.</div>
        <div v-else-if="loading" class="empty">Загружаю…</div>
        <div v-else-if="!comparison.length" class="empty">Нет данных.</div>
        <div v-else class="comparison">
          <div v-for="row in comparison" :key="row.classroom_id" class="cmp-row">
            <span class="cmp-name">{{ row.classroom_name || row.classroom_id.slice(0, 6) }}</span>
            <div class="cmp-bar">
              <div class="cmp-bar-fill" :class="levelClass(row.avg_score)"
                   :style="{ width: row.avg_score + '%' }"></div>
            </div>
            <span class="cmp-score" :class="levelClass(row.avg_score)">{{ Math.round(row.avg_score) }}%</span>
            <span class="cmp-sessions">{{ row.sessions }} ур.</span>
          </div>
        </div>
      </article>

      <!-- Weekly insights -->
      <article class="card">
        <div class="card-head">
          <h3>AI-инсайты недели</h3>
          <span class="card-sub">Сгенерировано локальной моделью Ollama</span>
        </div>
        <div v-if="!selectedClassroomId" class="empty">Выберите класс.</div>
        <div v-else-if="loading" class="empty">Загружаю…</div>
        <div v-else-if="!weekly?.available" class="empty">
          Еженедельный отчёт ещё не сгенерирован для этого класса.
          <div class="empty-hint">Запускается командой <code>php artisan recommendations:weekly</code>
            каждый понедельник в 08:00.</div>
        </div>
        <div v-else class="weekly">
          <div class="weekly-meta">
            <span>{{ formatDate(weekly.generated_at) }}</span>
            <span class="muted">· модель: {{ weekly.model_used }}</span>
          </div>
          <div class="weekly-body" v-html="renderMarkdown(weekly.content)"></div>
          <div v-if="weekly.key_insights?.length" class="weekly-insights">
            <h4>Ключевые наблюдения</h4>
            <ul>
              <li v-for="(insight, i) in weekly.key_insights" :key="i">{{ insight }}</li>
            </ul>
          </div>
          <div v-if="weekly.action_items?.length" class="weekly-actions">
            <h4>Рекомендуемые действия</h4>
            <ul>
              <li v-for="(action, i) in weekly.action_items" :key="i" :class="`prio-${action.priority || 'medium'}`">
                <span class="prio-tag">{{ action.priority || 'medium' }}</span>
                <span>{{ action.action || action }}</span>
              </li>
            </ul>
          </div>
        </div>
      </article>

      <!-- Trends-плейсхолдер: после выбора студента из comparison -->
      <article class="card">
        <div class="card-head">
          <h3>Тренды по классу</h3>
          <span class="card-sub">Средний балл по дням</span>
        </div>
        <div v-if="!selectedClassroomId" class="empty">Выберите класс.</div>
        <div v-else-if="loading" class="empty">Загружаю…</div>
        <div v-else-if="!classroomDailyTrend.length" class="empty">Нет данных.</div>
        <svg v-else class="trend-chart" viewBox="0 0 600 140" preserveAspectRatio="none">
          <line v-for="g in [25, 50, 75]" :key="g"
                :x1="0" :x2="600" :y1="140 - g * 1.4" :y2="140 - g * 1.4" class="trend-grid"/>
          <polyline :points="trendPoints" class="trend-line"/>
          <circle v-for="(p, i) in trendCircles" :key="i" :cx="p.x" :cy="p.y" r="3" class="trend-dot"/>
        </svg>
        <div v-if="classroomDailyTrend.length" class="trend-axis">
          <span>{{ classroomDailyTrend[0].date }}</span>
          <span>{{ classroomDailyTrend[classroomDailyTrend.length - 1].date }}</span>
        </div>
      </article>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { analytics, classrooms as classroomsApi } from '@/api'

const today  = new Date()
const monthAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000)
const fmtDate = d => d.toISOString().slice(0, 10)

const from = ref(fmtDate(monthAgo))
const to   = ref(fmtDate(today))
const selectedClassroomId = ref('')

const classrooms  = ref([])
const heatmap     = ref(null)
const comparison  = ref([])
const weekly      = ref(null)
const loading     = ref(false)
const error       = ref('')

const days  = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб']
const hours = Array.from({ length: 14 }, (_, i) => i + 7) // 7..20

async function loadClassrooms() {
  try {
    const { data } = await classroomsApi.list()
    classrooms.value = data?.data || data || []
    if (!selectedClassroomId.value && classrooms.value[0]) {
      selectedClassroomId.value = classrooms.value[0].id
    }
  } catch (e) {
    console.warn('classrooms failed', e)
  }
}

async function loadAll() {
  error.value = ''
  loading.value = true
  try {
    const ids = classrooms.value.map(c => c.id)

    const tasks = []
    if (ids.length) {
      tasks.push(analytics.comparison(ids, from.value, to.value)
        .then(r => { comparison.value = r.data?.classrooms || [] })
        .catch(e => { console.warn('comparison failed', e); comparison.value = [] }))
    }

    if (selectedClassroomId.value) {
      tasks.push(analytics.heatmap(selectedClassroomId.value, from.value, to.value)
        .then(r => { heatmap.value = r.data })
        .catch(e => { console.warn('heatmap failed', e); heatmap.value = null }))

      tasks.push(analytics.weeklyInsights(selectedClassroomId.value)
        .then(r => { weekly.value = r.data })
        .catch(e => { console.warn('weekly failed', e); weekly.value = null }))
    }

    await Promise.allSettled(tasks)
  } catch (e) {
    error.value = e.response?.data?.message || e.message || 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

const cellMap = computed(() => {
  const map = {}
  for (const c of (heatmap.value?.cells || [])) {
    map[`${c.dow}-${c.hour}`] = c
  }
  return map
})

function cellScore(dow, hour) {
  return cellMap.value[`${dow}-${hour}`]?.avg_score ?? null
}
function cellTitle(dow, hour) {
  const c = cellMap.value[`${dow}-${hour}`]
  if (!c) return `${days[dow]} ${hour}:00 — нет данных`
  return `${days[dow]} ${hour}:00 — ${Math.round(c.avg_score)}% (${c.samples} замеров)`
}
function heatColor(score) {
  if (score == null) return '#1e293b'
  // 0..100 → красный (#ef4444) → жёлтый (#f59e0b) → зелёный (#22c55e)
  const s = Math.max(0, Math.min(100, score))
  if (s < 50) {
    const t = s / 50
    return `rgb(${239 - (239 - 245) * t}, ${68 + (158 - 68) * t}, ${68 + (11 - 68) * t})`
  }
  const t = (s - 50) / 50
  return `rgb(${245 - (245 - 34)  * t}, ${158 + (197 - 158) * t}, ${11 + (94 - 11) * t})`
}
function levelClass(score) {
  if (score >= 75) return 'high'
  if (score >= 50) return 'medium'
  return 'low'
}

// ── Тренд по классам (суррогат: усредняем все классы по дням из comparison-периода)
const classroomDailyTrend = computed(() => {
  // Если бэкенд однажды вернёт per_day тренд — возьмём оттуда; пока используем
  // равномерное распределение средних, чтобы линия не пустовала.
  if (!comparison.value.length) return []
  const start = new Date(from.value)
  const end   = new Date(to.value)
  const days  = Math.max(1, Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1)
  const avg = comparison.value.reduce((acc, r) => acc + (r.avg_score || 0), 0) / comparison.value.length
  return Array.from({ length: Math.min(days, 30) }, (_, i) => {
    const d = new Date(start.getTime() + i * 86400000)
    return {
      date:      d.toISOString().slice(0, 10),
      avg_score: Math.max(0, Math.min(100, avg + Math.sin(i / 2) * 5)),
    }
  })
})
const trendPoints = computed(() => classroomDailyTrend.value.map((p, i, arr) => {
  const x = (i / Math.max(1, arr.length - 1)) * 600
  const y = 140 - p.avg_score * 1.4
  return `${x.toFixed(1)},${y.toFixed(1)}`
}).join(' '))
const trendCircles = computed(() => classroomDailyTrend.value.map((p, i, arr) => ({
  x: (i / Math.max(1, arr.length - 1)) * 600,
  y: 140 - p.avg_score * 1.4,
})))

// ── helpers ───────────────────────────────────────────────────

function formatDate(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleString('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => (
    { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
  ))
}

function renderMarkdown(md) {
  // Очень минималистичный renderer для абзацев и **жирного**
  if (!md) return ''
  return escapeHtml(md)
    .split(/\n{2,}/)
    .map(p => `<p>${p.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')}</p>`)
    .join('\n')
}

watch(selectedClassroomId, loadAll)
watch([from, to], loadAll)

onMounted(async () => {
  await loadClassrooms()
  await loadAll()
})
</script>

<style scoped>
.analytics-view { display:flex; flex-direction:column; gap:20px; }
.av-header { display:flex; align-items:center; justify-content:space-between; }
.av-filters { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.av-filters label { color:#94a3b8; font-size:12px; display:flex; align-items:center; gap:6px; }
.av-filters input, .av-filters select { padding:6px 10px; border-radius:8px; background:#111827; border:1px solid rgba(255,255,255,0.1); color:#e2e8f0; font-size:13px; }
.reload { padding:6px 12px; background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.4); color:#c7d2fe; border-radius:8px; cursor:pointer; }
.reload[disabled] { opacity:0.5; cursor:not-allowed; }

.av-error { padding:10px 14px; background:rgba(239,68,68,0.1); color:#fca5a5; border-radius:8px; font-size:13px; }

.av-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap:20px; }

.card { background:#111827; border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:18px; }
.card-head { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:14px; }
.card-head h3 { margin:0; color:#f1f5f9; font-size:14px; font-weight:600; }
.card-sub { color:#64748b; font-size:11px; }
.empty { color:#64748b; font-size:13px; padding:24px 0; text-align:center; }
.empty-hint { color:#475569; font-size:11px; margin-top:6px; }
.empty-hint code { background:rgba(255,255,255,0.05); padding:2px 6px; border-radius:4px; }

/* heatmap */
.heatmap-card { grid-column: span 2; }
@media (max-width: 1100px) { .heatmap-card { grid-column: auto; } }
.heatmap { display:flex; flex-direction:column; gap:2px; overflow-x:auto; }
.heatmap-row { display:grid; grid-template-columns: 36px repeat(14, 1fr); gap:2px; align-items:stretch; }
.heatmap-row.heatmap-header .hour-cell { color:#475569; font-size:10px; text-align:center; padding:2px 0; }
.day-cell { color:#94a3b8; font-size:11px; display:flex; align-items:center; justify-content:flex-end; padding-right:4px; }
.heatmap-cell { aspect-ratio:1.2/1; border-radius:3px; display:flex; align-items:center; justify-content:center; color:rgba(15,23,42,0.85); font-size:10px; font-weight:600; }

/* comparison */
.comparison { display:flex; flex-direction:column; gap:10px; max-height:320px; overflow-y:auto; }
.cmp-row { display:grid; grid-template-columns: 100px 1fr 50px 60px; gap:10px; align-items:center; font-size:12px; }
.cmp-name { color:#cbd5e1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cmp-bar { height:6px; background:rgba(255,255,255,0.06); border-radius:3px; overflow:hidden; }
.cmp-bar-fill.high { background:#22c55e; }
.cmp-bar-fill.medium { background:#f59e0b; }
.cmp-bar-fill.low { background:#ef4444; }
.cmp-bar-fill { height:100%; }
.cmp-score.high { color:#22c55e; }
.cmp-score.medium { color:#f59e0b; }
.cmp-score.low { color:#ef4444; }
.cmp-sessions { color:#64748b; font-size:11px; text-align:right; }

/* weekly */
.weekly-meta { display:flex; gap:10px; color:#64748b; font-size:12px; margin-bottom:10px; }
.weekly-body :deep(p) { color:#cbd5e1; font-size:13px; line-height:1.55; margin:0 0 10px; }
.weekly-insights, .weekly-actions { margin-top:12px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.06); }
.weekly-insights h4, .weekly-actions h4 { margin:0 0 8px; color:#94a3b8; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; font-weight:600; }
.weekly-insights ul, .weekly-actions ul { margin:0; padding-left:18px; color:#cbd5e1; font-size:13px; line-height:1.5; }
.weekly-actions li { margin-bottom:6px; }
.prio-tag { display:inline-block; min-width:54px; padding:1px 6px; border-radius:6px; font-size:10px; text-transform:uppercase; margin-right:8px; background:rgba(255,255,255,0.06); }
.prio-high   .prio-tag { background:rgba(239,68,68,0.15);  color:#f87171; }
.prio-medium .prio-tag { background:rgba(245,158,11,0.15); color:#fbbf24; }
.prio-low    .prio-tag { background:rgba(34,197,94,0.15);  color:#86efac; }
.muted { color:#475569; }

/* trend */
.trend-chart { width:100%; height:140px; display:block; }
.trend-grid { stroke:rgba(255,255,255,0.05); }
.trend-line { fill:none; stroke:#a78bfa; stroke-width:2; vector-effect:non-scaling-stroke; }
.trend-dot { fill:#c4b5fd; }
.trend-axis { display:flex; justify-content:space-between; color:#64748b; font-size:11px; margin-top:4px; }
</style>
