<template>
  <div class="classroom-card" @click="$emit('click')">
    <div class="card-header">
      <div class="card-info">
        <h3 class="card-title">{{ session.classroom_name || session.classroom?.name }}</h3>
        <span class="card-subject">{{ session.subject || 'Урок' }}</span>
      </div>
      <div class="card-score" :class="scoreClass">
        {{ Math.round(avg) }}<span class="score-pct">%</span>
      </div>
    </div>

    <!-- Мини-сетка студентов: реально обнаруженные → серые слоты до размера группы -->
    <div class="students-grid">
      <div
        v-for="(student, i) in detectedList"
        :key="student.student_id || i"
        class="student-dot"
        :class="dotClass(student.score)"
        :title="`${student.score}% — ${student.emotion || ''}`"
      >
        <div class="dot-fill" :style="{ height: student.score + '%' }"></div>
      </div>
      <div v-for="i in emptySlots" :key="'e'+i" class="student-dot empty"></div>
    </div>

    <!-- Прогресс бар -->
    <div class="card-progress">
      <div class="progress-bar">
        <div class="progress-fill" :class="scoreClass" :style="{ width: avg + '%' }"></div>
      </div>
      <div class="card-meta">
        <span>
          <span class="meta-strong">{{ detectedCount }}</span>
          <span v-if="rosterSize"> / {{ rosterSize }}</span>
          студентов
        </span>
        <span>{{ duration }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  session:       { type: Object, required: true },
  avg:           { type: Number, default: 0 },
  studentScores: { type: Object, default: () => ({}) },
})
defineEmits(['click'])

const MAX_DOTS = 20

const detectedList = computed(() => {
  const list = Object.values(props.studentScores)
    .filter(s => s.face_detected !== false)
  return list.slice(0, MAX_DOTS)
})

const detectedCount = computed(() =>
  props.session.students_present
    ?? Object.values(props.studentScores).filter(s => s.face_detected !== false).length
)

const rosterSize = computed(() => props.session.students_count || 0)

const emptySlots = computed(() => {
  const dots = Math.min(MAX_DOTS, Math.max(detectedList.value.length, rosterSize.value))
  return Math.max(0, dots - detectedList.value.length)
})

const scoreClass = computed(() => {
  if (props.avg >= 75) return 'high'
  if (props.avg >= 50) return 'medium'
  return 'low'
})

function dotClass(score) {
  if (!score && score !== 0) return 'unknown'
  if (score >= 75) return 'high'
  if (score >= 50) return 'medium'
  return 'low'
}

const duration = computed(() => {
  if (!props.session.started_at) return ''
  const diff = Math.floor((Date.now() - new Date(props.session.started_at)) / 60000)
  return `${diff} мин`
})
</script>

<style scoped>
.classroom-card {
  background: #111827;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  padding: 20px;
  cursor: pointer;
  transition: all 0.2s;
}
.classroom-card:hover {
  border-color: rgba(99,102,241,0.4);
  background: #141d2e;
  transform: translateY(-1px);
  box-shadow: 0 8px 30px rgba(0,0,0,0.3);
}

.card-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:16px; }
.card-title { font-size:15px; font-weight:600; color:#f1f5f9; margin:0 0 3px; }
.card-subject { font-size:12px; color:#64748b; }
.card-score { font-size:28px; font-weight:700; letter-spacing:-1px; line-height:1; }
.card-score.high   { color:#22c55e; }
.card-score.medium { color:#f59e0b; }
.card-score.low    { color:#ef4444; }
.score-pct { font-size:14px; font-weight:500; }

.students-grid {
  display: grid;
  grid-template-columns: repeat(10, 1fr);
  gap: 4px;
  margin-bottom: 16px;
}

.student-dot {
  aspect-ratio: 1;
  border-radius: 4px;
  background: rgba(255,255,255,0.05);
  overflow: hidden;
  position: relative;
  display: flex;
  align-items: flex-end;
}

.dot-fill {
  width: 100%;
  min-height: 3px;
  border-radius: 3px;
  transition: height 0.5s ease;
}

.student-dot.high   .dot-fill { background: #22c55e; }
.student-dot.medium .dot-fill { background: #f59e0b; }
.student-dot.low    .dot-fill { background: #ef4444; }
.student-dot.unknown .dot-fill { background: #475569; height: 30% !important; }
.student-dot.empty  { opacity: 0.2; }

.card-progress { }
.progress-bar { height:4px; background:rgba(255,255,255,0.08); border-radius:2px; overflow:hidden; margin-bottom:10px; }
.progress-fill { height:100%; border-radius:2px; transition:width 0.5s ease; }
.progress-fill.high   { background:linear-gradient(90deg,#16a34a,#22c55e); }
.progress-fill.medium { background:linear-gradient(90deg,#d97706,#f59e0b); }
.progress-fill.low    { background:linear-gradient(90deg,#dc2626,#ef4444); }

.card-meta { display:flex; justify-content:space-between; font-size:11px; color:#475569; }
.meta-strong { color:#cbd5e1; font-weight:600; }
</style>
