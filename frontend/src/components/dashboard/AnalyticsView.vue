<template>
  <div class="analytics-view">

    <StartSessionModal
      v-if="showModal"
      @close="showModal = false"
      @started="onSessionStarted"
    />

    <!-- ── No active lesson ─────────────────────────────────── -->
    <div v-if="activeSessions.length === 0" class="empty-state">
      <div class="empty-icon">📊</div>
      <h2 class="empty-title">Нет активного урока</h2>
      <p class="empty-desc">
        Запустите урок, чтобы увидеть аналитику в реальном времени
      </p>
      <button class="start-btn" @click="showModal = true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <polygon points="6,4 20,12 6,20" fill="currentColor" stroke="none"/>
        </svg>
        Начать урок
      </button>
    </div>

    <!-- ── Active lesson(s): live analytics ─────────────────── -->
    <div v-else class="active-area">
      <LiveSessionAnalytics
        v-for="session in activeSessions"
        :key="session.id"
        :session="session"
        :class-avg="engagementStore.classAverages[session.id] || 0"
        :student-scores="engagementStore.studentScores[session.id] || {}"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useEngagementStore } from '@/stores/engagement'
import LiveSessionAnalytics from '@/components/analytics/LiveSessionAnalytics.vue'
import StartSessionModal    from './StartSessionModal.vue'

const engagementStore = useEngagementStore()
const showModal       = ref(false)

const activeSessions = computed(() => engagementStore.activeSessions || [])

// Subscribe to every active session so the live data flows in.
const subscribed = new Set()

watch(
  () => activeSessions.value.map(s => s.id).join(','),
  () => {
    for (const s of activeSessions.value) {
      if (s?.id && !subscribed.has(s.id)) {
        engagementStore.subscribeToSession(s.id)
        subscribed.add(s.id)
      }
    }
  },
  { immediate: true },
)

function onSessionStarted() {
  // refresh the active sessions list — WebSocket store + the SessionStarted event
  // will populate the rest.
  engagementStore.loadActiveSessions()
}

onBeforeUnmount(() => {
  subscribed.clear()
})
</script>

<style scoped>
.analytics-view {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* ── Empty state ────────────────────────────────────────── */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  gap: 14px;
  text-align: center;
}
.empty-icon { font-size: 56px; opacity: 0.85; }
.empty-title {
  font-size: 22px;
  font-weight: 600;
  color: #cbd5e1;
  margin: 0;
}
.empty-desc {
  font-size: 14px;
  color: #64748b;
  margin: 0;
  max-width: 460px;
}
.start-btn {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 14px 28px;
  background: linear-gradient(135deg,#6366f1,#8b5cf6);
  border: none;
  border-radius: 12px;
  color: white;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
  box-shadow: 0 8px 24px rgba(99,102,241,0.35);
  margin-top: 10px;
}
.start-btn:hover  { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(99,102,241,0.45); }
.start-btn:active { transform: translateY(0); }
.start-btn svg    { width: 18px; height: 18px; }

/* ── Active area ────────────────────────────────────────── */
.active-area {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
</style>
