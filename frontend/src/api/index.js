import axios from 'axios'

const api = axios.create({
  baseURL: '/api/v1',
  headers: { 'Content-Type': 'application/json' },
})

api.interceptors.request.use(config => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      localStorage.removeItem('token')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export default api

export const auth = {
  login: (email, password) => api.post('/auth/login', { email, password }),
  me: () => api.get('/auth/me'),
  logout: () => api.post('/auth/logout'),
}

export const sessions = {
  active: () => api.get('/sessions/active'),
  list: (params) => api.get('/sessions', { params }),
  get: (id) => api.get(`/sessions/${id}`),
  start: (data) => api.post('/sessions', data),
  end: (id) => api.post(`/sessions/${id}/end`),
  pause: (id) => api.post(`/sessions/${id}/pause`),
  resume: (id) => api.post(`/sessions/${id}/resume`),
  timeline: (id) => api.get(`/sessions/${id}/timeline`),
  students: (id) => api.get(`/sessions/${id}/students`),
  // Передаём кадр с веб-камеры учителя в ML сервис
  ingestFrame: (id, frameB64, cameraId = 'browser') =>
    api.post(`/sessions/${id}/frames`, { frame: frameB64, camera_id: cameraId }),
}

export const analytics = {
  overview: (params) => api.get('/analytics/overview', { params }),
  emotions: (params) => api.get('/analytics/emotions', { params }),
  heatmap: (classroomId, params) => api.get(`/analytics/heatmap/${classroomId}`, { params }),
  student: (studentId, params) => api.get(`/analytics/students/${studentId}`, { params }),
  compare: (data) => api.post('/analytics/compare', data),
}

export const alerts = {
  list: (params) => api.get('/alerts', { params }),
  active: () => api.get('/alerts/active'),
  acknowledge: (id, note) => api.post(`/alerts/${id}/acknowledge`, { note }),
}

export const admin = {
  resetDashboard: (keepCompleted = false) =>
    api.post('/admin/reset-dashboard', { keep_completed: keepCompleted }),
}

export const recommendations = {
  list: () => api.get('/recommendations'),
  markRead: (id) => api.post(`/recommendations/${id}/read`),
  rate: (id, rating) => api.post(`/recommendations/${id}/rate`, { rating }),
}
