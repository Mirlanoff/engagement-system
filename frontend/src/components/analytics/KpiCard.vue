<template>
  <div class="kpi-card">
    <div class="kpi-label">{{ label }}</div>
    <div class="kpi-value" :class="tone">{{ value }}</div>
    <div class="kpi-sub" v-if="sub">{{ sub }}</div>
  </div>
</template>

<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  sub:   { type: String, default: '' },
  tone:  { type: String, default: '' },
})
</script>

<style scoped>
.kpi-card {
  background:rgba(255,255,255,0.04);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  padding:16px 18px;
  display:flex; flex-direction:column; gap:4px;
  min-height:96px;
}
.kpi-label { font-size:12px; color:#94a3b8; font-weight:500; }
.kpi-value { font-size:26px; font-weight:700; color:#f1f5f9; letter-spacing:-0.5px; }
.kpi-value.success { color:#22c55e; }
.kpi-value.warning { color:#f59e0b; }
.kpi-value.danger  { color:#ef4444; }
.kpi-sub { font-size:11px; color:#64748b; }
</style>
