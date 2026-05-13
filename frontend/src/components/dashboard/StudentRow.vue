<template>
  <tr class="student-row" :class="[{ absent: !faceDetected }, `level-${level}`]">
    <td class="col-status">
      <span class="dot" :class="`level-${level}`"></span>
    </td>

    <td class="col-name">
      <div class="name-wrap">
        <span class="name-text">{{ displayName }}</span>
        <span v-if="code" class="code">{{ code }}</span>
      </div>
    </td>

    <td class="col-engagement">
      <div class="engagement-wrap">
        <div class="bar">
          <div class="bar-fill" :class="`level-${level}`" :style="{ width: barWidth }"></div>
        </div>
        <span class="score" :class="`level-${level}`">
          <template v-if="faceDetected">{{ Math.round(score) }}%</template>
          <template v-else>—</template>
        </span>
      </div>
    </td>

    <td class="col-emotion">
      <span class="emotion" :title="emotionLabel">{{ emotionEmoji }}</span>
    </td>

    <td class="col-gaze">
      <span class="gaze" :title="gazeLabel">{{ gazeText }}</span>
    </td>
  </tr>
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
  const v = score.value
  if (v >= 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
})

const barWidth = computed(() => {
  if (!faceDetected.value) return '0%'
  const v = Math.max(0, Math.min(100, score.value))
  return `${v}%`
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

// Emotion mapping per spec
const EMOTION_EMOJI = {
  neutral:   '😐',
  calm:      '😐',
  happy:     '😊',
  joy:       '😊',
  positive:  '😊',
  sad:       '😢',
  sadness:   '😢',
  angry:     '😠',
  anger:     '😠',
  surprised: '😮',
  surprise:  '😮',
  fear:      '😨',
  fearful:   '😨',
  disgust:   '🤢',
  disgusted: '🤢',
  confused:  '🤔',
}
const EMOTION_LABEL = {
  neutral:   'Нейтрально',
  calm:      'Спокойствие',
  happy:     'Радость',
  joy:       'Радость',
  positive:  'Позитив',
  sad:       'Грусть',
  sadness:   'Грусть',
  angry:     'Злость',
  anger:     'Злость',
  surprised: 'Удивление',
  surprise:  'Удивление',
  fear:      'Тревога',
  fearful:   'Тревога',
  disgust:   'Отвращение',
  disgusted: 'Отвращение',
  confused:  'Смущение',
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

const gazeText = computed(() => {
  if (!faceDetected.value) return '—'
  if (props.student.gaze_on_board === true)  return '👁 на доске'
  if (props.student.gaze_on_board === false) return '➜ в сторону'
  return '—'
})

const gazeLabel = computed(() => {
  if (!faceDetected.value) return 'Лицо не найдено'
  if (props.student.gaze_on_board === true)  return 'Смотрит на доску'
  if (props.student.gaze_on_board === false) return 'Смотрит в сторону'
  return 'Направление взгляда неизвестно'
})
</script>

<style scoped>
.student-row {
  background: transparent;
  transition: background 0.2s ease;
}
.student-row:hover { background: rgba(255,255,255,0.04); }
.student-row.absent { opacity: 0.55; }

.student-row td {
  padding: 10px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
  color: #e2e8f0;
  font-size: 13.5px;
  vertical-align: middle;
}

.col-status { width: 44px; }
.dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #475569;
  transition: background 0.3s ease, box-shadow 0.3s ease;
}
.dot.level-high   { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,0.6); }
.dot.level-medium { background: #f59e0b; box-shadow: 0 0 8px rgba(245,158,11,0.6); }
.dot.level-low    { background: #ef4444; box-shadow: 0 0 8px rgba(239,68,68,0.6); }
.dot.level-unknown { background: #475569; }

.col-name { min-width: 160px; }
.name-wrap { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.name-text {
  font-weight: 500;
  color: #f1f5f9;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.code { font-size: 11px; color: #64748b; }

.col-engagement { min-width: 220px; }
.engagement-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
}
.bar {
  flex: 1;
  height: 8px;
  background: rgba(255,255,255,0.1);
  border-radius: 999px;
  overflow: hidden;
  min-width: 80px;
}
.bar-fill {
  height: 100%;
  width: 0%;
  background: #475569;
  border-radius: 999px;
  transition: width 0.3s ease, background-color 0.3s ease;
}
.bar-fill.level-high    { background: #22c55e; }
.bar-fill.level-medium  { background: #f59e0b; }
.bar-fill.level-low     { background: #ef4444; }
.bar-fill.level-unknown { background: #475569; }

.score {
  min-width: 42px;
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 13px;
  color: #94a3b8;
  transition: color 0.3s ease;
}
.score.level-high   { color: #22c55e; }
.score.level-medium { color: #f59e0b; }
.score.level-low    { color: #ef4444; }

.col-emotion { width: 60px; text-align: center; }
.emotion { font-size: 18px; line-height: 1; }

.col-gaze { width: 120px; white-space: nowrap; color: #cbd5e1; }
.gaze { font-size: 13px; }
</style>
