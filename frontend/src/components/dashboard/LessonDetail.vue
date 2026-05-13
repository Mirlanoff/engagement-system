<template>
  <div class="lesson-detail" :class="`level-${level}`">
    <!-- ── Шапка ───────────────────────────────────────────── -->
    <div class="detail-header">
      <div class="header-left">
        <h3 class="header-title">
          {{ classroomName }}
          <span v-if="subject" class="header-subject">• {{ subject }}</span>
        </h3>
        <div class="header-meta">
          <span class="meta-live"><span class="live-dot"></span>Live</span>
          <span v-if="teacherName" class="meta-item">Учитель: {{ teacherName }}</span>
          <span class="meta-item">{{ durationLabel }}</span>
        </div>
      </div>
      <div class="header-right">
        <div class="header-avg" :class="`level-${level}`">
          Средняя: <span class="avg-num">{{ avgDisplay }}%</span>
        </div>
        <button class="collapse-btn" @click="$emit('collapse')">
          <span class="x">✕</span> Свернуть
        </button>
      </div>
    </div>

    <!-- ── Мини-график ─────────────────────────────────────── -->
    <div class="chart-section">
      <div class="chart-title">Последние 10 минут</div>
      <div class="chart-box">
        <MiniChart
          :points="chartPoints"
          :max-points="10"
          empty-label="Нет данных за этот урок"
        />
      </div>
    </div>

    <!-- ── Таблица студентов ───────────────────────────────── -->
    <div class="students-section">
      <div class="students-title">
        <span>Студенты <span class="muted">(обновляется каждые 5 сек)</span></span>
        <span class="students-count">{{ studentList.length }}</span>
      </div>

      <div v-if="studentList.length === 0" class="students-empty">
        Ожидаем данные с камеры...
      </div>

      <div v-else class="students-table-wrap">
        <table class="students-table">
          <thead>
            <tr>
              <th class="col-status">Статус</th>
              <th class="col-name">Имя</th>
              <th class="col-engagement">Вовлечённость</th>
              <th class="col-emotion">Эмоция</th>
              <th class="col-gaze">Взгляд</th>
            </tr>
          </thead>
          <tbody>
            <StudentRow
              v-for="(s, i) in studentList"
              :key="s.student_id || i"
              :student="s"
              :index="i"
            />
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { sessions as sessionsApi } from '@/api'
import MiniChart  from './MiniChart.vue'
import StudentRow from './StudentRow.vue'

const props = defineProps({
  session:       { type: Object, required: true },
  classAvg:      { type: Number, default: 0 },
  studentScores: { type: Object, default: () => ({}) },
})
defineEmits(['collapse'])

// ── Хедер ────────────────────────────────────────────────────
const classroomName = computed(
  () => props.session.classroom_name || props.session.classroom?.name || 'Класс'
)
const subject = computed(() => props.session.subject || '')
const teacherName = computed(
  () => props.session.teacher || props.session.teacher_name || ''
)

const avgDisplay = computed(() => {
  const v = Number(props.classAvg) || 0
  if (!v || Number.isNaN(v)) return 0
  return Math.round(v)
})
const level = computed(() => {
  const v = avgDisplay.value
  if (!v) return 'unknown'
  if (v > 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
})

// ── Длительность урока ───────────────────────────────────────
const startedAt = computed(() =>
  props.session.started_at ? new Date(props.session.started_at) : null
)
const now = ref(Date.now())
let nowTimer = null

const durationLabel = computed(() => {
  if (!startedAt.value) return ''
  const mins = Math.max(0, Math.floor((now.value - startedAt.value.getTime()) / 60000))
  if (mins < 1)  return 'только что'
  if (mins < 60) return `${mins} мин`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return `${h} ч ${m} мин`
})

// ── Студенты ─────────────────────────────────────────────────
// Имена тянем 1 раз через REST, кешируем — имена не меняются за урок.
const baselineStudents = ref([])

async function loadBaselineStudents() {
  if (!props.session?.id) return
  try {
    const { data } = await sessionsApi.students(props.session.id)
    baselineStudents.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[LessonDetail] students load failed', e)
  }
}

// Сортировка по score ASC (самый невнимательный сверху)
const studentList = computed(() => {
  const live = props.studentScores || {}
  const liveIds = Object.keys(live)
  const baselineById = Object.fromEntries(
    baselineStudents.value.map(s => [s.student_id, s])
  )

  // Если живых данных ещё нет — показываем baseline (если есть)
  if (liveIds.length === 0) {
    return baselineStudents.value
      .map(s => ({
        student_id:    s.student_id,
        name:          s.name,
        code:          s.code,
        score:         s.avg_score || 0,
        level:         s.level,
        face_detected: false,
        emotion:       null,
        gaze_on_board: null,
      }))
      .sort((a, b) => (a.score || 0) - (b.score || 0))
  }

  return liveIds
    .map(id => {
      const liveRow = live[id] || {}
      const base    = baselineById[id] || {}
      return {
        student_id:    id,
        name:          base.name || liveRow.name || null,
        code:          base.code || null,
        score:         Number(liveRow.score) || 0,
        emotion:       liveRow.emotion ?? null,
        gaze_on_board: liveRow.gaze_on_board ?? null,
        face_detected: liveRow.face_detected !== false,
        level:         liveRow.level
          || (Number(liveRow.score) >= 70 ? 'high'
            : Number(liveRow.score) >= 50 ? 'medium'
            : 'low'),
      }
    })
    // Самый низкий балл — сверху (учителю сразу видно, кому нужно внимание)
    .sort((a, b) => (a.score || 0) - (b.score || 0))
})

// ── Таймлайн для мини-графика ────────────────────────────────
const timeline = ref([])
let timelineTimer = null
const lastAvgPushedMinute = ref(null)

async function loadTimeline() {
  if (!props.session?.id) return
  try {
    const { data } = await sessionsApi.timeline(props.session.id)
    timeline.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[LessonDetail] timeline load failed', e)
  }
}

// Из WebSocket class_avg раз в минуту добавляем точку — чтобы график "жил".
watch(
  () => props.classAvg,
  (v) => {
    if (!v || v <= 0) return
    const currentMinute = Math.floor(Date.now() / 60_000)
    if (lastAvgPushedMinute.value !== currentMinute) {
      lastAvgPushedMinute.value = currentMinute
      timeline.value = [...timeline.value, {
        minute:    timeline.value.length,
        avg_score: Number(v),
      }]
    }
  }
)

const chartPoints = computed(() => {
  const tail = timeline.value.slice(-10)
  return tail.map((p, i) => ({
    label: `+${p.minute ?? i} мин`,
    value: Number(p.avg_score) || 0,
  }))
})

// ── Жизненный цикл ───────────────────────────────────────────
onMounted(() => {
  loadBaselineStudents()
  loadTimeline()
  // Перезагружаем таймлайн каждые 30 секунд (минутные агрегаты появляются с лагом)
  timelineTimer = setInterval(loadTimeline, 30_000)
  nowTimer      = setInterval(() => { now.value = Date.now() }, 30_000)
})

onBeforeUnmount(() => {
  if (timelineTimer) clearInterval(timelineTimer)
  if (nowTimer)      clearInterval(nowTimer)
  timelineTimer = null
  nowTimer      = null
})

watch(() => props.session?.id, (id, prev) => {
  if (id && id !== prev) {
    loadBaselineStudents()
    loadTimeline()
    lastAvgPushedMinute.value = null
  }
})
</script>

<style scoped>
.lesson-detail {
  background: #1e293b;
  border: 2px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  transition: border-color 0.3s ease;
}
.lesson-detail.level-high    { border-color: #22c55e; }
.lesson-detail.level-medium  { border-color: #f59e0b; }
.lesson-detail.level-low     { border-color: #ef4444; }
.lesson-detail.level-unknown { border-color: rgba(255,255,255,0.12); }

/* ── Header ───────────────────────────────────────────────── */
.detail-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.header-left { min-width: 0; flex: 1; }
.header-title {
  font-size: 18px;
  font-weight: 600;
  color: #f1f5f9;
  margin: 0 0 6px;
}
.header-subject { color: #94a3b8; font-weight: 400; }
.header-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 12.5px;
  color: #94a3b8;
}
.meta-live {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: #ef4444;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.live-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ef4444;
  animation: pulse 1.4s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%      { opacity: 0.45; transform: scale(0.85); }
}

.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}
.header-avg {
  font-size: 14px;
  font-weight: 500;
  color: #94a3b8;
  transition: color 0.3s ease;
}
.header-avg .avg-num {
  font-size: 20px;
  font-weight: 700;
  margin-left: 4px;
  font-variant-numeric: tabular-nums;
}
.header-avg.level-high    { color: #22c55e; }
.header-avg.level-medium  { color: #f59e0b; }
.header-avg.level-low     { color: #ef4444; }
.header-avg.level-unknown { color: #94a3b8; }

.collapse-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: #cbd5e1;
  cursor: pointer;
  font-size: 12.5px;
  font-family: inherit;
  transition: all 0.2s ease;
}
.collapse-btn:hover {
  background: rgba(255,255,255,0.08);
  color: #f1f5f9;
}
.collapse-btn .x { font-size: 14px; line-height: 1; }

/* ── Chart ────────────────────────────────────────────────── */
.chart-section {
  background: rgba(255,255,255,0.025);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
  padding: 10px 14px 12px;
}
.chart-title {
  font-size: 11px;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
}
.chart-box {
  height: 120px;
}

/* ── Students table ───────────────────────────────────────── */
.students-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.students-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  color: #cbd5e1;
}
.students-title .muted { color: #64748b; font-weight: 400; }
.students-count {
  background: rgba(255,255,255,0.06);
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  color: #94a3b8;
  font-variant-numeric: tabular-nums;
}

.students-empty {
  padding: 24px 4px;
  text-align: center;
  font-size: 13px;
  color: #475569;
  font-style: italic;
}

.students-table-wrap {
  max-height: 360px;
  overflow-y: auto;
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
}
.students-table-wrap::-webkit-scrollbar { width: 8px; }
.students-table-wrap::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,0.1);
  border-radius: 4px;
}

.students-table {
  width: 100%;
  border-collapse: collapse;
}
.students-table thead th {
  position: sticky;
  top: 0;
  background: #1a2436;
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 10px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
  z-index: 1;
}
.students-table thead .col-status     { width: 64px; }
.students-table thead .col-engagement { width: 240px; }
.students-table thead .col-emotion    { width: 70px; text-align: center; }
.students-table thead .col-gaze       { width: 140px; }

@media (max-width: 900px) {
  .detail-header { flex-direction: column; align-items: stretch; }
  .header-right { justify-content: space-between; }
  .students-table thead .col-engagement { width: 180px; }
  .students-table thead .col-gaze { width: 110px; }
}
</style>
