<template>
  <div class="insights-tab">
    <div v-if="loading" class="state">
      <div class="spinner"></div>
      <p>Считаем инсайты...</p>
    </div>

    <div v-else-if="error" class="state error">
      <p>Ошибка загрузки</p>
      <button class="retry" @click="load">Повторить</button>
    </div>

    <div v-else-if="!hasOverview" class="state empty">
      <div class="empty-icon">🤖</div>
      <h3>Недостаточно данных</h3>
      <p>За выбранный период нет агрегатов для генерации инсайтов.</p>
    </div>

    <template v-else>
      <header class="insights-header">
        <h2>Автоматический анализ за период {{ periodLabel }}</h2>
        <p class="subtitle">Сводка собрана из агрегатов и снэпшотов школы.</p>
      </header>

      <section v-for="section in sections" :key="section.id" class="insight-section">
        <div class="section-title">
          <span class="section-icon">{{ section.icon }}</span>
          <h3>{{ section.title }}</h3>
        </div>
        <ul class="section-list">
          <li
            v-for="(item, i) in section.items"
            :key="i"
            class="section-item"
            :class="item.tone"
          >
            <span class="bullet">{{ item.bullet || '•' }}</span>
            <span class="text">{{ item.text }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, inject } from 'vue'
import { analytics } from '@/api'
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'

const { from, to } = useAnalyticsFilters()

const props = defineProps({
  data: { type: Object, default: null },
})

const overview = ref(props.data)
const emotions = ref(null)
const loading  = ref(false)
const error    = ref(false)

const EMOTION_LABEL = {
  neutral: 'нейтрально', calm: 'спокойствие',
  happy: 'радость', joy: 'радость', positive: 'позитив',
  surprise: 'удивление', surprised: 'удивление',
  sad: 'грусть', sadness: 'грусть',
  fear: 'тревога',
  angry: 'злость', anger: 'злость',
  disgust: 'отвращение', confused: 'смущение',
}

const hasOverview = computed(() => Boolean(overview.value?.summary))

function fmtPct(v, digits = 1) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—'
  return `${Number(v).toFixed(digits)}%`
}

function fmtDate(iso) {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

const periodLabel = computed(() => `${fmtDate(from.value)} — ${fmtDate(to.value)}`)

const sections = computed(() => {
  const out = []
  if (!hasOverview.value) return out

  const d         = overview.value
  const summary   = d.summary || {}
  const classes   = d.classrooms || []
  const trend     = d.daily_trend || []
  const schoolAvg = Number(summary.school_avg) || 0
  const sessions  = classes.reduce((s, c) => s + (Number(c.sessions_count) || 0), 0)

  // Distribution totals
  const dist = classes.reduce((acc, c) => {
    acc.high   += Number(c.distribution?.high)   || 0
    acc.medium += Number(c.distribution?.medium) || 0
    acc.low    += Number(c.distribution?.low)    || 0
    return acc
  }, { high: 0, medium: 0, low: 0 })
  const distTotal = dist.high + dist.medium + dist.low
  const lowPct  = distTotal ? Math.round((dist.low  / distTotal) * 100) : 0
  const highPct = distTotal ? Math.round((dist.high / distTotal) * 100) : 0

  // ── Общая картина ─────────────────────────────────────────
  const generalItems = [
    {
      tone: schoolAvg >= 60 ? 'success' : schoolAvg >= 50 ? 'warning' : 'danger',
      text: schoolAvg
        ? `Средняя вовлечённость по школе: ${fmtPct(schoolAvg)} ${schoolAvg >= 60 ? '— выше нормы 60%' : schoolAvg >= 50 ? '— на уровне нормы' : '— ниже нормы 60%'}.`
        : 'Средняя вовлечённость по школе пока недоступна.',
    },
    {
      tone: '',
      text: `Проведено ${sessions} ${sessionsWord(sessions)} в ${classes.length} ${classroomsWord(classes.length)} с активностью${
        summary.total_classrooms ? ` из ${summary.total_classrooms} всего` : ''
      }.`,
    },
  ]
  if (emotions.value?.total_snapshots) {
    generalItems.push({
      tone: '',
      text: `Сохранено ${emotions.value.total_snapshots} наблюдений с распознанным лицом.`,
    })
  }
  out.push({ id: 'general', icon: '▪', title: 'Общая картина', items: generalItems })

  // ── Достижения ────────────────────────────────────────────
  const ach = []
  if (summary.best_classroom) {
    const b = summary.best_classroom
    ach.push({
      tone: 'success',
      text: `${b.classroom_name} — лучший за период (средний ${fmtPct(b.avg_score)}, ${b.sessions_count} ${sessionsWord(b.sessions_count)}).`,
    })
  }
  if (summary.best_classroom && Number(summary.best_classroom.avg_score) >= 65) {
    ach.push({
      tone: 'success',
      text: `${summary.best_classroom.classroom_name} стабильно держит внимание выше 65%.`,
    })
  }
  // Peak day
  const sortedTrend = [...trend].sort((a, b) => (Number(b.avg_score) || 0) - (Number(a.avg_score) || 0))
  if (sortedTrend.length && Number(sortedTrend[0].avg_score) > 0) {
    const peak = sortedTrend[0]
    ach.push({
      tone: 'success',
      text: `Максимальная средняя за день: ${fmtPct(peak.avg_score)} (${fmtDate(peak.date)}).`,
    })
  }
  if (highPct >= 30) {
    ach.push({
      tone: 'success',
      text: `${highPct}% времени вовлечённость была выше 75% — отличный показатель.`,
    })
  }
  if (ach.length === 0) ach.push({ tone: '', text: 'За этот период нет ярко выраженных достижений.' })
  out.push({ id: 'achievements', icon: '🏆', title: 'Достижения', items: ach })

  // ── На что обратить внимание ──────────────────────────────
  const attention = []
  if (lowPct > 0) {
    attention.push({
      tone: lowPct > 25 ? 'danger' : 'warning',
      text: `${lowPct}% времени вовлечённость ниже 50%.`,
    })
  }
  if (summary.worst_classroom && classes.length > 1) {
    const w = summary.worst_classroom
    attention.push({
      tone: 'warning',
      text: `${w.classroom_name} требует внимания: средний ${fmtPct(w.avg_score)}, макс ${fmtPct(w.max_score)}.`,
    })
  }
  if (emotions.value?.gaze) {
    const g = emotions.value.gaze
    const totalGaze = (g.on_board || 0) + (g.right || 0) + (g.left || 0)
    if (totalGaze > 0) {
      const awayPct = Math.round(((g.right + g.left) / totalGaze) * 100)
      if (awayPct >= 20) {
        attention.push({
          tone: awayPct >= 40 ? 'danger' : 'warning',
          text: `${awayPct}% наблюдений — взгляд не на доску. Рекомендуется чередовать активности каждые 10–15 минут.`,
        })
      }
    }
  }
  if (emotions.value?.emotions) {
    const negativeKeys = ['sad', 'sadness', 'angry', 'anger', 'fear', 'disgust']
    const total = Object.values(emotions.value.emotions).reduce((s, v) => s + Number(v), 0)
    const negative = negativeKeys.reduce((s, k) => s + (Number(emotions.value.emotions[k]) || 0), 0)
    if (total > 0) {
      const negPct = Math.round((negative / total) * 100)
      if (negPct >= 10) {
        attention.push({
          tone: 'warning',
          text: `Доля негативных эмоций ${negPct}% — есть смысл проверить нагрузку и темп урока.`,
        })
      }
    }
  }
  if (attention.length === 0) attention.push({ tone: '', text: 'Серьёзных рисков по периоду не выявлено.' })
  out.push({ id: 'attention', icon: '⚠️', title: 'На что обратить внимание', items: attention })

  // ── Динамика ──────────────────────────────────────────────
  const dyn = []
  if (trend.length >= 2) {
    const half = Math.floor(trend.length / 2)
    const firstHalf  = trend.slice(0, half).map(p => Number(p.avg_score) || 0).filter(v => v > 0)
    const secondHalf = trend.slice(half).map(p => Number(p.avg_score) || 0).filter(v => v > 0)
    if (firstHalf.length && secondHalf.length) {
      const a = firstHalf.reduce((s, v) => s + v, 0) / firstHalf.length
      const b = secondHalf.reduce((s, v) => s + v, 0) / secondHalf.length
      const delta = b - a
      if (Math.abs(delta) < 1) {
        dyn.push({ tone: '', text: `Вовлечённость стабильна (${fmtPct(a)} → ${fmtPct(b)}).` })
      } else if (delta > 0) {
        dyn.push({ tone: 'success', text: `Положительная динамика: ${fmtPct(a)} → ${fmtPct(b)} (+${delta.toFixed(1)}%).` })
      } else {
        dyn.push({ tone: 'warning', text: `Снижение: ${fmtPct(a)} → ${fmtPct(b)} (${delta.toFixed(1)}%).` })
      }
    }
  }
  if (emotions.value?.emotions) {
    const dominantKey = Object.entries(emotions.value.emotions)
      .map(([k, v]) => [k, Number(v)])
      .sort((a, b) => b[1] - a[1])[0]?.[0]
    if (dominantKey) {
      dyn.push({
        tone: '',
        text: `Преобладающая эмоция за период — ${EMOTION_LABEL[dominantKey.toLowerCase()] || dominantKey}.`,
      })
    }
  }
  if (dyn.length === 0) dyn.push({ tone: '', text: 'Данных для сравнения по периоду пока недостаточно.' })
  out.push({ id: 'dynamics', icon: '📈', title: 'Динамика', items: dyn })

  return out
})

function sessionsWord(n) {
  const m = Math.abs(Number(n) || 0) % 100
  const lastDigit = m % 10
  if (m >= 11 && m <= 14) return 'уроков'
  if (lastDigit === 1)    return 'урок'
  if (lastDigit >= 2 && lastDigit <= 4) return 'урока'
  return 'уроков'
}

function classroomsWord(n) {
  const m = Math.abs(Number(n) || 0) % 100
  const lastDigit = m % 10
  if (m >= 11 && m <= 14) return 'классах'
  if (lastDigit === 1)    return 'классе'
  if (lastDigit >= 2 && lastDigit <= 4) return 'классах'
  return 'классах'
}

async function load() {
  loading.value = true
  error.value   = false
  try {
    const [ov, em] = await Promise.all([
      analytics.overview({ from: from.value, to: to.value }),
      analytics.emotions({ from: from.value, to: to.value }).catch(() => null),
    ])
    overview.value = ov.data
    emotions.value = em?.data || null
  } catch (e) {
    console.warn('insights load failed', e)
    error.value = true
  } finally {
    loading.value = false
  }
}

watch(() => props.data, v => {
  if (v) overview.value = v
})

onMounted(() => {
  // Если родитель уже передал overview — всё равно догружаем эмоции
  if (!overview.value) {
    load()
  } else {
    analytics.emotions({ from: from.value, to: to.value })
      .then(({ data }) => { emotions.value = data })
      .catch(() => { emotions.value = null })
  }
})

watch([from, to], load)

const refreshTrigger = inject('analyticsRefreshTrigger', ref(0))
watch(refreshTrigger, () => load())
</script>

<style scoped>
.insights-tab { display:flex; flex-direction:column; gap:16px; }
.state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px; gap:12px; color:#94a3b8; text-align:center; }
.state.error { color:#fca5a5; }
.state.empty .empty-icon { font-size:40px; }
.state h3 { margin:0; font-size:16px; color:#e2e8f0; }
.state p { margin:0; font-size:13px; color:#94a3b8; }
.retry { padding:8px 16px; background:rgba(99,102,241,0.15); color:#a5b4fc; border:1px solid rgba(99,102,241,0.3); border-radius:8px; cursor:pointer; font-size:12px; font-family:inherit; }
.retry:hover { background:rgba(99,102,241,0.25); }
.spinner { width:28px; height:28px; border:3px solid rgba(255,255,255,0.08); border-top-color:#6366f1; border-radius:50%; animation:spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.insights-header {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  padding: 16px 18px;
}
.insights-header h2 { margin: 0; font-size: 15px; font-weight: 600; color: #f1f5f9; }
.insights-header .subtitle { margin: 4px 0 0; font-size: 12px; color: #64748b; }

.insight-section {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  padding: 16px 18px;
}
.section-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}
.section-icon { font-size: 16px; }
.section-title h3 { font-size: 14px; font-weight: 600; color: #e2e8f0; margin: 0; }

.section-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
.section-item {
  display: grid;
  grid-template-columns: 16px 1fr;
  align-items: baseline;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.5;
  color: #cbd5e1;
}
.section-item.success { color: #d1fae5; border-left: 3px solid #22c55e; padding-left: 12px; }
.section-item.warning { color: #fde68a; border-left: 3px solid #f59e0b; padding-left: 12px; }
.section-item.danger  { color: #fecaca; border-left: 3px solid #ef4444; padding-left: 12px; }
.bullet { color: #475569; font-weight: 700; }
.text { color: inherit; }
</style>
