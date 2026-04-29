<template>
  <div class="live-overview">

    <StartSessionModal
      v-if="showModal"
      @close="showModal = false"
      @started="onSessionStarted"
    />

    <!-- Кнопка начать урок -->
    <div class="top-bar">
      <div class="summary-bar" v-if="sessions.length > 0">
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
          <span class="summary-value danger">{{ lowCount }}</span>
          <span class="summary-label">Низкая вовлечённость</span>
        </div>
      </div>
      <div v-else class="spacer"></div>
      <button class="start-btn" @click="showModal = true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5,3 19,12 5,21"/></svg>
        Начать урок
      </button>
    </div>

    <!-- Нет уроков -->
    <div v-if="sessions.length === 0" class="empty-state">
      <div class="empty-icon">📹</div>
      <h3>Нет активных уроков</h3>
      <p>Нажми «Начать урок» чтобы запустить мониторинг класса</p>
    </div>

    <!-- Карточки классов -->
    <div v-else class="sessions-grid">
      <ClassroomCard
        v-for="session in sessions"
        :key="session.id"
        :session="session"
        :avg="averages[session.id] || session.avg_engagement_score || 0"
        :student-scores="scores[session.id] || {}"
        @click="$emit('select', session)"
      />
    </div>

  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import ClassroomCard      from './ClassroomCard.vue'
import StartSessionModal  from './StartSessionModal.vue'

const props = defineProps({
  sessions: { type: Array,  default: () => [] },
  scores:   { type: Object, default: () => ({}) },
  averages: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['select', 'refresh'])

const showModal = ref(false)

function onSessionStarted(session) {
  emit('refresh')
}

const totalStudents = computed(() =>
  props.sessions.reduce((s, sess) => s + (sess.students_count || 0), 0)
)
const schoolAvg = computed(() => {
  if (!props.sessions.length) return 0
  const avgs = props.sessions.map(s => props.averages[s.id] || s.avg_engagement_score || 0)
  return Math.round(avgs.reduce((a, b) => a + b, 0) / avgs.length)
})
const lowCount = computed(() =>
  props.sessions.filter(s => (props.averages[s.id] || 0) < 50).length
)
function avgClass(v) {
  return v >= 75 ? 'success' : v >= 50 ? 'warning' : 'danger'
}
</script>

<style scoped>
.live-overview { display:flex; flex-direction:column; gap:20px; }
.top-bar { display:flex; align-items:center; justify-content:space-between; gap:16px; }
.spacer { flex:1; }
.start-btn { display:flex; align-items:center; gap:8px; padding:10px 20px; background:linear-gradient(135deg,#6366f1,#8b5cf6); border:none; border-radius:10px; color:white; font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; transition:opacity .15s; font-family:inherit; }
.start-btn:hover { opacity:.9; }
.start-btn svg { width:14px; height:14px; }
.summary-bar { display:flex; gap:12px; flex:1; }
.summary-item { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:12px 16px; }
.summary-value { display:block; font-size:22px; font-weight:700; color:#f1f5f9; letter-spacing:-0.5px; }
.summary-value.success { color:#22c55e; }
.summary-value.warning { color:#f59e0b; }
.summary-value.danger  { color:#ef4444; }
.summary-label { display:block; font-size:11px; color:#64748b; margin-top:1px; }
.empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:80px 20px; color:#475569; text-align:center; }
.empty-icon { font-size:48px; margin-bottom:16px; }
.empty-state h3 { font-size:16px; font-weight:600; color:#64748b; margin:0 0 8px; }
.empty-state p { font-size:13px; color:#475569; margin:0; }
.sessions-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px; }
</style>
