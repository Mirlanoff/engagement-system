<template>
  <div class="history-view">
    <header class="hv-header">
      <h2 class="hv-title">История уроков</h2>
      <button
        class="hv-refresh"
        :class="{ busy: loading }"
        :disabled="loading"
        @click="load()"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
          <path d="M4 4v5h5M20 20v-5h-5M5.07 9A7.5 7.5 0 0118.93 7.07M18.93 15A7.5 7.5 0 015.07 16.93"/>
        </svg>
        {{ loading ? 'Обновление…' : 'Обновить' }}
      </button>
    </header>

    <!-- Loading -->
    <div v-if="loading && completed.length === 0" class="hv-loading">
      <div v-for="i in 4" :key="i" class="skel-card"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="hv-error">
      <div class="error-text">Не удалось загрузить историю уроков</div>
      <button class="retry-btn" @click="load()">Повторить</button>
    </div>

    <!-- Empty -->
    <div v-else-if="completed.length === 0" class="hv-empty">
      <div class="empty-icon">🕐</div>
      <div class="empty-title">Пока нет завершённых уроков</div>
      <div class="empty-desc">
        Когда вы завершите урок, он появится здесь со всей статистикой
      </div>
    </div>

    <!-- Cards -->
    <div v-else class="cards-grid">
      <SessionCard
        v-for="s in completed"
        :key="s.id"
        :session="s"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { sessions as sessionsApi } from '@/api'
import SessionCard from './SessionCard.vue'

const completed = ref([])
const loading   = ref(false)
const error     = ref(false)

async function load() {
  loading.value = true
  error.value   = false
  try {
    const { data } = await sessionsApi.list({ status: 'completed', per_page: 50 })
    const list = Array.isArray(data?.data) ? data.data : []
    completed.value = [...list].sort((a, b) => {
      const tA = a.started_at ? new Date(a.started_at).getTime() : 0
      const tB = b.started_at ? new Date(b.started_at).getTime() : 0
      return tB - tA
    })
  } catch (e) {
    console.warn('[HistoryView] load failed', e)
    error.value = true
    completed.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.history-view {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.hv-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 4px;
}
.hv-title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: #f1f5f9;
}
.hv-refresh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: #cbd5e1;
  font-size: 12.5px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.hv-refresh:hover:not(:disabled) { background: rgba(255,255,255,0.09); color: #f1f5f9; }
.hv-refresh:disabled { opacity: 0.6; cursor: default; }
.hv-refresh.busy svg { animation: spin 0.9s linear infinite; }
.hv-refresh svg { width: 14px; height: 14px; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* ── Loading skeleton ───────────────────────────────────── */
.hv-loading { display: flex; flex-direction: column; gap: 10px; }
.skel-card {
  height: 92px;
  background: linear-gradient(90deg, rgba(255,255,255,0.04), rgba(255,255,255,0.07), rgba(255,255,255,0.04));
  background-size: 200% 100%;
  border-radius: 12px;
  animation: shimmer 1.4s ease-in-out infinite;
}
@keyframes shimmer {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ── Error ──────────────────────────────────────────────── */
.hv-error {
  padding: 32px 18px;
  text-align: center;
  background: rgba(239,68,68,0.08);
  border: 1px solid rgba(239,68,68,0.3);
  border-radius: 12px;
  color: #fca5a5;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.retry-btn {
  padding: 7px 14px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: #f1f5f9;
  font-size: 13px;
  cursor: pointer;
  font-family: inherit;
}
.retry-btn:hover { background: rgba(255,255,255,0.1); }

/* ── Empty ──────────────────────────────────────────────── */
.hv-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  gap: 8px;
  text-align: center;
}
.empty-icon { font-size: 48px; opacity: 0.75; }
.empty-title { font-size: 16px; font-weight: 600; color: #cbd5e1; }
.empty-desc  { font-size: 13px; color: #64748b; max-width: 360px; }

/* ── Cards grid ─────────────────────────────────────────── */
.cards-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
@media (min-width: 1100px) {
  .cards-grid { grid-template-columns: 1fr 1fr; }
}
@media (min-width: 1600px) {
  .cards-grid { grid-template-columns: 1fr 1fr 1fr; }
}
</style>
