<template>
  <div
    class="session-card"
    :class="[`level-${level}`, { expanded }]"
    @click="$emit('click', session)"
  >
    <div class="card-row card-top">
      <h3 class="card-title">{{ classroomName }}</h3>
      <div class="card-live">
        <span class="live-dot"></span>Live
      </div>
    </div>

    <div class="card-row card-teacher">
      <span class="label">Учитель:</span>
      <span class="value">{{ teacherName || '—' }}</span>
    </div>

    <div class="card-row card-avg">
      <span class="label">Средняя вовлечённость:</span>
      <span class="value avg-value" :class="`level-${level}`">{{ avgDisplay }}%</span>
    </div>

    <div class="card-row card-online">
      <span class="label">Студентов онлайн:</span>
      <span class="value">{{ onlineCount }}</span>
    </div>

    <div class="card-row card-duration">
      <span class="label">Длительность:</span>
      <span class="value">{{ durationLabel }}</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  session:       { type: Object, required: true },
  classAvg:      { type: Number, default: 0 },
  studentScores: { type: Object, default: () => ({}) },
  expanded:      { type: Boolean, default: false },
})
defineEmits(['click'])

const classroomName = computed(
  () => props.session.classroom_name || props.session.classroom?.name || 'Класс'
)
const teacherName = computed(
  () => props.session.teacher || props.session.teacher_name || ''
)

const avgDisplay = computed(() => {
  const v = Number(props.classAvg) || Number(props.session.avg_engagement_score) || 0
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

const onlineCount = computed(() => {
  const scores = props.studentScores || {}
  let count = 0
  for (const id of Object.keys(scores)) {
    if (scores[id]?.face_detected !== false) count++
  }
  return count
})

const startedAt = computed(() =>
  props.session.started_at ? new Date(props.session.started_at) : null
)

const now = ref(Date.now())
let nowTimer = null

const durationLabel = computed(() => {
  if (!startedAt.value) return '—'
  const mins = Math.max(0, Math.floor((now.value - startedAt.value.getTime()) / 60000))
  if (mins < 1) return 'только что'
  if (mins < 60) return `${mins} мин`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return `${h} ч ${m} мин`
})

onMounted(() => {
  nowTimer = setInterval(() => { now.value = Date.now() }, 30_000)
})
onBeforeUnmount(() => {
  if (nowTimer) clearInterval(nowTimer)
  nowTimer = null
})
</script>

<style scoped>
.session-card {
  background: #1e293b;
  border: 2px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  cursor: pointer;
  transition: all 0.3s ease;
}
.session-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 32px rgba(0,0,0,0.35);
  background: #243349;
}
.session-card.level-high    { border-color: #22c55e; }
.session-card.level-medium  { border-color: #f59e0b; }
.session-card.level-low     { border-color: #ef4444; }
.session-card.level-unknown { border-color: rgba(255,255,255,0.08); }

.card-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 13px;
  color: #cbd5e1;
}
.card-row .label { color: #94a3b8; }
.card-row .value { color: #e2e8f0; font-weight: 500; font-variant-numeric: tabular-nums; }

.card-top {
  margin-bottom: 4px;
}
.card-title {
  font-size: 18px;
  font-weight: 600;
  color: #f1f5f9;
  margin: 0;
}
.card-live {
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

.avg-value { font-size: 14px; font-weight: 700; transition: color 0.3s ease; }
.avg-value.level-high    { color: #22c55e; }
.avg-value.level-medium  { color: #f59e0b; }
.avg-value.level-low     { color: #ef4444; }
.avg-value.level-unknown { color: #94a3b8; }
</style>
