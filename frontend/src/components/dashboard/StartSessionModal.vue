<template>
  <div class="modal-overlay" @click.self="$emit('close')">
    <div class="modal">
      <div class="modal-header">
        <h2>Начать урок</h2>
        <button class="close-btn" @click="$emit('close')">✕</button>
      </div>

      <div class="modal-body">
        <div class="form-group">
          <label>Класс</label>
          <select v-model="form.classroom_id">
            <option value="">— Выберите класс —</option>
            <option v-for="c in classrooms" :key="c.id" :value="c.id">
              {{ c.name }}
            </option>
          </select>
        </div>
        <div class="form-group">
          <label>Предмет (необязательно)</label>
          <input v-model="form.subject" placeholder="Математика, Физика..." />
        </div>
        <p v-if="error" class="error">{{ error }}</p>
      </div>

      <div class="modal-footer">
        <button class="btn-cancel" @click="$emit('close')">Отмена</button>
        <button class="btn-start" @click="start" :disabled="!form.classroom_id || loading">
          {{ loading ? 'Запускаем...' : '▶ Начать урок' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api, { sessions } from '@/api'

const emit = defineEmits(['close', 'started'])

const classrooms = ref([])
const form       = ref({ classroom_id: '', subject: '' })
const loading    = ref(false)
const error      = ref('')

onMounted(async () => {
  try {
    const { data } = await api.get('/classrooms')
    classrooms.value = data.data || []
  } catch {
    // fallback — попробуем получить из активных сессий
    try {
      const { data } = await api.get('/sessions?per_page=5')
      const seen = new Set()
      classrooms.value = (data.data || [])
        .filter(s => s.classroom_id && !seen.has(s.classroom_id) && seen.add(s.classroom_id))
        .map(s => ({ id: s.classroom_id, name: s.classroom_name || s.classroom_id }))
    } catch {}
  }
})

async function start() {
  if (!form.value.classroom_id) return
  error.value  = ''
  loading.value = true
  try {
    const { data } = await sessions.start(form.value)
    emit('started', data.data)
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || 'Ошибка запуска урока'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.7); display:flex; align-items:center; justify-content:center; z-index:100; }
.modal { background:#0d1220; border:1px solid rgba(255,255,255,0.1); border-radius:16px; width:420px; overflow:hidden; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid rgba(255,255,255,0.07); }
.modal-header h2 { font-size:16px; font-weight:600; color:#f1f5f9; margin:0; }
.close-btn { width:28px; height:28px; border:none; background:transparent; color:#64748b; cursor:pointer; border-radius:6px; font-size:14px; }
.close-btn:hover { color:#f1f5f9; background:rgba(255,255,255,0.06); }
.modal-body { padding:24px; display:flex; flex-direction:column; gap:16px; }
.form-group { display:flex; flex-direction:column; gap:6px; }
label { font-size:12px; font-weight:500; color:#94a3b8; }
select, input { padding:10px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#f1f5f9; font-size:14px; font-family:inherit; }
select:focus, input:focus { outline:none; border-color:#6366f1; }
select option { background:#0d1220; }
.error { font-size:12px; color:#ef4444; margin:0; }
.modal-footer { display:flex; gap:10px; padding:16px 24px; border-top:1px solid rgba(255,255,255,0.07); }
.btn-cancel { flex:1; padding:10px; background:transparent; border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#94a3b8; cursor:pointer; font-size:13px; }
.btn-cancel:hover { color:#f1f5f9; }
.btn-start { flex:2; padding:10px; background:linear-gradient(135deg,#6366f1,#8b5cf6); border:none; border-radius:8px; color:white; cursor:pointer; font-size:13px; font-weight:600; }
.btn-start:disabled { opacity:0.5; cursor:not-allowed; }
</style>
