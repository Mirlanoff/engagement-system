<template>
  <div class="live-overview">

    <!-- Нет активных уроков -->
    <div v-if="sessions.length === 0" class="empty-state">
      <div class="empty-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
          <path d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/>
        </svg>
      </div>
      <h3>Нет активных уроков</h3>
      <p>Когда учитель начнёт урок — он появится здесь в реальном времени</p>
    </div>

    <!-- Сетка классов -->
    <template v-else>
      <!-- Сводная статистика сверху -->
      <div class="summary-bar">
        <div class="summary-item">
          <span class="summary-value">{{ sessions.length }}</span>
          <span class="summary-label">Активных уроков</span>
        </div>
        <div class="summary-item">
          <span class="summary-value">{{ totalStudents }}</span>
          <span class="summary-label">Студентов онлайн</span>
        </div>
        <div class="summary-item">
          <span class="summary-value" :class="avgClass(schoolAvg)">{{ schoolAvg }}%</span>
          <span class="summary-label">Средняя вовлечённость</span>
        </div>
        <div class="summary-item">
          <span class="summary-value danger">{{ lowEngagementCount }}</span>
          <span class="summary-label">Низкая вовлечённость</span>
        </div>
      </div>

      <!-- Карточки классов -->
      <div class="sessions-grid">
        <ClassroomCard
          v-for="session in sessions"
          :key="session.id"
          :session="session"
          :avg="averages[session.id] || session.avg_engagement_score || 0"
          :student-scores="scores[session.id] || {}"
          @click="$emit('select', session)"
        />
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import ClassroomCard from './ClassroomCard.vue'

const props = defineProps({
  sessions: { type: Array, default: () => [] },
  scores:   { type: Object, default: () => ({}) },
  averages: { type: Object, default: () => ({}) },
})
defineEmits(['select'])

const totalStudents = computed(() => props.sessions.reduce((s, sess) => s + (sess.students_count || 0), 0))

const schoolAvg = computed(() => {
  if (!props.sessions.length) return 0
  const avgs = props.sessions.map(s => props.averages[s.id] || s.avg_engagement_score || 0)
  return Math.round(avgs.reduce((a, b) => a + b, 0) / avgs.length)
})

const lowEngagementCount = computed(() =>
  props.sessions.filter(s => (props.averages[s.id] || 0) < 50).length
)

function avgClass(avg) {
  if (avg >= 75) return 'success'
  if (avg >= 50) return 'warning'
  return 'danger'
}
</script>

<style scoped>
.live-overview { display:flex; flex-direction:column; gap:24px; }

.empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:80px 20px; color:#475569; text-align:center; }
.empty-icon { width:64px; height:64px; margin-bottom:16px; opacity:0.3; }
.empty-icon svg { width:100%; height:100%; }
.empty-state h3 { font-size:16px; font-weight:600; color:#64748b; margin:0 0 8px; }
.empty-state p { font-size:13px; color:#475569; margin:0; max-width:300px; }

.summary-bar { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.summary-item { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:16px 20px; }
.summary-value { display:block; font-size:28px; font-weight:700; color:#f1f5f9; letter-spacing:-1px; }
.summary-value.success { color:#22c55e; }
.summary-value.warning { color:#f59e0b; }
.summary-value.danger  { color:#ef4444; }
.summary-label { display:block; font-size:12px; color:#64748b; margin-top:2px; }

.sessions-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px; }
</style>
