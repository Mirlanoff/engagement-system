import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { auth } from '@/api'
import axios from 'axios'

export const useAuthStore = defineStore('auth', () => {
  const user  = ref(null)
  const token = ref(localStorage.getItem('token'))

  const isLoggedIn = computed(() => !!token.value)
  const isAdmin      = computed(() => user.value?.role === 'admin')
  const isSupervisor = computed(() => ['admin', 'supervisor'].includes(user.value?.role))

  async function login(email, password) {
    const { data } = await auth.login(email, password)
    token.value = data.token
    user.value  = data.user
    localStorage.setItem('token', data.token)
    axios.defaults.headers.common['Authorization'] = `Bearer ${data.token}`
  }

  async function fetchMe() {
    if (!token.value) return
    try {
      const { data } = await auth.me()
      user.value = data.data
    } catch {
      logout()
    }
  }

  function logout() {
    token.value = null
    user.value  = null
    localStorage.removeItem('token')
    delete axios.defaults.headers.common['Authorization']
  }

  return { user, token, isLoggedIn, isAdmin, isSupervisor, login, fetchMe, logout }
})
