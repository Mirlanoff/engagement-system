<template>
  <div class="analytics-view">
    <div class="coming-soon">
      <div class="cs-icon">📊</div>
      <h3>Аналитика</h3>
      <p>Heatmap по часам, сравнение классов, тренды студентов</p>
      <div class="cs-tags">
        <span>Heatmap день×час</span>
        <span>Сравнение классов</span>
        <span>Тренды</span>
        <span>AI инсайты</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.analytics-view { display:flex; align-items:center; justify-content:center; min-height:400px; }
.coming-soon { text-align:center; color:#475569; }
.cs-icon { font-size:48px; margin-bottom:16px; }
.coming-soon h3 { font-size:18px; font-weight:600; color:#64748b; margin:0 0 8px; }
.coming-soon p { font-size:13px; margin:0 0 20px; color:#475569; }
.cs-tags { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
.cs-tags span { padding:4px 12px; background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.2); border-radius:20px; font-size:12px; color:#a5b4fc; }
</style>
