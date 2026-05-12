<template>
  <div class="session-card" @click="$emit('click', session)">
    <div class="card-header">
      <div class="card-info">
        <h3 class="card-title">{{ session.classroom_name || session.classroom?.name || 'Класс' }}</h3>
        <div class="card-meta">
          <span v-if="subject" class="card-subject">{{ subject }}</span>
          <span v-if="teacherName" class="card-teacher">· {{ teacherName }}</span>
          <span class="card-duration">· {{ durationLabel }}</span>
        </div>
      </div>

      <div class="card-score-wrap">
        <div class="card-score" :class="scoreLevel">
          <span class="score-num">{{ scoreDisplay }}</span><span class="score-pct">%</span>
        </div>
        <div class="card-score-label">сейчас</div>
      </div>
    </div>

    <div class="kpi-row">
      <div class="kpi">
        <div class="kpi-value">{{ detectedCount }}</div>
        <div class="kpi-label">обнаружено</div>
      </div>
      <div class="kpi">
        <div class="kpi-value">{{ session.students_count || studentList.length }}</div>
        <div class="kpi-label">в классе</div>
      </div>
      <div class="kpi">
        <div class="kpi-value success">{{ highCount }}</div>
        <div class="kpi-label">высокая</div>
      </div>
      <div class="kpi">
        <div class="kpi-value danger">{{ lowCount }}</div>
        <div class="kpi-label">низкая</div>
      </div>
    </div>

    <div class="chart-wrap">
      <div class="chart-title">Последние {{ timelineTail.length || 10 }} минут</div>
      <div class="chart-box">
        <MiniChart :points="timelinePoints" :max-points="10" empty-label="Нет данных за этот урок" />
      </div>
    </div>

    <div class="students-wrap">
      <div class="students-header">
        <span>Студенты</span>
        <span class="students-count">{{ studentList.length }}</span>
      </div>

      <div v-if="studentList.length === 0" class="students-empty">
        Ожидаем данные с камеры...
      </div>

      <div v-else class="students-list">
        <StudentRow
          v-for="(s, i) in studentList"
          :key="s.student_id || i"
          :student="s"
          :index="i"
        />
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
defineEmits(['click'])

// ── Загрузка таймлайна (минутные агрегаты) ─────────────────────
const timeline = ref([])
let timelineTimer = null

async function loadTimeline() {
  if (!props.session?.id) return
  try {
    const { data } = await sessionsApi.timeline(props.session.id)
    timeline.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    // не валим карточку, если таймлайн временно недоступен
    console.warn('[SessionCard] timeline load failed', e)
  }
}

const timelineTail = computed(() => timeline.value.slice(-10))

const timelinePoints = computed(() => {
  const points = timelineTail.value.map(p => ({
    label: `+${p.minute} мин`,
    value: Number(p.avg_score) || 0,
  }))

  // Дотягиваем "хвост" текущим WS-средним, чтобы график всегда показывал свежий тренд
  if (props.classAvg > 0) {
    points.push({ label: 'сейчас', value: Number(props.classAvg) || 0 })
  }
  return points
})

// ── Студенты: имена и коды из REST + live из WS-стора ──────────
const baselineStudents = ref([])

async function loadBaselineStudents() {
  if (!props.session?.id) return
  try {
    const { data } = await sessionsApi.students(props.session.id)
    baselineStudents.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[SessionCard] students load failed', e)
  }
}

// Объединяем имена/коды из REST с живыми данными из стора
const studentList = computed(() => {
  const live = props.studentScores || {}
  const liveIds = Object.keys(live)
  const baselineById = Object.fromEntries(
    baselineStudents.value.map(s => [s.student_id, s])
  )

  if (liveIds.length === 0) {
    return baselineStudents.value.map(s => ({
      student_id: s.student_id,
      name:       s.name,
      code:       s.code,
      score:      s.avg_score,
      level:      s.level,
      face_detected: true,
      emotion:    null,
      gaze_on_board: null,
    }))
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
          || (Number(liveRow.score) >= 75 ? 'high'
            : Number(liveRow.score) >= 50 ? 'medium'
            : 'low'),
      }
    })
    .sort((a, b) => (b.score || 0) - (a.score || 0))
})

const detectedCount = computed(() =>
  studentList.value.filter(s => s.face_detected !== false).length
)
const highCount = computed(() =>
  studentList.value.filter(s => s.face_detected !== false && (s.score || 0) >= 75).length
)
const lowCount = computed(() =>
  studentList.value.filter(s => s.face_detected !== false && (s.score || 0) < 50).length
)

// ── Текущий % (приоритет: WS class_avg → последний минутный агрегат → 0) ─
const currentScore = computed(() => {
  if (props.classAvg && props.classAvg > 0) return props.classAvg
  const last = timelineTail.value[timelineTail.value.length - 1]
  if (last) return Number(last.avg_score) || 0
  return 0
})

const scoreDisplay = computed(() => {
  const v = currentScore.value
  if (!v || Number.isNaN(v)) return '—'
  return Math.round(v)
})

const scoreLevel = computed(() => {
  const v = currentScore.value
  if (!v) return 'unknown'
  if (v >= 75) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
})

// ── Шапка: предмет, учитель, продолжительность ────────────────
const subject     = computed(() => props.session.subject || '')
const teacherName = computed(() => props.session.teacher || props.session.teacher_name || '')

const startedAt = computed(() => {
  const raw = props.session.started_at
  return raw ? new Date(raw) : null
})

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

// ── Жизненный цикл ────────────────────────────────────────────
onMounted(() => {
  loadTimeline()
  loadBaselineStudents()
  // Минутный агрегат обновляется раз в минуту — перезагружаем чуть чаще
  timelineTimer = setInterval(loadTimeline, 30_000)
  // Локальный тикер чтобы продолжительность не "застывала"
  nowTimer = setInterval(() => { now.value = Date.now() }, 30_000)
})

onBeforeUnmount(() => {
  if (timelineTimer) clearInterval(timelineTimer)
  if (nowTimer)      clearInterval(nowTimer)
  timelineTimer = null
  nowTimer = null
})

// Если урок поменялся (редкий случай) — перезагрузим всё
watch(() => props.session?.id, (id, prev) => {
  if (id && id !== prev) {
    loadTimeline()
    loadBaselineStudents()
  }
})
</script>

<style scoped>
.session-card {
  background: #1e293b;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 18px 18px 14px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  cursor: pointer;
  transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
}
.session-card:hover {
  border-color: rgba(99,102,241,0.45);
  transform: translateY(-1px);
  box-shadow: 0 10px 32px rgba(0,0,0,0.35);
}

.card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.card-info { min-width: 0; }
.card-title {
  font-size: 16px;
  font-weight: 600;
  color: #f1f5f9;
  margin: 0 0 4px;
}
.card-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
}
.card-subject { color: #94a3b8; }

.card-score-wrap { text-align: right; flex-shrink: 0; }
.card-score {
  font-size: 48px;
  font-weight: 700;
  letter-spacing: -1.5px;
  line-height: 1;
  color: #94a3b8;
  transition: color 0.3s;
}
.card-score .score-num { font-variant-numeric: tabular-nums; }
.card-score .score-pct { font-size: 18px; font-weight: 500; margin-left: 2px; }
.card-score.high    { color: #22c55e; }
.card-score.medium  { color: #f59e0b; }
.card-score.low     { color: #ef4444; }
.card-score.unknown { color: #475569; }
.card-score-label {
  font-size: 11px;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-top: 4px;
}

.kpi-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}
.kpi {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 8px;
  padding: 8px 10px;
  text-align: center;
}
.kpi-value {
  font-size: 18px;
  font-weight: 700;
  color: #f1f5f9;
  font-variant-numeric: tabular-nums;
  transition: color 0.3s;
}
.kpi-value.success { color: #22c55e; }
.kpi-value.danger  { color: #ef4444; }
.kpi-label {
  font-size: 10.5px;
  color: #64748b;
  margin-top: 2px;
  text-transform: lowercase;
}

.chart-wrap {
  background: rgba(255,255,255,0.025);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
  padding: 8px 12px 10px;
}
.chart-title {
  font-size: 11px;
  color: #64748b;
  margin-bottom: 4px;
  letter-spacing: 0.02em;
}
.chart-box {
  height: 72px;
  position: relative;
}

.students-wrap {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.students-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11.5px;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.students-count {
  background: rgba(255,255,255,0.06);
  padding: 1px 8px;
  border-radius: 999px;
  font-size: 11px;
  color: #94a3b8;
  text-transform: none;
  letter-spacing: 0;
}
.students-empty {
  padding: 14px 4px;
  text-align: center;
  font-size: 12px;
  color: #475569;
  font-style: italic;
}
.students-list {
  display: flex;
  flex-direction: column;
  gap: 3px;
  max-height: 260px;
  overflow-y: auto;
}
.students-list::-webkit-scrollbar { width: 6px; }
.students-list::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,0.08);
  border-radius: 3px;
}
</style>
