<template>
  <button
    class="history-card"
    :class="`level-${level}`"
    @click="$emit('click', session)"
    type="button"
  >
    <div class="card-top">
      <h3 class="card-title">
        {{ classroomName }}
        <span v-if="subject" class="card-subject">• {{ subject }}</span>
      </h3>
      <div class="card-avg" :class="`level-${level}`">
        <span class="avg-dot" :class="`level-${level}`"></span>
        {{ avgDisplay }}%
      </div>
    </div>

    <div class="card-line card-time">
      {{ dateTimeLabel }}
    </div>

    <div class="card-line card-meta">
      <span>Длительность: <b>{{ durationLabel }}</b></span>
      <span class="dot-sep">•</span>
      <span>Студентов: <b>{{ studentsCount }}</b></span>
    </div>
  </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  session: { type: Object, required: true },
})
defineEmits(['click'])

const classroomName = computed(
  () => props.session.classroom_name || props.session.classroom?.name || 'Класс'
)
const subject = computed(() => props.session.subject || '')

const avgValue = computed(() => {
  const v = Number(props.session.avg_engagement_score)
  return Number.isFinite(v) ? v : 0
})
const avgDisplay = computed(() => {
  if (!avgValue.value) return '—'
  return Math.round(avgValue.value)
})
const level = computed(() => {
  const v = avgValue.value
  if (!v) return 'unknown'
  if (v > 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
})

const studentsCount = computed(() => {
  const n = Number(props.session.students_count) || 0
  return n
})

const durationLabel = computed(() => {
  const m = Number(props.session.duration_minutes)
  if (!m || !Number.isFinite(m)) {
    // Fallback: compute from started/ended_at
    if (props.session.started_at && props.session.ended_at) {
      const ms = new Date(props.session.ended_at) - new Date(props.session.started_at)
      const mins = Math.max(0, Math.round(ms / 60000))
      return formatMinutes(mins)
    }
    return '—'
  }
  return formatMinutes(Math.round(m))
})

function formatMinutes(mins) {
  if (mins < 60) return `${mins} мин`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return `${h} ч ${m} мин`
}

const dateTimeLabel = computed(() => {
  const startedAt = props.session.started_at ? new Date(props.session.started_at) : null
  const endedAt   = props.session.ended_at   ? new Date(props.session.ended_at)   : null
  if (!startedAt) return '—'

  const date = startedAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
  const start = startedAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  if (endedAt) {
    const end = endedAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    return `${date}, ${start} – ${end}`
  }
  return `${date}, ${start}`
})
</script>

<style scoped>
.history-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
  text-align: left;
  background: #1e293b;
  border: 1.5px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  padding: 14px 18px;
  cursor: pointer;
  font-family: inherit;
  color: #e2e8f0;
  transition: all 0.2s ease;
}
.history-card:hover {
  background: #233146;
  transform: translateY(-1px);
  box-shadow: 0 8px 20px rgba(0,0,0,0.25);
}
.history-card.level-high   { border-left: 3px solid #22c55e; }
.history-card.level-medium { border-left: 3px solid #f59e0b; }
.history-card.level-low    { border-left: 3px solid #ef4444; }
.history-card.level-unknown { border-left: 3px solid rgba(255,255,255,0.12); }

.card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.card-title {
  margin: 0;
  font-size: 15.5px;
  font-weight: 600;
  color: #f1f5f9;
  flex: 1;
  min-width: 0;
}
.card-subject {
  color: #94a3b8;
  font-weight: 400;
  margin-left: 4px;
}

.card-avg {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 600;
  color: #94a3b8;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  transition: color 0.3s ease;
}
.card-avg.level-high   { color: #22c55e; }
.card-avg.level-medium { color: #f59e0b; }
.card-avg.level-low    { color: #ef4444; }

.avg-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #475569;
}
.avg-dot.level-high   { background: #22c55e; }
.avg-dot.level-medium { background: #f59e0b; }
.avg-dot.level-low    { background: #ef4444; }

.card-line {
  font-size: 13px;
  color: #94a3b8;
}
.card-time { color: #cbd5e1; font-variant-numeric: tabular-nums; }
.card-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #64748b;
}
.card-meta b { color: #cbd5e1; font-weight: 600; }
.dot-sep { color: #475569; }
</style>
