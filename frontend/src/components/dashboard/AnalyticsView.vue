<template>
  <div class="analytics-view">
    <!-- Левая колонка: список уроков -->
    <aside class="lessons-pane">
      <div class="pane-header">
        <h3>Уроки</h3>
        <button class="refresh-btn" :disabled="loadingList" @click="loadLessons">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M3 12a9 9 0 0115.5-6.4M21 12a9 9 0 01-15.5 6.4M21 4v5h-5M3 20v-5h5"/>
          </svg>
        </button>
      </div>

      <div v-if="loadingList && lessons.length === 0" class="pane-loading">Загрузка уроков…</div>
      <div v-else-if="!lessons.length" class="pane-empty">
        Уроков пока нет. Запустите урок и веб-камера начнёт захватывать кадры.
      </div>

      <div v-else class="lessons-list">
        <button
          v-for="l in lessons"
          :key="l.id"
          class="lesson-item"
          :class="{ active: selectedId === l.id, [`level-${l.engagement_level}`]: true }"
          @click="selectLesson(l)"
        >
          <div class="lesson-row">
            <div class="lesson-name">{{ l.classroom_name || 'Класс' }}</div>
            <div class="lesson-score">{{ Math.round(l.avg_engagement_score || 0) }}%</div>
          </div>
          <div class="lesson-meta">
            <span>{{ l.subject || '—' }}</span>
            <span class="lesson-dot">•</span>
            <span>{{ formatDate(l.started_at) }}</span>
            <span v-if="l.status === 'active'" class="lesson-active-tag">Live</span>
          </div>
          <div class="lesson-meta light">
            <span>{{ l.students_count || 0 }} студентов</span>
            <span class="lesson-dot">•</span>
            <span>{{ l.total_snapshots || 0 }} снэпшотов</span>
          </div>
        </button>
      </div>
    </aside>

    <!-- Правая колонка: детали выбранного урока -->
    <section class="detail-pane">
      <div v-if="!selectedId" class="detail-empty">
        <div class="detail-empty-icon">📊</div>
        <div class="detail-empty-text">Выберите урок слева, чтобы увидеть разбор по студентам</div>
      </div>

      <div v-else-if="loadingDetail" class="detail-loading">Загрузка анализа…</div>

      <div v-else-if="!detail" class="detail-empty">
        <div class="detail-empty-text">Не удалось загрузить аналитику урока.</div>
      </div>

      <div v-else class="detail-body">
        <!-- Сводка урока -->
        <div class="detail-header">
          <div>
            <div class="detail-title">{{ detail.session.classroom_name || 'Класс' }}</div>
            <div class="detail-subtitle">
              {{ detail.session.subject || '—' }}
              <span v-if="detail.session.teacher_name" class="muted">• {{ detail.session.teacher_name }}</span>
              <span class="muted">• {{ formatDate(detail.session.started_at) }}</span>
            </div>
          </div>
          <div class="detail-score" :class="`level-${detail.session.engagement_level}`">
            {{ Math.round(detail.session.avg_engagement_score || 0) }}%
          </div>
        </div>

        <div class="stat-grid">
          <div class="stat-card">
            <div class="stat-label">Длительность</div>
            <div class="stat-value">{{ detail.session.duration_minutes ?? '—' }} мин</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Студентов</div>
            <div class="stat-value">{{ detail.session.students_count || 0 }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Снэпшотов</div>
            <div class="stat-value">{{ detail.session.total_snapshots || 0 }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Мин / Макс</div>
            <div class="stat-value small">
              {{ Math.round(detail.session.min_engagement_score || 0) }}%
              <span class="muted">/</span>
              {{ Math.round(detail.session.max_engagement_score || 0) }}%
            </div>
          </div>
        </div>

        <!-- Студенты -->
        <div class="section-title">
          Студенты ({{ detail.students.length }})
          <span v-if="!detail.students.length" class="section-hint">— ML ещё не успел собрать данные</span>
        </div>

        <div v-if="detail.students.length" class="students-grid">
          <article
            v-for="s in detail.students"
            :key="s.student_id"
            class="student-card"
            :class="`level-${s.level}`"
          >
            <header class="student-head">
              <div class="student-name" :title="s.student_name">{{ s.student_name }}</div>
              <div class="student-score">{{ Math.round(s.avg_engagement) }}%</div>
            </header>

            <div class="student-bar">
              <div class="student-bar-fill" :style="{ width: clamp(s.avg_engagement) + '%' }"></div>
            </div>

            <div class="student-row">
              <div class="student-emotion" :title="emotionName(s.dominant_emotion)">
                <span class="student-emoji">{{ emotionEmoji(s.dominant_emotion) }}</span>
                <span class="muted">{{ emotionName(s.dominant_emotion) || '—' }}</span>
              </div>
              <div class="student-gaze" :title="`${s.gaze_on_board_pct || 0}% времени на доске`">
                👁 {{ Math.round(s.gaze_on_board_pct || 0) }}%
              </div>
            </div>

            <div class="student-emotion-bars">
              <div
                v-for="bar in emotionBars(s.emotion_distribution)"
                :key="bar.emotion"
                class="emotion-bar-row"
              >
                <span class="emotion-bar-emoji">{{ emotionEmoji(bar.emotion) }}</span>
                <div class="emotion-bar-track">
                  <div
                    class="emotion-bar-fill"
                    :class="`em-${bar.emotion}`"
                    :style="{ width: bar.percent + '%' }"
                  ></div>
                </div>
                <span class="emotion-bar-percent">{{ Math.round(bar.percent) }}%</span>
              </div>
            </div>

            <footer class="student-foot muted">
              {{ s.snapshots }} снэпшотов
              <span v-if="s.absent_count > 0">• {{ s.absent_count }} без лица</span>
            </footer>
          </article>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { analytics } from '@/api'

const lessons       = ref([])
const loadingList   = ref(false)
const selectedId    = ref(null)
const detail        = ref(null)
const loadingDetail = ref(false)

const EMOTIONS = ['happy', 'neutral', 'surprised', 'sad', 'angry', 'fearful', 'disgusted']

const EMOTION_EMOJI = {
  happy: '😊', neutral: '😐', sad: '😔', angry: '😠',
  fearful: '😨', disgusted: '🤢', surprised: '😲',
}
const EMOTION_NAME = {
  happy: 'Радость', neutral: 'Нейтрально', sad: 'Грусть', angry: 'Злость',
  fearful: 'Страх', disgusted: 'Отвращение', surprised: 'Удивление',
}

let pollTimer = null

async function loadLessons() {
  loadingList.value = true
  try {
    const { data } = await analytics.lessons({ limit: 30 })
    lessons.value = data?.data ?? []
    if (!selectedId.value && lessons.value.length) {
      const first = lessons.value.find(l => l.status === 'active') || lessons.value[0]
      await selectLesson(first)
    } else if (selectedId.value && !lessons.value.find(l => l.id === selectedId.value)) {
      selectedId.value = null
      detail.value = null
    }
  } catch (e) {
    console.warn('Failed to load lessons', e)
  } finally {
    loadingList.value = false
  }
}

async function selectLesson(lesson) {
  if (!lesson) return
  selectedId.value = lesson.id
  loadingDetail.value = true
  try {
    const { data } = await analytics.session(lesson.id)
    detail.value = data
  } catch (e) {
    console.warn('Failed to load session detail', e)
    detail.value = null
  } finally {
    loadingDetail.value = false
  }
}

function emotionEmoji(e) {
  return EMOTION_EMOJI[e] || '😐'
}
function emotionName(e) {
  return EMOTION_NAME[e] || ''
}
function clamp(v) {
  const n = Number(v) || 0
  return Math.max(0, Math.min(100, n))
}
function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function emotionBars(distribution) {
  if (!distribution) return []
  return EMOTIONS
    .map(em => ({
      emotion: em,
      percent: distribution[em]?.percent ?? 0,
      count:   distribution[em]?.count ?? 0,
    }))
    .filter(b => b.count > 0)
    .sort((a, b) => b.percent - a.percent)
}

onMounted(async () => {
  await loadLessons()
  pollTimer = setInterval(async () => {
    await loadLessons()
    if (selectedId.value) {
      const lesson = lessons.value.find(l => l.id === selectedId.value)
      if (lesson?.status === 'active') {
        await selectLesson(lesson)
      }
    }
  }, 15000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<style scoped>
.analytics-view {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 16px;
  height: 100%;
  min-height: 0;
}

.lessons-pane {
  display: flex;
  flex-direction: column;
  background: #0d1220;
  border: 1px solid rgba(255, 255, 255, 0.07);
  border-radius: 12px;
  min-height: 0;
}
.pane-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.pane-header h3 {
  font-size: 13px; font-weight: 600; color: #94a3b8; margin: 0;
  text-transform: uppercase; letter-spacing: 0.06em;
}
.refresh-btn {
  width: 28px; height: 28px; border-radius: 6px;
  border: 1px solid rgba(255,255,255,0.08);
  background: rgba(255,255,255,0.04);
  color: #94a3b8; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.refresh-btn:hover:not(:disabled) { color: #e2e8f0; }
.refresh-btn:disabled { opacity: 0.4; cursor: progress; }
.refresh-btn svg { width: 14px; height: 14px; }

.pane-loading,
.pane-empty {
  padding: 24px 18px; font-size: 12.5px; color: #64748b; text-align: center;
}

.lessons-list {
  flex: 1; overflow-y: auto; padding: 8px;
  display: flex; flex-direction: column; gap: 6px;
}
.lessons-list::-webkit-scrollbar { width: 4px; }
.lessons-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.lesson-item {
  text-align: left;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 10px;
  padding: 10px 12px;
  cursor: pointer; color: inherit; font-family: inherit;
  display: flex; flex-direction: column; gap: 4px;
  transition: background-color 0.15s, border-color 0.15s;
}
.lesson-item:hover { background: rgba(255, 255, 255, 0.06); }
.lesson-item.active {
  background: rgba(99, 102, 241, 0.12);
  border-color: rgba(99, 102, 241, 0.4);
}
.lesson-item.level-low    .lesson-score { color: #ef4444; }
.lesson-item.level-medium .lesson-score { color: #f59e0b; }
.lesson-item.level-high   .lesson-score { color: #22c55e; }

.lesson-row {
  display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
}
.lesson-name {
  font-size: 13px; font-weight: 600; color: #e2e8f0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lesson-score { font-size: 16px; font-weight: 700; letter-spacing: -0.5px; }
.lesson-meta {
  font-size: 11.5px; color: #94a3b8;
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.lesson-meta.light { color: #64748b; }
.lesson-dot { color: #475569; }
.lesson-active-tag {
  background: rgba(34, 197, 94, 0.15); color: #4ade80;
  font-size: 10px; font-weight: 700; padding: 1px 6px;
  border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.3);
  margin-left: 4px;
}

.detail-pane {
  display: flex; flex-direction: column;
  background: #0d1220;
  border: 1px solid rgba(255, 255, 255, 0.07);
  border-radius: 12px;
  overflow: hidden; min-height: 0;
}
.detail-empty,
.detail-loading {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 12px;
  color: #64748b; text-align: center; padding: 24px;
}
.detail-empty-icon { font-size: 48px; }
.detail-empty-text { font-size: 13px; max-width: 320px; }

.detail-body {
  flex: 1; overflow-y: auto; padding: 18px 20px;
  display: flex; flex-direction: column; gap: 16px;
}
.detail-body::-webkit-scrollbar { width: 4px; }
.detail-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.detail-header {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
}
.detail-title { font-size: 16px; font-weight: 600; color: #f1f5f9; }
.detail-subtitle {
  font-size: 12px; color: #94a3b8; margin-top: 4px;
  display: flex; flex-wrap: wrap; gap: 6px;
}
.detail-score { font-size: 32px; font-weight: 700; letter-spacing: -1px; }
.detail-score.level-low    { color: #ef4444; }
.detail-score.level-medium { color: #f59e0b; }
.detail-score.level-high   { color: #22c55e; }

.muted { color: #64748b; }

.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
}
.stat-card {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 10px; padding: 12px 14px;
}
.stat-label { font-size: 11px; color: #64748b; }
.stat-value { font-size: 18px; font-weight: 700; color: #f1f5f9; margin-top: 2px; }
.stat-value.small { font-size: 14px; font-weight: 600; }

.section-title {
  font-size: 12px; font-weight: 600; color: #94a3b8;
  text-transform: uppercase; letter-spacing: 0.06em;
  margin-top: 4px;
}
.section-hint {
  text-transform: none; letter-spacing: 0; font-weight: 400;
  color: #64748b;
}

.students-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}

.student-card {
  background: #111827;
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 10px; padding: 12px;
  display: flex; flex-direction: column; gap: 8px;
}
.student-card.level-high   { border-color: rgba(34, 197, 94, 0.3); }
.student-card.level-medium { border-color: rgba(245, 158, 11, 0.3); }
.student-card.level-low    { border-color: rgba(239, 68, 68, 0.3); }

.student-head {
  display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
}
.student-name {
  font-size: 13px; font-weight: 600; color: #e2e8f0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  flex: 1;
}
.student-score { font-size: 18px; font-weight: 700; letter-spacing: -0.5px; color: #f1f5f9; }
.student-card.level-high   .student-score { color: #22c55e; }
.student-card.level-medium .student-score { color: #f59e0b; }
.student-card.level-low    .student-score { color: #ef4444; }

.student-bar {
  height: 4px; background: rgba(255,255,255,0.08); border-radius: 2px; overflow: hidden;
}
.student-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #6366f1, #8b5cf6);
  transition: width 0.4s;
}
.student-card.level-high   .student-bar-fill { background: linear-gradient(90deg, #22c55e, #4ade80); }
.student-card.level-medium .student-bar-fill { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.student-card.level-low    .student-bar-fill { background: linear-gradient(90deg, #ef4444, #f97316); }

.student-row {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  font-size: 12px;
}
.student-emotion { display: flex; align-items: center; gap: 6px; }
.student-emoji { font-size: 16px; }
.student-gaze { font-size: 12px; color: #94a3b8; }

.student-emotion-bars {
  display: flex; flex-direction: column; gap: 4px;
  padding-top: 4px; border-top: 1px solid rgba(255,255,255,0.05);
}
.emotion-bar-row {
  display: flex; align-items: center; gap: 6px; font-size: 11px;
}
.emotion-bar-emoji { width: 16px; text-align: center; font-size: 12px; }
.emotion-bar-track {
  flex: 1; height: 4px; background: rgba(255,255,255,0.06);
  border-radius: 2px; overflow: hidden;
}
.emotion-bar-fill { height: 100%; background: #6366f1; transition: width 0.3s; }
.emotion-bar-fill.em-happy     { background: #22c55e; }
.emotion-bar-fill.em-neutral   { background: #94a3b8; }
.emotion-bar-fill.em-sad       { background: #60a5fa; }
.emotion-bar-fill.em-angry     { background: #ef4444; }
.emotion-bar-fill.em-fearful   { background: #a855f7; }
.emotion-bar-fill.em-disgusted { background: #16a34a; }
.emotion-bar-fill.em-surprised { background: #f59e0b; }
.emotion-bar-percent {
  width: 32px; text-align: right; color: #94a3b8;
  font-variant-numeric: tabular-nums;
}

.student-foot {
  font-size: 11px;
  padding-top: 4px;
  border-top: 1px solid rgba(255,255,255,0.05);
}

@media (max-width: 900px) {
  .analytics-view {
    grid-template-columns: 1fr;
    grid-template-rows: auto 1fr;
  }
  .lessons-pane { max-height: 240px; }
}
</style>
