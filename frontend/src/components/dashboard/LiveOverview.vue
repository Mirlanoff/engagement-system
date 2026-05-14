<template>
  <div class="live-overview">

    <StartSessionModal
      v-if="showModal"
      @close="showModal = false"
      @started="onSessionStarted"
    />

    <StudentRegistrationModal
      v-if="showStudentModal"
      @close="showStudentModal = false"
      @registered="onStudentRegistered"
    />

    <!-- Classroom Detail Modal -->
    <div v-if="selectedClassroom" class="modal-overlay" @click.self="selectedClassroom = null">
      <div class="classroom-detail-modal">
        <div class="cdm-header">
          <h2>{{ selectedClassroom.name }}</h2>
          <button class="close-btn" @click="selectedClassroom = null">&#10005;</button>
        </div>
        <div class="cdm-body">
          <div class="cdm-info">
            <div class="cdm-row">
              <span class="cdm-label">Классный руководитель:</span>
              <span class="cdm-value">{{ selectedClassroom.head_teacher || 'Не указан' }}</span>
            </div>
            <div class="cdm-row">
              <span class="cdm-label">Кол-во студентов:</span>
              <span class="cdm-value">{{ classroomStudents.length }}</span>
            </div>
          </div>
          <div class="cdm-students">
            <h3 class="cdm-students-title">Список студентов</h3>
            <div v-if="loadingStudents" class="cdm-loading">Загрузка...</div>
            <div v-else-if="classroomStudents.length === 0" class="cdm-empty">Нет студентов в этом классе</div>
            <div v-else class="cdm-students-list">
              <div
                v-for="student in classroomStudents"
                :key="student.id"
                class="cdm-student-row"
              >
                <div class="cdm-student-avatar">
                  <img v-if="student.photo_url" :src="student.photo_url" alt="" />
                  <span v-else>{{ getInitials(student.name) }}</span>
                </div>
                <span class="cdm-student-name">{{ student.name }}</span>
                <span class="cdm-student-face" :class="{ registered: student.face_registered }">
                  {{ student.face_registered ? 'Лицо зарег.' : 'Нет лица' }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Top actions -->
    <div class="top-bar">
      <h2 class="top-title">Обзор</h2>
      <div class="top-actions">
        <button class="register-btn-sm" @click="showStudentModal = true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
            <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4-4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <line x1="19" y1="8" x2="19" y2="14"/>
            <line x1="22" y1="11" x2="16" y2="11"/>
          </svg>
          Регистрация
        </button>
        <button class="start-btn" @click="showModal = true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <polygon points="6,4 20,12 6,20" fill="currentColor" stroke="none"/>
          </svg>
          Начать урок
        </button>
      </div>
    </div>

    <!-- Active classes section -->
    <section v-if="activeClassrooms.length > 0" class="section">
      <h3 class="section-title">Активные классы <span class="badge">{{ activeClassrooms.length }}</span></h3>
      <div class="classrooms-grid">
        <div
          v-for="c in activeClassrooms"
          :key="c.id"
          class="classroom-card active"
          @click="openClassroom(c)"
        >
          <div class="cc-top">
            <span class="cc-name">{{ c.name }}</span>
            <div class="live-badge"><span class="live-dot"></span>Live</div>
          </div>
          <div class="cc-meta">
            <span v-if="c.head_teacher">{{ c.head_teacher }}</span>
            <span>{{ c.students_count }} студентов</span>
          </div>
        </div>
      </div>
    </section>

    <!-- All classes section -->
    <section class="section">
      <h3 class="section-title">Все классы <span class="badge">{{ allClassrooms.length }}</span></h3>
      <div v-if="allClassrooms.length === 0" class="empty-mini">
        Нет классов
      </div>
      <div v-else class="classrooms-grid">
        <div
          v-for="c in inactiveClassrooms"
          :key="c.id"
          class="classroom-card"
          @click="openClassroom(c)"
        >
          <div class="cc-top">
            <span class="cc-name">{{ c.name }}</span>
          </div>
          <div class="cc-meta">
            <span v-if="c.head_teacher">{{ c.head_teacher }}</span>
            <span>{{ c.students_count }} студентов</span>
          </div>
        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useEngagementStore } from '@/stores/engagement'
import { classrooms as classroomsApi, students as studentsApi } from '@/api'
import StartSessionModal from './StartSessionModal.vue'
import StudentRegistrationModal from './StudentRegistrationModal.vue'

const props = defineProps({
  sessions: { type: Array,  default: () => [] },
  scores:   { type: Object, default: () => ({}) },
  averages: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['select', 'refresh', 'session-started'])

const engagementStore = useEngagementStore()
const showModal       = ref(false)
const showStudentModal = ref(false)

// Classrooms
const allClassrooms = ref([])
const selectedClassroom = ref(null)
const classroomStudents = ref([])
const loadingStudents = ref(false)

const activeClassrooms = computed(() =>
  allClassrooms.value.filter(c => c.is_lesson_active)
)
const inactiveClassrooms = computed(() =>
  allClassrooms.value.filter(c => !c.is_lesson_active)
)

function onSessionStarted(session) {
  emit('refresh')
  emit('session-started', session)
  loadClassrooms()
}

function onStudentRegistered(student) {
  console.log('Студент зарегистрирован:', student)
  // Reload classroom students if modal is open
  if (selectedClassroom.value) {
    loadClassroomStudents(selectedClassroom.value.id)
  }
}

async function loadClassrooms() {
  try {
    const { data } = await classroomsApi.list()
    allClassrooms.value = data.data || []
  } catch (e) {
    allClassrooms.value = []
  }
}

async function openClassroom(classroom) {
  selectedClassroom.value = classroom
  await loadClassroomStudents(classroom.id)
}

async function loadClassroomStudents(classroomId) {
  loadingStudents.value = true
  try {
    const { data } = await studentsApi.list(classroomId)
    classroomStudents.value = data.data || []
  } catch (e) {
    classroomStudents.value = []
  } finally {
    loadingStudents.value = false
  }
}

function getInitials(name) {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase()
  return name[0].toUpperCase()
}

let refreshTimer = null

onMounted(() => {
  loadClassrooms()
  refreshTimer = setInterval(loadClassrooms, 15000)
})

onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer)
})
</script>

<style scoped>
.live-overview {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.top-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.top-title {
  font-size: 18px;
  font-weight: 600;
  color: #f1f5f9;
  margin: 0;
}
.start-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  background: linear-gradient(135deg,#6366f1,#8b5cf6);
  border: none;
  border-radius: 9px;
  color: white;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.start-btn:hover  { transform: translateY(-1px); }
.start-btn svg    { width: 14px; height: 14px; }

.register-btn-sm {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 14px;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(99,102,241,0.35);
  border-radius: 9px;
  color: #a5b4fc;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.register-btn-sm:hover {
  background: rgba(99,102,241,0.1);
  border-color: rgba(99,102,241,0.55);
  color: #c7d2fe;
}

/* Sections */
.section { display: flex; flex-direction: column; gap: 12px; }
.section-title {
  font-size: 14px;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.badge {
  background: rgba(99,102,241,0.15);
  color: #a5b4fc;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
}

.empty-mini {
  padding: 20px;
  text-align: center;
  color: #64748b;
  font-size: 13px;
}

/* Classrooms grid */
.classrooms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}

.classroom-card {
  background: #1e293b;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  padding: 16px 18px;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.classroom-card:hover {
  background: #243349;
  transform: translateY(-1px);
  border-color: rgba(99,102,241,0.3);
}
.classroom-card.active {
  border-color: rgba(34,197,94,0.4);
  background: rgba(34,197,94,0.05);
}
.classroom-card.active:hover {
  border-color: rgba(34,197,94,0.6);
  background: rgba(34,197,94,0.08);
}

.cc-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cc-name {
  font-size: 15px;
  font-weight: 600;
  color: #f1f5f9;
}
.cc-meta {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: #94a3b8;
}

.live-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  background: rgba(34,197,94,0.1);
  border: 1px solid rgba(34,197,94,0.3);
  border-radius: 20px;
  font-size: 9px;
  color: #22c55e;
  font-weight: 700;
  text-transform: uppercase;
}
.live-dot {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #22c55e;
  animation: pulse 1.4s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%      { opacity: 0.4; transform: scale(0.8); }
}

/* Classroom Detail Modal */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.7);
  display: flex; align-items: center; justify-content: center;
  z-index: 100;
}
.classroom-detail-modal {
  background: #0d1220;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 16px;
  width: 500px;
  max-height: 80vh;
  overflow-y: auto;
}
.cdm-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1px solid rgba(255,255,255,0.07);
}
.cdm-header h2 { font-size: 16px; font-weight: 600; color: #f1f5f9; margin: 0; }
.close-btn {
  width: 28px; height: 28px; border: none;
  background: transparent; color: #64748b;
  cursor: pointer; border-radius: 6px; font-size: 14px;
}
.close-btn:hover { color: #f1f5f9; background: rgba(255,255,255,0.06); }

.cdm-body { padding: 20px 22px; display: flex; flex-direction: column; gap: 18px; }
.cdm-info { display: flex; flex-direction: column; gap: 8px; }
.cdm-row { display: flex; justify-content: space-between; font-size: 13px; }
.cdm-label { color: #94a3b8; }
.cdm-value { color: #f1f5f9; font-weight: 500; }

.cdm-students { display: flex; flex-direction: column; gap: 10px; }
.cdm-students-title { font-size: 13px; font-weight: 600; color: #cbd5e1; margin: 0; }
.cdm-loading, .cdm-empty { font-size: 13px; color: #64748b; padding: 12px 0; }

.cdm-students-list { display: flex; flex-direction: column; gap: 6px; }
.cdm-student-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  background: rgba(255,255,255,0.03);
  border-radius: 8px;
}
.cdm-student-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: white;
  overflow: hidden; flex-shrink: 0;
}
.cdm-student-avatar img { width: 100%; height: 100%; object-fit: cover; }
.cdm-student-name { flex: 1; font-size: 13px; color: #f1f5f9; }
.cdm-student-face {
  font-size: 11px; padding: 2px 8px; border-radius: 10px;
  background: rgba(239,68,68,0.1); color: #ef4444;
  border: 1px solid rgba(239,68,68,0.2);
}
.cdm-student-face.registered {
  background: rgba(34,197,94,0.1); color: #22c55e;
  border-color: rgba(34,197,94,0.2);
}
</style>
