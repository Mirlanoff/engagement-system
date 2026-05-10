<template>
  <div class="analytics-view">
    <div class="analytics-header">
      <div class="tabs">
        <button
          v-for="t in tabs"
          :key="t.id"
          :class="['tab', { active: activeTab === t.id }]"
          @click="activeTab = t.id"
          type="button"
        >{{ t.label }}</button>
      </div>
      <DateRangePicker />
    </div>

    <div class="tab-body">
      <KeepAlive>
        <component
          :is="activeComponent"
          :data="activeTab === 'insights' ? overviewData : null"
          @loaded="onCompareLoaded"
        />
      </KeepAlive>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, shallowRef } from 'vue'
import DateRangePicker from '@/components/analytics/DateRangePicker.vue'
import CompareTab      from '@/components/analytics/CompareTab.vue'
import TrendsTab       from '@/components/analytics/TrendsTab.vue'
import HeatmapTab      from '@/components/analytics/HeatmapTab.vue'
import InsightsTab     from '@/components/analytics/InsightsTab.vue'

const tabs = [
  { id: 'compare',  label: 'Сравнение классов', component: CompareTab  },
  { id: 'trends',   label: 'Тренды',            component: TrendsTab   },
  { id: 'heatmap',  label: 'Heatmap день×час',  component: HeatmapTab  },
  { id: 'insights', label: 'AI инсайты',        component: InsightsTab },
]

const activeTab    = ref('compare')
const overviewData = shallowRef(null)

const activeComponent = computed(() =>
  tabs.find(t => t.id === activeTab.value)?.component
)

function onCompareLoaded(payload) {
  overviewData.value = payload
}
</script>

<style scoped>
.analytics-view { display:flex; flex-direction:column; gap:16px; }

.analytics-header {
  display:flex; flex-wrap:wrap; gap:12px;
  align-items:center; justify-content:space-between;
}

.tabs { display:flex; gap:4px; padding:4px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:10px; flex-wrap:wrap; }
.tab {
  padding:8px 14px;
  background:transparent; border:none; border-radius:7px;
  color:#94a3b8; font-size:12.5px; font-weight:500; font-family:inherit;
  cursor:pointer; transition: all 0.15s; white-space:nowrap;
}
.tab:hover { color:#e2e8f0; background:rgba(255,255,255,0.05); }
.tab.active { background:rgba(99,102,241,0.18); color:#a5b4fc; }

.tab-body { min-height:280px; }
</style>
