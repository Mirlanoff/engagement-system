<template>
  <div class="analytics-view">

    <!-- No active lesson -->
    <div v-if="activeSessions.length === 0" class="empty-state">
      <div class="empty-icon">📊</div>
      <h2 class="empty-title">Нет активного урока</h2>
      <p class="empty-desc">
        Перейдите в <span class="empty-tab">Обзор</span>, чтобы начать урок
      </p>
    </div>

    <!-- Active lesson(s): live analytics -->
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
import { computed, watch, onBeforeUnmount } from 'vue'
import { useEngagementStore } from '@/stores/engagement'
import LiveSessionAnalytics from '@/components/analytics/LiveSessionAnalytics.vue'

const engagementStore = useEngagementStore()

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
.empty-tab {
  color: #cbd5e1;
  font-weight: 600;
}

/* ── Active area ────────────────────────────────────────── */
.active-area {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
</style>
