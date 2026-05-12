<template>
  <div class="student-row" :class="{ absent: !faceDetected }">
    <span class="dot" :class="level"></span>

    <div class="name">
      <span class="name-text">{{ displayName }}</span>
      <span v-if="code" class="code">{{ code }}</span>
    </div>

    <span class="emotion" :title="emotionLabel">{{ emotionEmoji }}</span>

    <span class="gaze" :title="gazeLabel">{{ gazeIcon }}</span>

    <span class="score" :class="level">
      <template v-if="faceDetected">{{ Math.round(score) }}%</template>
      <template v-else>—</template>
    </span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  student: { type: Object, required: true },
  index:   { type: Number, default: 0 },
})

const score = computed(() => Number(props.student.score) || 0)
const faceDetected = computed(() => props.student.face_detected !== false)

const level = computed(() => {
  if (!faceDetected.value) return 'unknown'
  if (props.student.level) return props.student.level
  if (score.value >= 75) return 'high'
  if (score.value >= 50) return 'medium'
  return 'low'
})

const displayName = computed(() => {
  if (props.student.name) return props.student.name
  if (props.student.student_id) {
    const tail = String(props.student.student_id).slice(-4)
    return `Лицо ${tail}`
  }
  return `Лицо ${props.index + 1}`
})

const code = computed(() => props.student.code || '')

const EMOTION_EMOJI = {
  happy:    '😊',
  joy:      '😊',
  positive: '😊',
  neutral:  '😐',
  calm:     '😐',
  surprise: '😮',
  surprised:'😮',
  fear:     '😟',
  sad:      '😟',
  sadness:  '😟',
  angry:    '😠',
  anger:    '😠',
  disgust:  '🤢',
  confused: '🤔',
}
const EMOTION_LABEL = {
  happy:    'Радость',
  joy:      'Радость',
  positive: 'Позитив',
  neutral:  'Нейтрально',
  calm:     'Спокойствие',
  surprise: 'Удивление',
  surprised:'Удивление',
  fear:     'Тревога',
  sad:      'Грусть',
  sadness:  'Грусть',
  angry:    'Злость',
  anger:    'Злость',
  disgust:  'Отвращение',
  confused: 'Смущение',
}

const emotionEmoji = computed(() => {
  if (!faceDetected.value) return '•'
  const e = (props.student.emotion || '').toLowerCase()
  return EMOTION_EMOJI[e] || '😐'
})

const emotionLabel = computed(() => {
  if (!faceDetected.value) return 'Лицо не найдено'
  const e = (props.student.emotion || '').toLowerCase()
  return EMOTION_LABEL[e] || (props.student.emotion ? props.student.emotion : 'Нейтрально')
})

const gazeIcon = computed(() => {
  if (!faceDetected.value) return '·'
  if (props.student.gaze_on_board === true)  return '👁'
  if (props.student.gaze_on_board === false) return '↗'
  return '👁'
})

const gazeLabel = computed(() => {
  if (!faceDetected.value) return ''
  if (props.student.gaze_on_board === true)  return 'Смотрит на доску'
  if (props.student.gaze_on_board === false) return 'Смотрит в сторону'
  return 'Направление взгляда неизвестно'
})
</script>

<style scoped>
.student-row {
  display: grid;
  grid-template-columns: 10px minmax(0, 1fr) auto auto 48px;
  align-items: center;
  gap: 10px;
  padding: 7px 10px;
  border-radius: 8px;
  font-size: 13px;
  color: #e2e8f0;
  background: rgba(255,255,255,0.025);
  transition: background 0.2s;
}
.student-row:hover { background: rgba(255,255,255,0.05); }
.student-row.absent { opacity: 0.55; }

.dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #475569;
  flex-shrink: 0;
  transition: background 0.3s;
}
.dot.high   { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.55); }
.dot.medium { background: #f59e0b; box-shadow: 0 0 6px rgba(245,158,11,0.55); }
.dot.low    { background: #ef4444; box-shadow: 0 0 6px rgba(239,68,68,0.55); }
.dot.unknown { background: #475569; }

.name {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.name-text {
  font-weight: 500;
  color: #f1f5f9;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.code { font-size: 11px; color: #64748b; }

.emotion { font-size: 16px; line-height: 1; }
.gaze    { font-size: 14px; line-height: 1; color: #94a3b8; }

.score {
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 13px;
  color: #94a3b8;
  transition: color 0.3s;
}
.score.high   { color: #22c55e; }
.score.medium { color: #f59e0b; }
.score.low    { color: #ef4444; }
</style>
