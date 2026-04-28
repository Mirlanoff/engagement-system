import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: '/dashboard'
    },
    {
      path: '/login',
      component: () => import('@/views/LoginView.vue')
    },
    {
      path: '/dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { requiresAuth: true }
    },
  ]
})

router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  if (to.meta.requiresAuth && !token) {
    return '/login'
  }
})

export default router
