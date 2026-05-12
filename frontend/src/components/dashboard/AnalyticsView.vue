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
      <div class="header-right">
        <div class="live-indicator" :class="liveClass" :title="liveTitle">
          <span class="status-dot" :class="liveClass"></span>
          <span class="live-text">{{ liveLabel }}</span>
          <span v-if="lastUpdateLabel" class="live-time">· {{ lastUpdateLabel }}</span>
        </div>
        <DateRangePicker />
      </div>
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
import { ref, computed, shallowRef, provide, onMounted, onBeforeUnmount } from 'vue'
import DateRangePicker from '@/components/analytics/DateRangePicker.vue'
import CompareTab      from '@/components/analytics/CompareTab.vue'
import TrendsTab       from '@/components/analytics/TrendsTab.vue'
import HeatmapTab      from '@/components/analytics/HeatmapTab.vue'
import InsightsTab     from '@/components/analytics/InsightsTab.vue'

const tabs = [
  { id: 'compare',  label: 'Обзор периода',     component: CompareTab  },
  { id: 'trends',   label: 'Тренды',            component: TrendsTab   },
  { id: 'heatmap',  label: 'Эмоции и внимание', component: HeatmapTab  },
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

// ── Real-time refresh trigger (provided to all tabs) ─────────────
const refreshTrigger = ref(0)
provide('analyticsRefreshTrigger', refreshTrigger)

// ── WebSocket / polling state ────────────────────────────────────
const isConnected  = ref(false)
const lastUpdateAt = ref(null)
let   echo         = null
let   echoChannel  = null
let   pollTimer    = null

const POLL_INTERVAL_MS = 30000

const liveClass = computed(() => {
  if (isConnected.value) return 'live'
  if (pollTimer)         return 'warn'
  return 'err'
})

const liveLabel = computed(() => {
  if (isConnected.value) return 'Live'
  if (pollTimer)         return 'Polling'
  return 'Offline'
})

const liveTitle = computed(() => {
  if (isConnected.value) return 'WebSocket подключён — данные обновляются в реальном времени'
  if (pollTimer)         return 'WebSocket недоступен — обновление каждые 30 секунд'
  return 'Подключение не установлено'
})

const lastUpdateLabel = computed(() => {
  if (!lastUpdateAt.value) return ''
  const d = lastUpdateAt.value
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  const ss = String(d.getSeconds()).padStart(2, '0')
  return `${hh}:${mm}:${ss}`
})

function triggerRefresh() {
  refreshTrigger.value += 1
  lastUpdateAt.value = new Date()
}

function startPolling() {
  if (pollTimer) return
  pollTimer = setInterval(triggerRefresh, POLL_INTERVAL_MS)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function connectAnalyticsWebSocket() {
  try {
    const { default: Echo }   = await import('laravel-echo')
    const { default: Pusher } = await import('pusher-js')
    window.Pusher = Pusher

    const isHttps = window.location.protocol === 'https:'
    const wsPort  = Number(window.location.port) || (isHttps ? 443 : 80)

    echo = new Echo({
      broadcaster:       'pusher',
      key:               import.meta.env.VITE_PUSHER_APP_KEY || 'engagement_key',
      wsHost:            window.location.hostname,
      wsPort:            wsPort,
      wssPort:           wsPort,
      forceTLS:          isHttps,
      disableStats:      true,
      cluster:           'mt1',
      enabledTransports: isHttps ? ['wss'] : ['ws', 'wss'],
    })

    const connector = echo.connector?.pusher?.connection
    if (connector?.bind) {
      connector.bind('connected',    () => { isConnected.value = true;  stopPolling() })
      connector.bind('disconnected', () => { isConnected.value = false; startPolling() })
      connector.bind('unavailable',  () => { isConnected.value = false; startPolling() })
      connector.bind('failed',       () => { isConnected.value = false; startPolling() })
    }

    echoChannel = echo.channel('analytics')
    echoChannel.listen('.aggregate.updated', () => {
      isConnected.value = true
      stopPolling()
      triggerRefresh()
    })
  } catch (e) {
    console.warn('[Analytics WS] Failed to connect:', e)
    isConnected.value = false
    startPolling()
  }
}

onMounted(() => {
  connectAnalyticsWebSocket()
})

onBeforeUnmount(() => {
  try {
    if (echoChannel) {
      echoChannel.stopListening('.aggregate.updated')
    }
    echo?.leave('analytics')
    echo?.disconnect()
  } catch (e) {
    console.warn('[Analytics WS] cleanup error:', e)
  }
  echoChannel       = null
  echo              = null
  isConnected.value = false
  stopPolling()
})
</script>

<style scoped>
.analytics-view { display:flex; flex-direction:column; gap:16px; }

.analytics-header {
  display:flex; flex-wrap:wrap; gap:12px;
  align-items:center; justify-content:space-between;
}

.header-right { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

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

.live-indicator {
  display:flex; align-items:center; gap:6px;
  padding:6px 10px;
  background:rgba(255,255,255,0.04);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:999px;
  font-size:11.5px; color:#94a3b8;
  font-variant-numeric: tabular-nums;
  user-select: none;
}
.live-indicator.live { color:#86efac; border-color:rgba(34,197,94,0.25); }
.live-indicator.warn { color:#fcd34d; border-color:rgba(245,158,11,0.25); }
.live-indicator.err  { color:#fca5a5; border-color:rgba(239,68,68,0.25); }

.live-text { font-weight:600; letter-spacing:0.02em; }
.live-time { opacity:0.8; }

.status-dot {
  width:8px; height:8px; border-radius:50%;
  background:#475569;
  flex-shrink:0;
}
.status-dot.live { background:#22c55e; box-shadow:0 0 8px #22c55e; animation:pulse 1.5s infinite; }
.status-dot.warn { background:#f59e0b; }
.status-dot.err  { background:#ef4444; }

@keyframes pulse {
  0%,100% { opacity: 1; }
  50% { opacity: 0.4; }
}
</style>
