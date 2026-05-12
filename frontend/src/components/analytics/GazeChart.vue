<template>
  <div class="gaze-chart">
    <div class="chart-card-header">
      <h3>Направление взгляда</h3>
      <span class="hint" v-if="total > 0">{{ total }} наблюдений</span>
    </div>

    <div v-if="total === 0" class="empty">
      <span class="empty-icon">👁</span>
      <span>Нет данных о взгляде</span>
    </div>

    <ul v-else class="bars">
      <li v-for="row in rows" :key="row.key" class="bar-row">
        <div class="bar-head">
          <span class="bar-icon">{{ row.icon }}</span>
          <span class="bar-label">{{ row.label }}</span>
          <span class="bar-value">{{ row.percent }}%</span>
        </div>
        <div class="bar-track">
          <div
            class="bar-fill"
            :class="row.tone"
            :style="{ width: row.percent + '%' }"
          ></div>
        </div>
        <div class="bar-sub">{{ row.value }} из {{ total }}</div>
      </li>
    </ul>

    <p v-if="total > 0" class="hint-text">
      На доску — взгляд в пределах ±15° от центра.
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  gaze: {
    type: Object,
    default: () => ({ on_board: 0, right: 0, left: 0, unknown: 0 }),
  },
})

const ORDER = [
  { key: 'on_board', label: 'На доску',     icon: '👁', tone: 'success' },
  { key: 'right',    label: 'Вправо',       icon: '➜', tone: 'warning' },
  { key: 'left',     label: 'Влево',        icon: '⬅', tone: 'warning' },
  { key: 'unknown',  label: 'Не определено', icon: '·', tone: 'muted'  },
]

const total = computed(() =>
  ORDER.reduce((s, c) => s + (Number(props.gaze?.[c.key]) || 0), 0)
)

const rows = computed(() =>
  ORDER
    .map(c => {
      const value = Number(props.gaze?.[c.key]) || 0
      return {
        ...c,
        value,
        percent: total.value > 0 ? Math.round((value / total.value) * 100) : 0,
      }
    })
    // Прячем "Не определено", если их меньше 1% — лишний шум
    .filter(r => r.key !== 'unknown' || r.percent >= 1)
)
</script>

<style scoped>
.gaze-chart {
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

.bars { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px; }
.bar-row { display: flex; flex-direction: column; gap: 4px; }
.bar-head {
  display: grid;
  grid-template-columns: 20px 1fr auto;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #cbd5e1;
}
.bar-icon { font-size: 15px; }
.bar-label { color: #e2e8f0; }
.bar-value {
  font-weight: 600;
  color: #f1f5f9;
  font-variant-numeric: tabular-nums;
}
.bar-track {
  height: 8px;
  background: rgba(255,255,255,0.06);
  border-radius: 4px;
  overflow: hidden;
}
.bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.4s ease;
}
.bar-fill.success { background: linear-gradient(90deg, #16a34a, #22c55e); }
.bar-fill.warning { background: linear-gradient(90deg, #d97706, #f59e0b); }
.bar-fill.danger  { background: linear-gradient(90deg, #dc2626, #ef4444); }
.bar-fill.muted   { background: rgba(148,163,184,0.4); }

.bar-sub {
  font-size: 11px;
  color: #64748b;
  font-variant-numeric: tabular-nums;
}

.hint-text {
  margin: 4px 0 0;
  font-size: 11px;
  color: #64748b;
  font-style: italic;
}
</style>
