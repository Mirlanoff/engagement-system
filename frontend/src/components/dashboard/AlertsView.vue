<template>
  <div class="alerts-view">
    <div class="alerts-header">
      <span class="alerts-count">{{ unacknowledged }} непрочитанных</span>
      <button v-if="unacknowledged > 0" class="ack-all-btn" @click="ackAll">
        Прочитать все
      </button>
    </div>

    <div v-if="alerts.length === 0" class="empty-alerts">
      <div style="font-size:48px;margin-bottom:12px">✅</div>
      <p>Нет активных алертов</p>
    </div>

    <div class="alerts-list">
      <div
        v-for="alert in alerts"
        :key="alert.alert_id"
        class="alert-item"
        :class="[alert.severity, { acknowledged: alert.is_acknowledged }]"
      >
        <div class="alert-icon">
          <span v-if="alert.severity === 'critical'">🚨</span>
          <span v-else-if="alert.severity === 'warning'">⚠️</span>
          <span v-else>ℹ️</span>
        </div>
        <div class="alert-body">
          <div class="alert-message">{{ alert.message }}</div>
          <div class="alert-meta">
            {{ formatTime(alert.triggered_at) }} •
            {{ alertTypeLabel(alert.type) }}
          </div>
        </div>
        <button
          v-if="!alert.is_acknowledged"
          class="ack-btn"
          @click.stop="$emit('acknowledge', alert.alert_id)"
        >
          ✓
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  alerts: { type: Array, default: () => [] }
})
const emit = defineEmits(['acknowledge'])

const unacknowledged = computed(() => props.alerts.filter(a => !a.is_acknowledged).length)

function ackAll() {
  props.alerts.filter(a => !a.is_acknowledged).forEach(a => emit('acknowledge', a.alert_id))
}

function formatTime(ts) {
  if (!ts) return ''
  return new Date(ts).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function alertTypeLabel(type) {
  return {
    low_class_engagement:    'Низкая вовлечённость класса',
    low_student_engagement:  'Низкая вовлечённость студента',
    student_absent:          'Студент отсутствует',
    rapid_decline:           'Резкое падение',
    prolonged_low:           'Длительная низкая активность',
    anomaly_detected:        'Аномалия',
  }[type] || type
}
</script>

<style scoped>
.alerts-view { display:flex; flex-direction:column; gap:16px; }
.alerts-header { display:flex; align-items:center; justify-content:space-between; }
.alerts-count { font-size:13px; color:#64748b; }
.ack-all-btn { padding:6px 14px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#94a3b8; cursor:pointer; font-size:12px; transition:all 0.15s; }
.ack-all-btn:hover { color:#f1f5f9; }
.empty-alerts { text-align:center; padding:60px; color:#64748b; }
.alerts-list { display:flex; flex-direction:column; gap:8px; }
.alert-item { display:flex; align-items:center; gap:14px; padding:14px 16px; background:#111827; border-radius:10px; border:1px solid rgba(255,255,255,0.07); transition:opacity 0.2s; }
.alert-item.critical { border-left:3px solid #ef4444; }
.alert-item.warning  { border-left:3px solid #f59e0b; }
.alert-item.info     { border-left:3px solid #6366f1; }
.alert-item.acknowledged { opacity:0.4; }
.alert-icon { font-size:20px; flex-shrink:0; }
.alert-body { flex:1; }
.alert-message { font-size:13px; color:#f1f5f9; margin-bottom:3px; }
.alert-meta { font-size:11px; color:#64748b; }
.ack-btn { width:28px; height:28px; border-radius:6px; border:1px solid rgba(255,255,255,0.1); background:transparent; color:#22c55e; cursor:pointer; font-size:14px; transition:all 0.15s; }
.ack-btn:hover { background:rgba(34,197,94,0.1); }
</style>
