<template>
  <div class="mini-chart">
    <Line v-if="hasData" :data="chartData" :options="chartOptions" />
    <div v-else class="mini-chart-empty">{{ emptyLabel }}</div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Filler,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler)

const props = defineProps({
  // массив чисел (0..100) или объектов { label, value }
  points:     { type: Array,  default: () => [] },
  maxPoints:  { type: Number, default: 10 },
  min:        { type: Number, default: 0 },
  max:        { type: Number, default: 100 },
  emptyLabel: { type: String, default: 'Нет данных' },
})

const normalized = computed(() =>
  props.points.map(p => (typeof p === 'number' ? { label: '', value: p } : p))
)

const tail = computed(() => normalized.value.slice(-props.maxPoints))

const hasData = computed(() => tail.value.length > 0)

const lineColor = computed(() => {
  if (!tail.value.length) return '#6366f1'
  const last = Number(tail.value[tail.value.length - 1]?.value) || 0
  if (last > 70) return '#22c55e'
  if (last >= 50) return '#f59e0b'
  return '#ef4444'
})

const fillColor = computed(() => {
  if (!tail.value.length) return 'rgba(99,102,241,0.18)'
  const last = Number(tail.value[tail.value.length - 1]?.value) || 0
  if (last > 70) return 'rgba(34,197,94,0.18)'
  if (last >= 50) return 'rgba(245,158,11,0.18)'
  return 'rgba(239,68,68,0.18)'
})

const chartData = computed(() => ({
  labels: tail.value.map(p => p.label ?? ''),
  datasets: [{
    data:            tail.value.map(p => Number(p.value) || 0),
    borderColor:     lineColor.value,
    backgroundColor: fillColor.value,
    borderWidth:     2,
    pointRadius:     0,
    pointHoverRadius: 3,
    tension:         0.35,
    fill:            true,
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  animation: { duration: 300, easing: 'easeOutQuart' },
  plugins: {
    legend:  { display: false },
    tooltip: {
      enabled: true,
      displayColors: false,
      callbacks: {
        title: items => items[0]?.label || '',
        label: ctx   => `${Number(ctx.parsed.y).toFixed(1)}%`,
      },
    },
  },
  scales: {
    x: { display: false },
    y: { display: false, min: props.min, max: props.max },
  },
  interaction: { mode: 'nearest', intersect: false },
}))
</script>

<style scoped>
.mini-chart {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 60px;
}
.mini-chart-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  font-size: 12px;
  color: #475569;
  font-style: italic;
}
</style>
