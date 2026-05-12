<template>
  <div class="heatmap-tab">

    <!-- ── School-wide emotion & gaze distribution ─────────────── -->
    <div v-if="emotionsLoading" class="state">
      <div class="spinner"></div>
      <p>Загружаем эмоции и взгляд...</p>
    </div>

    <div v-else-if="emotionsError" class="state error">
      <p>Не удалось загрузить распределение эмоций</p>
      <button class="retry" @click="loadEmotions">Повторить</button>
    </div>

    <div v-else class="dual">
      <EmotionChart :emotions="emotionsData.emotions" />
      <GazeChart    :gaze="emotionsData.gaze" />
    </div>

    <!-- ── Per-class hour×day heatmap ──────────────────────────── -->
    <div class="heatmap-card">
      <div class="heatmap-header">
        <h3>Вовлечённость по часам</h3>
        <label class="cl-label">
          <span>Класс</span>
          <select v-model="selectedClassroomId" :disabled="!classrooms.length">
            <option value="">— Выберите класс —</option>
            <option v-for="c in classrooms" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
      </div>

      <div v-if="loadingClassrooms" class="state">
        <div class="spinner"></div>
        <p>Загружаем классы...</p>
      </div>

      <div v-else-if="!classrooms.length" class="state empty">
        <div class="empty-icon">🏫</div>
        <h3>Нет доступных классов</h3>
      </div>

      <div v-else-if="!selectedClassroomId" class="state empty">
        <div class="empty-icon">🗓️</div>
        <h3>Выберите класс выше</h3>
        <p>Тепловая карта появится после выбора класса.</p>
      </div>

      <div v-else-if="loadingHeatmap" class="state">
        <div class="spinner"></div>
        <p>Загружаем тепловую карту...</p>
      </div>

      <div v-else-if="heatmapError" class="state error">
        <p>Ошибка загрузки</p>
        <button class="retry" @click="loadHeatmap">Повторить</button>
      </div>

      <div v-else-if="!cells.length" class="state empty">
        <div class="empty-icon">📭</div>
        <h3>Нет данных за период</h3>
        <p>В выбранном диапазоне нет агрегатов по этому классу.</p>
      </div>

      <template v-else>
        <div class="legend">
          <span class="legend-label">Низкая</span>
          <span class="legend-gradient"></span>
          <span class="legend-label">Высокая</span>
        </div>

        <div class="heatmap-scroll">
          <div class="heatmap-grid" :style="gridStyle">
            <div class="cell-header corner"></div>
            <div v-for="h in hours" :key="`h-${h}`" class="cell-header hour">{{ h }}</div>

            <template v-for="(dayName, dayIdx) in dayNames" :key="`row-${dayIdx}`">
              <div class="cell-header day">{{ dayName }}</div>
              <div
                v-for="hour in hours"
                :key="`d-${dayIdx}-h-${hour}`"
                class="cell"
                :class="{ empty: !grid[dayIdx][hour] }"
                :style="cellStyle(grid[dayIdx][hour])"
                :title="cellTitle(dayName, hour, grid[dayIdx][hour])"
              >
                <span v-if="grid[dayIdx][hour]" class="cell-value">{{ Math.round(grid[dayIdx][hour].score) }}</span>
              </div>
            </template>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, inject } from 'vue'
import api, { analytics } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'
import EmotionChart from './EmotionChart.vue'
import GazeChart    from './GazeChart.vue'

const { from, to } = useAnalyticsFilters()

// ── Emotion / gaze (school-wide) ────────────────────────────────
const emotionsData = ref({
  emotions: {},
  gaze: { on_board: 0, right: 0, left: 0, unknown: 0 },
  total_snapshots: 0,
})
const emotionsLoading = ref(false)
const emotionsError   = ref(false)

async function loadEmotions() {
  emotionsLoading.value = true
  emotionsError.value   = false
  try {
    const { data } = await analytics.emotions({ from: from.value, to: to.value })
    emotionsData.value = {
      emotions: data.emotions || {},
      gaze:     data.gaze     || { on_board: 0, right: 0, left: 0, unknown: 0 },
      total_snapshots: Number(data.total_snapshots) || 0,
    }
  } catch (e) {
    console.warn('emotions load failed', e)
    emotionsError.value = true
  } finally {
    emotionsLoading.value = false
  }
}

// ── Heatmap (per-class) ─────────────────────────────────────────
const classrooms          = ref([])
const selectedClassroomId = ref('')
const loadingClassrooms   = ref(false)

const cells     = ref([])
const dayNames  = ref(['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'])
const loadingHeatmap = ref(false)
const heatmapError   = ref(false)

const hours = Array.from({ length: 24 }, (_, i) => i)

const gridStyle = computed(() => ({
  gridTemplateColumns: `60px repeat(24, minmax(28px, 1fr))`,
}))

const grid = computed(() => {
  const g = Array.from({ length: 7 }, () => ({}))
  for (const c of cells.value) {
    if (c.day >= 0 && c.day <= 6 && c.hour >= 0 && c.hour <= 23) {
      g[c.day][c.hour] = { score: Number(c.score), points: Number(c.points) }
    }
  }
  return g
})

function cellStyle(cell) {
  if (!cell) return {}
  const t = Math.max(0, Math.min(1, cell.score / 100))
  const r = t < 0.5 ? lerp(239, 245, t * 2) : lerp(245,  16, (t - 0.5) * 2)
  const g = t < 0.5 ? lerp( 68, 158, t * 2) : lerp(158, 185, (t - 0.5) * 2)
  const b = t < 0.5 ? lerp( 68,  11, t * 2) : lerp( 11, 129, (t - 0.5) * 2)
  return {
    background: `rgba(${r}, ${g}, ${b}, ${0.35 + 0.55 * t})`,
    color: t > 0.4 ? '#0a0e1a' : '#f1f5f9',
  }
}

function lerp(a, b, t) {
  return Math.round(a + (b - a) * t)
}

function cellTitle(day, hour, cell) {
  if (!cell) return `${day} ${hour}:00 — нет данных`
  return `${day} ${hour}:00 — ${cell.score.toFixed(1)}% (${cell.points} точек)`
}

async function loadClassrooms() {
  loadingClassrooms.value = true
  try {
    const { data } = await api.get('/classrooms')
    classrooms.value = data.data || []
    if (classrooms.value.length && !selectedClassroomId.value) {
      selectedClassroomId.value = classrooms.value[0].id
    }
  } catch (e) {
    console.warn('classrooms load failed', e)
  } finally {
    loadingClassrooms.value = false
  }
}

async function loadHeatmap() {
  if (!selectedClassroomId.value) {
    cells.value = []
    return
  }
  loadingHeatmap.value = true
  heatmapError.value   = false
  try {
    const { data } = await analytics.heatmap(selectedClassroomId.value, {
      from: from.value, to: to.value,
    })
    cells.value = Array.isArray(data?.data) ? data.data : []
    if (Array.isArray(data?.days) && data.days.length === 7) {
      dayNames.value = data.days
    }
  } catch (e) {
    console.warn('heatmap load failed', e)
    heatmapError.value = true
    cells.value = []
  } finally {
    loadingHeatmap.value = false
  }
}

const refreshTrigger = inject('analyticsRefreshTrigger', ref(0))

onMounted(() => {
  loadEmotions()
  loadClassrooms()
})

watch(selectedClassroomId, loadHeatmap)
watch([from, to], () => {
  loadEmotions()
  if (selectedClassroomId.value) loadHeatmap()
})
watch(refreshTrigger, () => {
  loadEmotions()
  if (selectedClassroomId.value) loadHeatmap()
})
</script>

<style scoped>
.heatmap-tab { display:flex; flex-direction:column; gap:16px; }

.dual {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
@media (max-width: 900px) {
  .dual { grid-template-columns: 1fr; }
}

.heatmap-card {
  background:rgba(255,255,255,0.03);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  padding:16px 18px;
}
.heatmap-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  gap: 16px;
  flex-wrap: wrap;
}
.heatmap-header h3 { font-size: 14px; font-weight: 600; color: #e2e8f0; margin: 0; }

.cl-label { display:flex; align-items:center; gap:8px; font-size:12px; color:#94a3b8; }
.cl-label select {
  padding:8px 12px; background:rgba(255,255,255,0.05);
  border:1px solid rgba(255,255,255,0.1); border-radius:8px;
  color:#f1f5f9; font-size:13px; font-family:inherit; min-width:240px;
  color-scheme: dark;
}
.cl-label select:focus { outline:none; border-color:#6366f1; }
.cl-label select option { background:#0d1220; }

.state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 20px; gap:12px; color:#94a3b8; text-align:center; }
.state.error { color:#fca5a5; }
.state.empty .empty-icon { font-size:40px; }
.state h3 { margin:0; font-size:16px; color:#e2e8f0; }
.state p { margin:0; font-size:13px; color:#94a3b8; }
.retry { padding:8px 16px; background:rgba(99,102,241,0.15); color:#a5b4fc; border:1px solid rgba(99,102,241,0.3); border-radius:8px; cursor:pointer; font-size:12px; font-family:inherit; }
.retry:hover { background:rgba(99,102,241,0.25); }
.spinner { width:28px; height:28px; border:3px solid rgba(255,255,255,0.08); border-top-color:#6366f1; border-radius:50%; animation:spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.legend { display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:11px; color:#94a3b8; }
.legend-gradient {
  width:160px; height:10px; border-radius:5px;
  background: linear-gradient(90deg,
    rgba(239,68,68,0.7) 0%,
    rgba(245,158,11,0.7) 50%,
    rgba(16,185,129,0.85) 100%
  );
}

.heatmap-scroll { overflow-x:auto; }
.heatmap-grid { display:grid; gap:2px; min-width:720px; }

.cell-header {
  display:flex; align-items:center; justify-content:center;
  font-size:10px; color:#64748b; padding:4px 0;
}
.cell-header.day { justify-content:flex-end; padding-right:8px; color:#94a3b8; font-weight:500; font-size:11px; }
.cell-header.hour { font-variant-numeric: tabular-nums; }
.cell-header.corner { background:transparent; }

.cell {
  height:32px;
  border-radius:4px;
  display:flex; align-items:center; justify-content:center;
  font-size:10px; font-weight:600;
  background:rgba(255,255,255,0.03);
  transition: transform 0.1s;
}
.cell:hover { transform: scale(1.08); cursor:default; }
.cell.empty { background:rgba(255,255,255,0.025); }
.cell-value { font-variant-numeric: tabular-nums; }
</style>
