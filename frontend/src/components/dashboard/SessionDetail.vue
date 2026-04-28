<template>
  <div class="session-detail">
    <div class="detail-header">
      <button class="back-btn" @click="$emit('back')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5m0 0l7 7m-7-7l7-7"/></svg>
        Назад
      </button>
      <div class="session-info">
        <h2>{{ session.classroom?.name }}</h2>
        <span class="session-subject">{{ session.subject }}</span>
      </div>
      <div class="session-avg" :class="avgClass">{{ Math.round(avg) }}%</div>
    </div>

    <div class="detail-body">
      <!-- Левая колонка: сетка студентов -->
      <div class="students-section">
        <div class="section-title">Студенты • {{ studentList.length }}</div>
        <div class="students-grid">
          <div
            v-for="student in studentList"
            :key="student.student_id"
            class="student-card"
            :class="levelClass(student.score)"
          >
            <div class="student-score">{{ Math.round(student.score) }}</div>
            <div class="student-bar">
              <div class="bar-fill" :style="{ width: student.score + '%' }"></div>
            </div>
            <div class="student-emotion">{{ emotionEmoji(student.emotion) }}</div>
            <div class="student-gaze">
              <span v-if="student.gaze_on_board" class="gaze-icon on">👁</span>
              <span v-else class="gaze-icon off">👁</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Правая колонка: статистика -->
      <div class="stats-section">
        <div class="stat-card">
          <div class="stat-label">Средний балл</div>
          <div class="stat-value" :class="avgClass">{{ Math.round(avg) }}%</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Смотрят на доску</div>
          <div class="stat-value">{{ gazeCount }}/{{ studentList.length }}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Вовлечены (>75%)</div>
          <div class="stat-value success">{{ highCount }}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Низкая вовлечённость</div>
          <div class="stat-value danger">{{ lowCount }}</div>
        </div>

        <!-- Распределение эмоций -->
        <div class="emotions-card">
          <div class="stat-label" style="margin-bottom:12px">Эмоции класса</div>
          <div class="emotions-list">
            <div v-for="(count, emotion) in emotionCounts" :key="emotion" class="emotion-row">
              <span class="emotion-emoji">{{ emotionEmoji(emotion) }}</span>
              <span class="emotion-name">{{ emotionName(emotion) }}</span>
              <div class="emotion-bar">
                <div class="emotion-fill" :style="{ width: (count / studentList.length * 100) + '%' }"></div>
              </div>
              <span class="emotion-count">{{ count }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  session: { type: Object, required: true },
  scores:  { type: Object, default: () => ({}) },
  avg:     { type: Number, default: 0 },
})
defineEmits(['back'])

const studentList = computed(() => Object.values(props.scores))

const avgClass = computed(() => {
  if (props.avg >= 75) return 'success'
  if (props.avg >= 50) return 'warning'
  return 'danger'
})

const gazeCount = computed(() => studentList.value.filter(s => s.gaze_on_board).length)
const highCount = computed(() => studentList.value.filter(s => s.score >= 75).length)
const lowCount  = computed(() => studentList.value.filter(s => s.score < 50).length)

const emotionCounts = computed(() => {
  const counts = {}
  studentList.value.forEach(s => {
    if (s.emotion) counts[s.emotion] = (counts[s.emotion] || 0) + 1
  })
  return Object.fromEntries(Object.entries(counts).sort((a,b) => b[1]-a[1]))
})

function levelClass(score) {
  if (score >= 75) return 'high'
  if (score >= 50) return 'medium'
  return 'low'
}

function emotionEmoji(emotion) {
  return { happy:'😊', neutral:'😐', sad:'😔', angry:'😠', fearful:'😨', disgusted:'🤢', surprised:'😲' }[emotion] || '😐'
}

function emotionName(emotion) {
  return { happy:'Радость', neutral:'Нейтрально', sad:'Грусть', angry:'Злость', fearful:'Страх', disgusted:'Отвращение', surprised:'Удивление' }[emotion] || emotion
}
</script>

<style scoped>
.session-detail { display:flex; flex-direction:column; gap:20px; }
.detail-header { display:flex; align-items:center; gap:20px; background:#111827; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px 20px; }
.back-btn { display:flex; align-items:center; gap:6px; padding:8px 12px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#94a3b8; cursor:pointer; font-size:13px; white-space:nowrap; transition:all 0.15s; }
.back-btn:hover { color:#f1f5f9; }
.back-btn svg { width:16px; height:16px; }
.session-info { flex:1; }
.session-info h2 { margin:0 0 2px; font-size:16px; font-weight:600; color:#f1f5f9; }
.session-subject { font-size:12px; color:#64748b; }
.session-avg { font-size:32px; font-weight:700; letter-spacing:-1px; }
.session-avg.success { color:#22c55e; }
.session-avg.warning { color:#f59e0b; }
.session-avg.danger  { color:#ef4444; }

.detail-body { display:grid; grid-template-columns:1fr 240px; gap:16px; }

.section-title { font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:12px; }

.students-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(80px,1fr)); gap:8px; }

.student-card { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:10px 8px; display:flex; flex-direction:column; align-items:center; gap:5px; transition:border-color 0.2s; }
.student-card.high   { border-color:rgba(34,197,94,0.25); }
.student-card.medium { border-color:rgba(245,158,11,0.25); }
.student-card.low    { border-color:rgba(239,68,68,0.25); animation:pulse-low 2s infinite; }

@keyframes pulse-low { 0%,100%{border-color:rgba(239,68,68,0.25)} 50%{border-color:rgba(239,68,68,0.6)} }

.student-score { font-size:15px; font-weight:700; color:#f1f5f9; }
.student-bar { width:100%; height:3px; background:rgba(255,255,255,0.08); border-radius:2px; overflow:hidden; }
.bar-fill { height:100%; background:currentColor; transition:width 0.5s; }
.student-card.high   .bar-fill { background:#22c55e; }
.student-card.medium .bar-fill { background:#f59e0b; }
.student-card.low    .bar-fill { background:#ef4444; }
.student-emotion { font-size:16px; }
.gaze-icon { font-size:12px; }
.gaze-icon.on  { opacity:1; }
.gaze-icon.off { opacity:0.2; }

.stats-section { display:flex; flex-direction:column; gap:10px; }
.stat-card { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:14px 16px; }
.stat-label { font-size:11px; color:#64748b; margin-bottom:4px; }
.stat-value { font-size:22px; font-weight:700; color:#f1f5f9; }
.stat-value.success { color:#22c55e; }
.stat-value.warning { color:#f59e0b; }
.stat-value.danger  { color:#ef4444; }

.emotions-card { background:#111827; border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:14px 16px; flex:1; }
.emotions-list { display:flex; flex-direction:column; gap:8px; }
.emotion-row { display:flex; align-items:center; gap:8px; }
.emotion-emoji { font-size:14px; width:20px; text-align:center; }
.emotion-name { font-size:11px; color:#64748b; width:80px; flex-shrink:0; }
.emotion-bar { flex:1; height:4px; background:rgba(255,255,255,0.08); border-radius:2px; overflow:hidden; }
.emotion-fill { height:100%; background:#6366f1; border-radius:2px; transition:width 0.5s; }
.emotion-count { font-size:11px; color:#94a3b8; width:16px; text-align:right; }
</style>
