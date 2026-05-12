<template>
  <div class="date-range">
    <label class="dr-label">
      <span>С</span>
      <input type="date" :value="from" :max="to" @change="onFrom" />
    </label>
    <label class="dr-label">
      <span>По</span>
      <input type="date" :value="to" :min="from" @change="onTo" />
    </label>
  </div>
</template>

<script setup>
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters'

const { from, to, setRange } = useAnalyticsFilters()

function onFrom(e) {
  const v = e.target.value
  if (v) setRange(v, to.value)
}
function onTo(e) {
  const v = e.target.value
  if (v) setRange(from.value, v)
}
</script>

<style scoped>
.date-range { display:flex; gap:10px; align-items:center; }
.dr-label { display:flex; align-items:center; gap:6px; font-size:12px; color:#94a3b8; }
.dr-label input {
  padding:6px 10px; background:rgba(255,255,255,0.05);
  border:1px solid rgba(255,255,255,0.1); border-radius:8px;
  color:#f1f5f9; font-size:12px; font-family:inherit;
  color-scheme: dark;
}
.dr-label input:focus { outline:none; border-color:#6366f1; }
</style>
