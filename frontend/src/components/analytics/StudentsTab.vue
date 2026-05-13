<template>
  <div class="students-tab">
    <!-- ── Filters ────────────────────────────────────────── -->
    <div class="filters">
      <div class="filter-label">
        <span>Класс:</span>
        <select v-model="classroomId" class="classroom-select">
          <option value="">Все</option>
          <option v-for="c in classroomOptions" :key="c.id" :value="c.id">
            {{ c.name }}
          </option>
        </select>
      </div>
      <div class="period">
        <span class="period-label">Период:</span>
        <span class="period-value">{{ from }} → {{ to }}</span>
      </div>
    </div>

    <!-- ── States ─────────────────────────────────────────── -->
    <div v-if="loading" class="skeleton-wrap">
      <div v-for="n in 5" :key="n" class="skeleton-row">
        <span class="sk sk-dot"></span>
        <span class="sk sk-name"></span>
        <span class="sk sk-bar"></span>
        <span class="sk sk-cell"></span>
        <span class="sk sk-cell"></span>
        <span class="sk sk-cell"></span>
      </div>
    </div>

    <div v-else-if="error" class="state error">
      <p>Ошибка загрузки</p>
      <button class="retry" @click="load">Повторить</button>
    </div>

    <div v-else-if="students.length === 0" class="state empty">
      <div class="empty-icon">👥</div>
      <h3>Нет данных за выбранный период</h3>
      <p>Попробуйте выбрать другой диапазон дат или другой класс.</p>
    </div>

    <template v-else>
      <div class="summary">
        <span>{{ students.length }} {{ pluralStudent(students.length) }}</span>
        <span class="muted">отсортированы по возрастанию вовлечённости</span>
      </div>
      <StudentTable :students="students" />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, inject, onMounted } from 'vue'
import { analytics, classrooms as classroomsApi } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'
import StudentTable from './StudentTable.vue'

const { from, to } = useAnalyticsFilters()

const loading      = ref(false)
const error        = ref(false)
const students     = ref([])
const classroomId  = ref('')
const classroomOptions = ref([])

async function loadClassrooms() {
  try {
    const { data } = await classroomsApi.list()
    classroomOptions.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    classroomOptions.value = []
  }
}

async function load() {
  loading.value = true
  error.value   = false
  try {
    const params = { from: from.value, to: to.value }
    if (classroomId.value) params.classroom_id = classroomId.value
    const { data } = await analytics.studentsList(params)
    students.value = Array.isArray(data?.data) ? data.data : []
  } catch (e) {
    console.warn('[StudentsTab] load failed', e)
    students.value = []
    error.value = true
  } finally {
    loading.value = false
  }
}

function pluralStudent(n) {
  const m100 = n % 100
  const m10  = n % 10
  if (m100 >= 11 && m100 <= 14) return 'студентов'
  if (m10 === 1) return 'студент'
  if (m10 >= 2 && m10 <= 4) return 'студента'
  return 'студентов'
}

// Real-time refresh trigger from AnalyticsView
const refreshTrigger = inject('analyticsRefreshTrigger', ref(0))

onMounted(() => {
  loadClassrooms()
  load()
})

watch([from, to, classroomId], load)
watch(refreshTrigger, () => load())
</script>

<style scoped>
.students-tab {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.filters {
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}
.filter-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #94a3b8;
}
.classroom-select {
  padding: 6px 28px 6px 10px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: #f1f5f9;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  color-scheme: dark;
  appearance: none;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'><polyline points='6 9 12 15 18 9'/></svg>");
  background-repeat: no-repeat;
  background-position: right 8px center;
}
.classroom-select:focus { outline: none; border-color: #6366f1; }

.period {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #94a3b8;
}
.period-value {
  font-variant-numeric: tabular-nums;
  color: #cbd5e1;
}

.summary {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 13px;
  color: #cbd5e1;
}
.summary .muted { color: #64748b; font-size: 12px; }

/* ── States ───────────────────────────────────────────── */
.state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  gap: 12px;
  color: #94a3b8;
  text-align: center;
}
.state.error { color: #fca5a5; }
.state.empty .empty-icon { font-size: 40px; }
.state h3 { margin: 0; font-size: 16px; color: #e2e8f0; }
.state p  { margin: 0; font-size: 13px; color: #94a3b8; }
.retry {
  padding: 8px 16px;
  background: rgba(99,102,241,0.15);
  color: #a5b4fc;
  border: 1px solid rgba(99,102,241,0.3);
  border-radius: 8px;
  cursor: pointer;
  font-size: 12px;
  font-family: inherit;
}
.retry:hover { background: rgba(99,102,241,0.25); }

/* ── Skeleton ─────────────────────────────────────────── */
.skeleton-wrap {
  background: #1e293b;
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.skeleton-row {
  display: grid;
  grid-template-columns: 24px 1.5fr 2fr 1fr 1fr 1fr;
  gap: 12px;
  align-items: center;
}
.sk {
  height: 14px;
  background: linear-gradient(90deg, rgba(255,255,255,0.05), rgba(255,255,255,0.1), rgba(255,255,255,0.05));
  background-size: 200% 100%;
  border-radius: 6px;
  animation: shimmer 1.4s ease-in-out infinite;
}
.sk.sk-dot  { width: 12px; height: 12px; border-radius: 50%; }
.sk.sk-name { height: 14px; width: 80%; }
.sk.sk-bar  { height: 10px; }
.sk.sk-cell { height: 14px; width: 60%; justify-self: end; }
@keyframes shimmer {
  0%   { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}
</style>
