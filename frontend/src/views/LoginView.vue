<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-logo">
        <div class="logo-icon">
          <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M6 20c0-3.314 2.686-6 6-6s6 2.686 6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </div>
        <span>EngageAI</span>
      </div>
      <h1>Вход в систему</h1>
      <p class="login-sub">Мониторинг вовлечённости студентов</p>
      <div class="form-group">
        <label>Email</label>
        <input v-model="email" type="email" placeholder="admin@school.kg" @keyup.enter="login"/>
      </div>
      <div class="form-group">
        <label>Пароль</label>
        <input v-model="password" type="password" placeholder="••••••••" @keyup.enter="login"/>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="login-btn" @click="login" :disabled="loading">
        {{ loading ? 'Вход...' : 'Войти' }}
      </button>
      <div class="login-hint">
        <strong>Тест:</strong> admin@school.kg / password
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router    = useRouter()
const authStore = useAuthStore()
const email     = ref('admin@school.kg')
const password  = ref('password')
const error     = ref('')
const loading   = ref(false)

async function login() {
  error.value   = ''
  loading.value = true
  try {
    await authStore.login(email.value, password.value)
    router.push('/dashboard')
  } catch (e) {
    error.value = e.response?.data?.message || 'Неверный email или пароль'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
.login-page { min-height:100vh; background:#0a0e1a; display:flex; align-items:center; justify-content:center; font-family:'DM Sans',system-ui,sans-serif; }
.login-card { background:#0d1220; border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:40px; width:100%; max-width:380px; }
.login-logo { display:flex; align-items:center; gap:10px; margin-bottom:28px; }
.logo-icon { width:36px; height:36px; background:linear-gradient(135deg,#6366f1,#8b5cf6); border-radius:10px; display:flex; align-items:center; justify-content:center; color:white; }
.logo-icon svg { width:20px; height:20px; }
.login-logo span { font-size:18px; font-weight:700; background:linear-gradient(135deg,#a5b4fc,#c4b5fd); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
h1 { font-size:22px; font-weight:700; color:#f1f5f9; margin:0 0 6px; letter-spacing:-0.5px; }
.login-sub { font-size:13px; color:#64748b; margin:0 0 28px; }
.form-group { margin-bottom:16px; }
label { display:block; font-size:12px; font-weight:500; color:#94a3b8; margin-bottom:6px; }
input { width:100%; padding:10px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#f1f5f9; font-size:14px; font-family:inherit; transition:border-color 0.15s; box-sizing:border-box; }
input:focus { outline:none; border-color:#6366f1; }
input::placeholder { color:#475569; }
.error { font-size:12px; color:#ef4444; margin:0 0 12px; }
.login-btn { width:100%; padding:11px; background:linear-gradient(135deg,#6366f1,#8b5cf6); border:none; border-radius:8px; color:white; font-size:14px; font-weight:600; cursor:pointer; transition:opacity 0.15s; margin-top:4px; font-family:inherit; }
.login-btn:hover { opacity:0.9; }
.login-btn:disabled { opacity:0.6; cursor:not-allowed; }
.login-hint { margin-top:16px; padding:10px 14px; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.15); border-radius:8px; font-size:12px; color:#64748b; }
.login-hint strong { color:#a5b4fc; }
</style>
