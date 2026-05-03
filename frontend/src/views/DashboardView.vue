<template>
  <div class="dashboard">
    <aside class="sidebar">
      <div class="sidebar-logo">
        <div class="logo-icon">
          <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M6 20c0-3.314 2.686-6 6-6s6 2.686 6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </div>
        <span class="logo-text">EngageAI</span>
      </div>
      <nav class="sidebar-nav">
        <button v-for="item in navItems" :key="item.id"
          :class="['nav-item', { active: activeView === item.id }]"
          @click="activeView = item.id">
          <span class="nav-icon" v-html="item.icon"></span>
          <span class="nav-label">{{ item.label }}</span>
          <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
        </button>
      </nav>
      <div class="sidebar-footer">
        <div class="user-avatar">{{ userInitials }}</div>
        <div class="user-info">
          <div class="user-name">{{ authStore.user?.name }}</div>
          <div class="user-role">{{ roleLabel }}</div>
        </div>
        <button class="logout-btn" @click="handleLogout">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        </button>
      </div>
    </aside>

    <main class="main-content">
      <header class="content-header">
        <div class="header-left">
          <h1 class="page-title">{{ pageTitle[activeView] }}</h1>
          <div class="live-indicator" v-if="engagementStore.isConnected">
            <span class="live-dot"></span>Live
          </div>
        </div>
        <div class="header-right">
          <span class="header-time">{{ currentTime }}</span>
          <button class="alert-bell" @click="activeView = 'alerts'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            <span v-if="engagementStore.alertCount > 0" class="bell-badge">{{ engagementStore.alertCount }}</span>
          </button>
        </div>
      </header>

      <div class="content-body">
        <LiveOverview    v-if="activeView === 'overview'"   :sessions="engagementStore.activeSessions" :scores="engagementStore.studentScores" :averages="engagementStore.classAverages" @select="selectSession" @refresh="engagementStore.loadActiveSessions"/>
        <SessionDetail   v-else-if="activeView === 'session' && selectedSession" :session="selectedSession" :scores="engagementStore.studentScores[selectedSession.id] || {}" :avg="engagementStore.classAverages[selectedSession.id] || 0" @back="activeView = 'overview'"/>
        <AnalyticsView   v-else-if="activeView === 'analytics'"/>
        <AlertsView      v-else-if="activeView === 'alerts'"  :alerts="engagementStore.activeAlerts" @acknowledge="engagementStore.acknowledgeAlert"/>
        <HistoryView     v-else-if="activeView === 'history'"/>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore }       from '@/stores/auth'
import { useEngagementStore } from '@/stores/engagement'
import LiveOverview  from '@/components/dashboard/LiveOverview.vue'
import SessionDetail from '@/components/dashboard/SessionDetail.vue'
import AnalyticsView from '@/components/dashboard/AnalyticsView.vue'
import AlertsView    from '@/components/dashboard/AlertsView.vue'
import HistoryView   from '@/components/dashboard/HistoryView.vue'

const router          = useRouter()
const authStore       = useAuthStore()
const engagementStore = useEngagementStore()

const activeView      = ref('overview')
const selectedSession = ref(null)
const currentTime     = ref('')

const pageTitle = { overview: 'Активные уроки', session: 'Урок • Live', analytics: 'Аналитика', alerts: 'Алерты', history: 'История' }

const navItems = computed(() => [
  { id: 'overview',  label: 'Обзор',     icon: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>` },
  { id: 'analytics', label: 'Аналитика', icon: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>` },
  { id: 'alerts',    label: 'Алерты',    badge: engagementStore.alertCount || null, icon: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>` },
  { id: 'history',   label: 'История',   icon: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>` },
])

const userInitials = computed(() => (authStore.user?.name || '').split(' ').map(n=>n[0]).join('').slice(0,2).toUpperCase())
const roleLabel    = computed(() => ({ admin: 'Администратор', supervisor: 'Супервайзер', teacher: 'Учитель' }[authStore.user?.role] || ''))

function selectSession(session) {
  selectedSession.value = session
  activeView.value = 'session'
  engagementStore.subscribeToSession(session.id)
}

async function handleLogout() {
  engagementStore.disconnect()
  authStore.logout()
  router.push('/login')
}

let timer
onMounted(async () => {
  await authStore.fetchMe()
  await engagementStore.loadActiveSessions()
  await engagementStore.loadActiveAlerts()
  if (authStore.user?.school_id) engagementStore.connectWebSocket(authStore.user.school_id)
  timer = setInterval(() => {
    currentTime.value = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  }, 1000)
})

onUnmounted(() => { clearInterval(timer); engagementStore.disconnect() })
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');

.dashboard { display:flex; height:100vh; background:#0a0e1a; color:#e2e8f0; font-family:'DM Sans',system-ui,sans-serif; overflow:hidden; }

.sidebar { width:220px; flex-shrink:0; background:#0d1220; border-right:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; }
.sidebar-logo { display:flex; align-items:center; gap:10px; padding:24px 20px; border-bottom:1px solid rgba(255,255,255,0.06); }
.logo-icon { width:32px; height:32px; background:linear-gradient(135deg,#6366f1,#8b5cf6); border-radius:8px; display:flex; align-items:center; justify-content:center; color:white; }
.logo-icon svg { width:18px; height:18px; }
.logo-text { font-size:16px; font-weight:600; background:linear-gradient(135deg,#a5b4fc,#c4b5fd); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.sidebar-nav { flex:1; padding:16px 12px; display:flex; flex-direction:column; gap:2px; }
.nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:8px; border:none; background:transparent; color:#94a3b8; cursor:pointer; transition:all 0.15s; font-size:13.5px; font-weight:500; width:100%; }
.nav-item:hover { background:rgba(255,255,255,0.06); color:#e2e8f0; }
.nav-item.active { background:rgba(99,102,241,0.15); color:#a5b4fc; }
.nav-icon { width:18px; height:18px; flex-shrink:0; display:flex; }
.nav-icon :deep(svg) { width:18px; height:18px; }
.nav-label { flex:1; text-align:left; }
.nav-badge { background:#ef4444; color:white; font-size:10px; font-weight:700; padding:2px 6px; border-radius:10px; }
.sidebar-footer { padding:16px; border-top:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; gap:10px; }
.user-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:white; flex-shrink:0; }
.user-info { flex:1; min-width:0; }
.user-name { font-size:12px; font-weight:600; color:#e2e8f0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.user-role { font-size:11px; color:#64748b; }
.logout-btn { width:28px; height:28px; border:none; background:transparent; color:#64748b; cursor:pointer; border-radius:6px; display:flex; align-items:center; justify-content:center; transition:all 0.15s; padding:0; flex-shrink:0; }
.logout-btn:hover { color:#ef4444; background:rgba(239,68,68,0.1); }
.logout-btn svg { width:16px; height:16px; }

.main-content { flex:1; display:flex; flex-direction:column; overflow:hidden; }
.content-header { display:flex; align-items:center; justify-content:space-between; padding:20px 28px; border-bottom:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
.header-left { display:flex; align-items:center; gap:14px; }
.page-title { font-size:18px; font-weight:600; color:#f1f5f9; margin:0; letter-spacing:-0.3px; }
.live-indicator { display:flex; align-items:center; gap:5px; font-size:11px; font-weight:600; color:#22c55e; background:rgba(34,197,94,0.1); padding:3px 8px; border-radius:20px; border:1px solid rgba(34,197,94,0.2); }
.live-dot { width:6px; height:6px; border-radius:50%; background:#22c55e; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
.header-right { display:flex; align-items:center; gap:16px; }
.header-time { font-size:13px; color:#64748b; font-variant-numeric:tabular-nums; }
.alert-bell { position:relative; width:36px; height:36px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.04); border-radius:8px; color:#94a3b8; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s; }
.alert-bell:hover { color:#f1f5f9; }
.alert-bell svg { width:18px; height:18px; }
.bell-badge { position:absolute; top:-5px; right:-5px; background:#ef4444; color:white; font-size:10px; font-weight:700; width:16px; height:16px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
.content-body { flex:1; overflow-y:auto; padding:24px 28px; }
.content-body::-webkit-scrollbar { width:4px; }
.content-body::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.1); border-radius:2px; }
</style>
