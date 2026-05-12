<template>
  <div class="emotion-chart">
    <div class="chart-card-header">
      <h3>Эмоции за период</h3>
      <span class="hint" v-if="total > 0">{{ total }} наблюдений</span>
    </div>

    <div v-if="!hasData" class="empty">
      <span class="empty-icon">😐</span>
      <span>Нет данных об эмоциях</span>
    </div>

    <div v-else class="chart-body">
      <div class="chart-wrap">
        <Doughnut :data="chartData" :options="chartOptions" />
        <div class="chart-center">
          <span class="center-emoji">{{ dominantEmoji }}</span>
          <span class="center-label">{{ dominantLabel }}</span>
          <span class="center-value">{{ dominantPercent }}%</span>
        </div>
      </div>

      <ul class="legend">
        <li v-for="row in legendRows" :key="row.key" class="legend-row">
          <span class="dot" :style="{ background: row.color }"></span>
          <span class="emoji">{{ row.emoji }}</span>
          <span class="name">{{ row.label }}</span>
          <span class="pct">{{ row.percent }}%</span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
} from 'chart.js'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
  emotions: { type: Object, default: () => ({}) },
})

const EMOTION_META = {
  neutral:   { emoji: '😐', label: 'Нейтрально',  color: '#94a3b8' },
  calm:      { emoji: '😐', label: 'Спокойствие', color: '#94a3b8' },
  happy:     { emoji: '😊', label: 'Радость',     color: '#22c55e' },
  joy:       { emoji: '😊', label: 'Радость',     color: '#22c55e' },
  positive:  { emoji: '😊', label: 'Позитив',     color: '#22c55e' },
  surprise:  { emoji: '😮', label: 'Удивление',   color: '#6366f1' },
  surprised: { emoji: '😮', label: 'Удивление',   color: '#6366f1' },
  sad:       { emoji: '😟', label: 'Грусть',      color: '#3b82f6' },
  sadness:   { emoji: '😟', label: 'Грусть',      color: '#3b82f6' },
  fear:      { emoji: '😨', label: 'Тревога',     color: '#a855f7' },
  angry:     { emoji: '😠', label: 'Злость',      color: '#ef4444' },
  anger:     { emoji: '😠', label: 'Злость',      color: '#ef4444' },
  disgust:   { emoji: '🤢', label: 'Отвращение',  color: '#84cc16' },
  confused:  { emoji: '🤔', label: 'Смущение',    color: '#eab308' },
}

const FALLBACK_COLORS = ['#06b6d4', '#f97316', '#ec4899', '#0ea5e9', '#14b8a6']

const rows = computed(() => {
  const entries = Object.entries(props.emotions || {})
    .map(([key, value]) => [String(key).toLowerCase(), Number(value) || 0])
    .filter(([, v]) => v > 0)
    .sort((a, b) => b[1] - a[1])

  const sum = entries.reduce((s, [, v]) => s + v, 0)
  if (sum === 0) return []

  return entries.map(([key, value], i) => {
    const meta = EMOTION_META[key] || {
      emoji: '🙂',
      label: key.charAt(0).toUpperCase() + key.slice(1),
      color: FALLBACK_COLORS[i % FALLBACK_COLORS.length],
    }
    return {
      key,
      value,
      percent: sum > 0 ? Math.round((value / sum) * 100) : 0,
      emoji: meta.emoji,
      label: meta.label,
      color: meta.color,
    }
  })
})

const total = computed(() => rows.value.reduce((s, r) => s + r.value, 0))
const hasData = computed(() => rows.value.length > 0)

const legendRows = computed(() => rows.value.slice(0, 6))

const dominantEmoji   = computed(() => rows.value[0]?.emoji  ?? '😐')
const dominantLabel   = computed(() => rows.value[0]?.label  ?? '—')
const dominantPercent = computed(() => rows.value[0]?.percent ?? 0)

const chartData = computed(() => ({
  labels: rows.value.map(r => r.label),
  datasets: [{
    data: rows.value.map(r => r.value),
    backgroundColor: rows.value.map(r => r.color),
    borderColor: 'rgba(15,23,42,0.6)',
    borderWidth: 2,
    hoverOffset: 6,
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: '64%',
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: ctx => {
          const row = rows.value[ctx.dataIndex]
          return row ? ` ${row.label}: ${row.percent}% (${row.value})` : ''
        },
      },
    },
  },
}))
</script>

<style scoped>
.emotion-chart {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.chart-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.chart-card-header h3 { font-size: 14px; font-weight: 600; color: #e2e8f0; margin: 0; }
.chart-card-header .hint { font-size: 12px; color: #64748b; }

.empty {
  padding: 40px 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  color: #64748b;
  font-size: 13px;
}
.empty-icon { font-size: 32px; }

.chart-body {
  display: grid;
  grid-template-columns: minmax(180px, 240px) 1fr;
  gap: 16px;
  align-items: center;
}

.chart-wrap {
  position: relative;
  height: 220px;
  width: 100%;
}
.chart-center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  gap: 1px;
}
.center-emoji { font-size: 28px; }
.center-label { font-size: 12px; color: #94a3b8; }
.center-value {
  font-size: 22px;
  font-weight: 700;
  color: #f1f5f9;
  letter-spacing: -0.5px;
  font-variant-numeric: tabular-nums;
}

.legend { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.legend-row {
  display: grid;
  grid-template-columns: 10px 18px 1fr auto;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  border-radius: 6px;
  font-size: 13px;
  color: #cbd5e1;
}
.legend-row:hover { background: rgba(255,255,255,0.04); }
.dot { width: 10px; height: 10px; border-radius: 50%; }
.emoji { font-size: 14px; line-height: 1; }
.name { color: #e2e8f0; }
.pct {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: #94a3b8;
}

@media (max-width: 760px) {
  .chart-body { grid-template-columns: 1fr; }
  .chart-wrap { height: 200px; }
}
</style>
