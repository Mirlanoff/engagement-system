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
      <!-- Левая колонка -->
      <div class="left-col">
        <!-- Сетка студентов -->
        <div class="students-section">
          <div class="section-title">Студенты • {{ studentList.length }}</div>
          <div class="students-grid">
            <button
              v-for="student in studentList"
              :key="student.student_id"
              class="student-card"
              :class="levelClass(student.score)"
              @click="openBreakdown(student)"
            >
              <div class="student-score">{{ Math.round(student.score) }}</div>
              <div class="student-bar">
                <div class="bar-fill" :style="{ width: student.score + '%' }"></div>
              </div>
              <div class="student-meta">
                <span class="meta-emoji">{{ emotionEmoji(student.emotion) }}</span>
                <span :class="['gaze-icon', student.gaze_on_board ? 'on' : 'off']">👁</span>
                <span v-if="student.hand_raised" title="Подняла руку" class="hand-up">✋</span>
                <span v-if="student.attention_state" class="attn-pill" :class="`attn-${student.attention_state}`">
                  {{ attnLabel(student.attention_state) }}
                </span>
              </div>
            </button>
          </div>
          <div class="hint-line">Нажмите на карточку студента, чтобы посмотреть, почему такая оценка.</div>
        </div>

        <!-- Таймлайн вовлечённости класса -->
        <div class="timeline-section">
          <div class="section-title">Динамика урока</div>
          <div v-if="timelineLoading" class="timeline-loading">Загружаю таймлайн…</div>
          <div v-else-if="!timeline.length" class="timeline-empty">Данных пока нет.</div>
          <svg v-else class="timeline-chart" :viewBox="`0 0 ${timelineWidth} ${timelineHeight}`" preserveAspectRatio="none">
            <line :x1="0" :x2="timelineWidth" :y1="timelineHeight - 75 * timelineScaleY" :y2="timelineHeight - 75 * timelineScaleY" class="grid-line"/>
            <line :x1="0" :x2="timelineWidth" :y1="timelineHeight - 50 * timelineScaleY" :y2="timelineHeight - 50 * timelineScaleY" class="grid-line"/>
            <line :x1="0" :x2="timelineWidth" :y1="timelineHeight - 25 * timelineScaleY" :y2="timelineHeight - 25 * timelineScaleY" class="grid-line"/>
            <polyline class="timeline-line" :points="timelinePoints"/>
            <circle v-for="(p, i) in timelineCircles" :key="i" :cx="p.x" :cy="p.y" r="2.5" class="timeline-dot"/>
          </svg>
          <div v-if="timeline.length" class="timeline-axis">
            <span>{{ formatMinute(timeline[0].minute) }}</span>
            <span>{{ formatMinute(timeline[timeline.length - 1].minute) }}</span>
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

    <!-- Popover: расшифровка скоринга по студенту -->
    <div v-if="breakdown.open" class="popover-mask" @click.self="closeBreakdown">
      <div class="popover">
        <div class="popover-header">
          <h3>Почему такая оценка?</h3>
          <button class="popover-close" @click="closeBreakdown">✕</button>
        </div>

        <div v-if="breakdown.loading" class="popover-loading">Загружаю…</div>
        <div v-else-if="breakdown.error" class="popover-error">{{ breakdown.error }}</div>
        <div v-else class="popover-body">
          <div class="popover-summary">
            <div>
              <div class="popover-summary-label">Итог</div>
              <div class="popover-summary-value" :class="levelClass(breakdown.data.engagement_score)">
                {{ Math.round(breakdown.data.engagement_score) }}%
              </div>
            </div>
            <div v-if="breakdown.data.attention_state">
              <div class="popover-summary-label">Состояние</div>
              <div class="popover-summary-value">
                <span :class="`attn-pill attn-${breakdown.data.attention_state}`">
                  {{ attnLabel(breakdown.data.attention_state) }}
                </span>
              </div>
            </div>
          </div>

          <div v-if="breakdown.data.not_detected_reason" class="popover-warning">
            ⚠ {{ notDetectedLabel(breakdown.data.not_detected_reason) }}
          </div>

          <div v-if="breakdownComponents.length" class="popover-components">
            <div class="popover-section-title">Компоненты вовлечённости</div>
            <div v-for="comp in breakdownComponents" :key="comp.name" class="component-row">
              <div class="component-head">
                <span class="component-name">{{ comp.label }}</span>
                <span class="component-weight" v-if="comp.weight != null">×{{ (comp.weight * 100).toFixed(0) }}%</span>
                <span class="component-score" v-if="comp.score != null">{{ Math.round(comp.score) }}</span>
              </div>
              <div class="component-bar">
                <div class="component-fill" :class="levelClass(comp.score)" :style="{ width: (comp.score ?? 0) + '%' }"></div>
              </div>
              <div class="component-reason" v-if="comp.reason">{{ comp.reason }}</div>
            </div>
          </div>

          <div v-if="breakdown.data.frame_quality" class="popover-quality">
            <div class="popover-section-title">Качество кадра</div>
            <div class="quality-grid">
              <div v-if="breakdown.data.frame_quality.brightness != null">
                Яркость: <strong>{{ breakdown.data.frame_quality.brightness.toFixed?.(1) ?? breakdown.data.frame_quality.brightness }}</strong>
              </div>
              <div v-if="breakdown.data.frame_quality.blur != null">
                Резкость: <strong>{{ breakdown.data.frame_quality.blur.toFixed?.(1) ?? breakdown.data.frame_quality.blur }}</strong>
              </div>
              <div>
                Лицо: <strong>{{ breakdown.data.face_detected ? 'найдено' : 'не найдено' }}</strong>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { analytics, sessions as sessionsApi } from '@/api'

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
  if (score == null) return 'low'
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
function attnLabel(state) {
  return { focused:'сконц.', distracted:'отвл.', drowsy:'устал', absent:'нет' }[state] || state
}
function notDetectedLabel(reason) {
  return ({
    too_dark:          'Слишком тёмный кадр — добавьте света.',
    too_blurry:        'Кадр размыт — проверьте фокус и тряску.',
    no_faces_in_fov:   'В кадре не видно лиц.',
    face_too_small:    'Лица слишком мелкие — подвиньте камеру ближе.',
    model_unavailable: 'ML-модель недоступна.',
    frame_decode_error:'Не удалось декодировать кадр.',
  })[reason] || reason
}

// ── Breakdown popover ──────────────────────────────────────────

const breakdown = ref({ open: false, loading: false, error: null, data: null, studentId: null })

async function openBreakdown(student) {
  if (!student?.snapshot_id) {
    breakdown.value = {
      open: true, loading: false, error: 'Снэпшот для этого студента ещё не получен — подождите следующий цикл анализа.',
      data: null, studentId: student?.student_id ?? null,
    }
    return
  }
  breakdown.value = { open: true, loading: true, error: null, data: null, studentId: student.student_id }
  try {
    const { data } = await analytics.snapshotBreakdown(student.snapshot_id)
    breakdown.value = { open: true, loading: false, error: null, data, studentId: student.student_id }
  } catch (e) {
    breakdown.value = {
      open: true, loading: false,
      error: e.response?.data?.message || e.message || 'Не удалось загрузить разбор',
      data: null, studentId: student.student_id,
    }
  }
}
function closeBreakdown() {
  breakdown.value.open = false
}

const componentLabels = {
  presence:  'Присутствие',
  gaze:      'Взгляд',
  emotion:   'Эмоция',
  head_pose: 'Поза головы',
  posture:   'Поза тела',
}

const breakdownComponents = computed(() => {
  const data = breakdown.value.data
  if (!data) return []
  const bd = data.score_breakdown
  if (!bd || typeof bd !== 'object') {
    // Если ML не прислал готовый breakdown — собираем из raw_components
    return Object.entries(data.raw_components || {})
      .filter(([_, v]) => v != null)
      .map(([k, v]) => ({
        name:   k.replace(/_score$/, ''),
        label:  componentLabels[k.replace(/_score$/, '')] || k,
        score:  v,
        weight: null,
        reason: null,
      }))
  }
  return Object.entries(bd).map(([name, info]) => ({
    name,
    label:  componentLabels[name] || name,
    score:  info?.score ?? null,
    weight: info?.weight ?? null,
    reason: info?.reason ?? null,
  }))
})

// ── Timeline ──────────────────────────────────────────────────

const timeline = ref([])
const timelineLoading = ref(false)

async function loadTimeline() {
  if (!props.session?.id) return
  timelineLoading.value = true
  try {
    const { data } = await sessionsApi.timeline(props.session.id)
    timeline.value = (data?.data || data?.timeline || []).map(p => ({
      minute:    p.minute_at || p.minute || p.timestamp,
      avg_score: Number(p.avg_score ?? p.score ?? 0),
    })).filter(p => p.minute)
  } catch (e) {
    console.warn('timeline load failed', e)
    timeline.value = []
  } finally {
    timelineLoading.value = false
  }
}

const timelineWidth  = 600
const timelineHeight = 120
const timelineScaleY = computed(() => timelineHeight / 100)
const timelinePoints = computed(() => {
  if (!timeline.value.length) return ''
  return timeline.value.map((p, i) => {
    const x = (i / Math.max(1, timeline.value.length - 1)) * timelineWidth
    const y = timelineHeight - p.avg_score * timelineScaleY.value
    return `${x.toFixed(1)},${y.toFixed(1)}`
  }).join(' ')
})
const timelineCircles = computed(() => {
  return timeline.value.map((p, i) => ({
    x: (i / Math.max(1, timeline.value.length - 1)) * timelineWidth,
    y: timelineHeight - p.avg_score * timelineScaleY.value,
  }))
})

function formatMinute(m) {
  if (!m) return ''
  const d = new Date(m)
  if (isNaN(d.getTime())) return String(m)
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

let pollTimer
onMounted(() => {
  loadTimeline()
  pollTimer = setInterval(loadTimeline, 30000)
})
watch(() => props.session?.id, loadTimeline)
onBeforeUnmount(() => clearInterval(pollTimer))
</script>

<style scoped>
.session-detail { display:flex; flex-direction:column; gap:20px; }
.detail-header { display:flex; align-items:center; gap:20px; background:#111827; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px 20px; }
.back-btn { display:flex; align-items:center; gap:6px; padding:8px 12px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#94a3b8; cursor:pointer; font-size:13px; white-space:nowrap; transition:all 0.15s; }
.back-btn:hover { background:rgba(255,255,255,0.12); color:#f1f5f9; }
.back-btn svg { width:16px; height:16px; }
.session-info { flex:1; }
.session-info h2 { color:#f1f5f9; margin:0; font-size:18px; }
.session-subject { color:#94a3b8; font-size:13px; }
.session-avg { font-size:32px; font-weight:700; padding:8px 16px; border-radius:10px; }
.session-avg.success { color:#22c55e; background:rgba(34,197,94,0.1); }
.session-avg.warning { color:#f59e0b; background:rgba(245,158,11,0.1); }
.session-avg.danger  { color:#ef4444; background:rgba(239,68,68,0.1); }

.detail-body { display:grid; grid-template-columns: 1fr 320px; gap:20px; }

.left-col { display:flex; flex-direction:column; gap:20px; }

.students-section, .timeline-section, .stat-card, .emotions-card { background:#111827; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:18px; }
.section-title { color:#94a3b8; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px; }

.students-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:12px; }
.student-card { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:10px; padding:12px; cursor:pointer; text-align:left; color:inherit; font:inherit; transition:all 0.15s; }
.student-card:hover { transform:translateY(-2px); border-color:rgba(255,255,255,0.15); background:rgba(255,255,255,0.06); }
.student-card.high   { border-color:rgba(34,197,94,0.4); }
.student-card.medium { border-color:rgba(245,158,11,0.35); }
.student-card.low    { border-color:rgba(239,68,68,0.4); }
.student-score { font-size:24px; font-weight:700; color:#f1f5f9; }
.student-bar { height:4px; background:rgba(255,255,255,0.06); border-radius:2px; overflow:hidden; margin:6px 0; }
.bar-fill { height:100%; background:linear-gradient(90deg,#6366f1,#8b5cf6); border-radius:2px; }
.student-meta { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.meta-emoji { font-size:14px; }
.gaze-icon.on { color:#22c55e; }
.gaze-icon.off { color:#475569; }
.hand-up { font-size:13px; }
.attn-pill { display:inline-block; padding:2px 6px; border-radius:6px; font-size:10px; text-transform:uppercase; letter-spacing:0.4px; }
.attn-focused    { background:rgba(34,197,94,0.15);  color:#22c55e; }
.attn-distracted { background:rgba(245,158,11,0.15); color:#f59e0b; }
.attn-drowsy     { background:rgba(168,85,247,0.15); color:#a855f7; }
.attn-absent     { background:rgba(239,68,68,0.15);  color:#ef4444; }

.hint-line { color:#475569; font-size:11px; margin-top:10px; }

.timeline-loading, .timeline-empty { color:#64748b; font-size:13px; padding:6px 0; }
.timeline-chart { width:100%; height:120px; display:block; }
.grid-line { stroke:rgba(255,255,255,0.05); stroke-width:1; }
.timeline-line { fill:none; stroke:#8b5cf6; stroke-width:2; vector-effect:non-scaling-stroke; }
.timeline-dot  { fill:#a78bfa; }
.timeline-axis { display:flex; justify-content:space-between; color:#64748b; font-size:11px; margin-top:4px; }

.stats-section { display:flex; flex-direction:column; gap:12px; }
.stat-label { color:#94a3b8; font-size:12px; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
.stat-value { font-size:26px; font-weight:700; color:#f1f5f9; }
.stat-value.success { color:#22c55e; }
.stat-value.warning { color:#f59e0b; }
.stat-value.danger  { color:#ef4444; }

.emotions-list { display:flex; flex-direction:column; gap:10px; }
.emotion-row { display:flex; align-items:center; gap:8px; }
.emotion-emoji { width:18px; }
.emotion-name { width:90px; color:#cbd5e1; font-size:12px; }
.emotion-bar { flex:1; height:6px; background:rgba(255,255,255,0.06); border-radius:3px; overflow:hidden; }
.emotion-fill { height:100%; background:linear-gradient(90deg,#22c55e,#4ade80); }
.emotion-count { color:#cbd5e1; font-size:12px; min-width:18px; text-align:right; }

/* ── Popover ──────────────────────────────────────────────── */
.popover-mask { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:120; }
.popover { width:520px; max-width:92vw; max-height:85vh; overflow:auto; background:#0d1220; border:1px solid rgba(255,255,255,0.12); border-radius:14px; box-shadow:0 18px 50px rgba(0,0,0,0.6); }
.popover-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.08); }
.popover-header h3 { margin:0; color:#f1f5f9; font-size:16px; }
.popover-close { background:transparent; border:none; color:#94a3b8; font-size:18px; cursor:pointer; }
.popover-loading, .popover-error { padding:18px; color:#cbd5e1; }
.popover-body { padding:18px; display:flex; flex-direction:column; gap:18px; }
.popover-summary { display:flex; gap:30px; }
.popover-summary-label { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; }
.popover-summary-value { font-size:28px; font-weight:700; color:#f1f5f9; }
.popover-summary-value.high { color:#22c55e; }
.popover-summary-value.medium { color:#f59e0b; }
.popover-summary-value.low { color:#ef4444; }
.popover-warning { padding:10px 12px; border-radius:8px; background:rgba(245,158,11,0.12); color:#fde68a; font-size:12px; }
.popover-section-title { color:#94a3b8; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px; }
.popover-components { display:flex; flex-direction:column; gap:14px; }
.component-row { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.05); border-radius:10px; padding:10px 12px; }
.component-head { display:flex; align-items:center; gap:10px; }
.component-name  { flex:1; font-weight:600; color:#e2e8f0; font-size:13px; }
.component-weight { color:#64748b; font-size:11px; }
.component-score  { color:#cbd5e1; font-weight:700; font-size:13px; }
.component-bar { height:5px; background:rgba(255,255,255,0.06); border-radius:3px; overflow:hidden; margin-top:6px; }
.component-fill.high   { height:100%; background:#22c55e; }
.component-fill.medium { height:100%; background:#f59e0b; }
.component-fill.low    { height:100%; background:#ef4444; }
.component-reason { color:#94a3b8; font-size:12px; margin-top:6px; }

.quality-grid { display:flex; gap:18px; flex-wrap:wrap; color:#cbd5e1; font-size:12px; }
</style>
