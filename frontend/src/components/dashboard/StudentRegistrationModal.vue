<template>
  <div class="modal-overlay" @click.self="$emit('close')">
    <div class="modal">
      <div class="modal-header">
        <h2>Регистрация студента</h2>
        <button class="close-btn" @click="$emit('close')">&#10005;</button>
      </div>

      <div class="modal-body">
        <!-- Имя -->
        <div class="form-group">
          <label>Имя</label>
          <input v-model="form.name" placeholder="Введите имя" />
        </div>

        <!-- Фамилия -->
        <div class="form-group">
          <label>Фамилия</label>
          <input v-model="form.surname" placeholder="Введите фамилию" />
        </div>

        <!-- Класс -->
        <div class="form-group">
          <label>Класс</label>
          <select v-model="form.classroom_id">
            <option value="">— Выберите класс —</option>
            <option v-for="c in classrooms" :key="c.id" :value="c.id">
              {{ c.name }}
            </option>
          </select>
        </div>

        <!-- Фото -->
        <div class="form-group">
          <label>Фото студента</label>
          <div class="photo-section">
            <!-- Превью -->
            <div class="photo-preview" v-if="photoPreview">
              <img :src="photoPreview" alt="Фото" />
              <button class="remove-photo" @click="removePhoto">&#10005;</button>
            </div>

            <!-- Кнопки выбора -->
            <div class="photo-actions" v-if="!cameraActive && !photoPreview">
              <button class="btn-photo" @click="triggerFileInput">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18">
                  <path d="M3 16l4-4 4 4m5-4l4 4M2 20h20M14 4l2 2h4a1 1 0 011 1v11a1 1 0 01-1 1H3a1 1 0 01-1-1V7a1 1 0 011-1h4l2-2h5z"/>
                </svg>
                Из проводника
              </button>
              <button class="btn-photo" @click="startCamera">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18">
                  <path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M3 6h8a2 2 0 012 2v8a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2z"/>
                </svg>
                Веб-камера
              </button>
              <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png"
                style="display:none"
                @change="onFileSelected"
              />
            </div>

            <!-- Камера -->
            <div class="camera-container" v-if="cameraActive">
              <video ref="videoEl" autoplay playsinline></video>
              <div class="camera-controls">
                <button class="btn-capture" @click="capturePhoto">Сделать снимок</button>
                <button class="btn-cancel-cam" @click="stopCamera">Отмена</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Статус загрузки фото -->
        <div v-if="photoStatus" :class="['photo-status', photoStatus.type]">
          {{ photoStatus.message }}
        </div>

        <p v-if="error" class="error">{{ error }}</p>
      </div>

      <div class="modal-footer">
        <button class="btn-cancel" @click="$emit('close')">Отмена</button>
        <button
          class="btn-submit"
          @click="submit"
          :disabled="!canSubmit || loading"
        >
          {{ loading ? 'Сохранение...' : 'Зарегистрировать' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import api, { students } from '@/api'

const emit = defineEmits(['close', 'registered'])

const classrooms = ref([])
const form = ref({ name: '', surname: '', classroom_id: '' })
const loading = ref(false)
const error = ref('')

// Фото
const fileInput = ref(null)
const photoFile = ref(null)
const photoPreview = ref(null)
const photoStatus = ref(null)

// Камера
const cameraActive = ref(false)
const videoEl = ref(null)
let mediaStream = null

const canSubmit = computed(() =>
  form.value.name.trim() &&
  form.value.surname.trim() &&
  form.value.classroom_id
)

onMounted(async () => {
  try {
    const { data } = await api.get('/classrooms')
    classrooms.value = data.data || []
  } catch {
    // fallback
    classrooms.value = []
  }
})

onBeforeUnmount(() => {
  stopCamera()
})

// ── Файл из проводника ──────────────────────────────────────
function triggerFileInput() {
  fileInput.value?.click()
}

function onFileSelected(e) {
  const file = e.target.files?.[0]
  if (!file) return
  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
  photoStatus.value = null
}

// ── Веб-камера ──────────────────────────────────────────────
async function startCamera() {
  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: 640, height: 480 },
    })
    cameraActive.value = true
    // Ждём монтирования video элемента
    await new Promise(r => setTimeout(r, 50))
    if (videoEl.value) {
      videoEl.value.srcObject = mediaStream
    }
  } catch (err) {
    error.value = 'Не удалось получить доступ к камере'
  }
}

function capturePhoto() {
  if (!videoEl.value) return
  const canvas = document.createElement('canvas')
  canvas.width = videoEl.value.videoWidth || 640
  canvas.height = videoEl.value.videoHeight || 480
  const ctx = canvas.getContext('2d')
  ctx.drawImage(videoEl.value, 0, 0, canvas.width, canvas.height)

  canvas.toBlob((blob) => {
    if (blob) {
      photoFile.value = new File([blob], 'webcam.jpg', { type: 'image/jpeg' })
      photoPreview.value = URL.createObjectURL(blob)
      photoStatus.value = null
    }
    stopCamera()
  }, 'image/jpeg', 0.9)
}

function stopCamera() {
  if (mediaStream) {
    mediaStream.getTracks().forEach(t => t.stop())
    mediaStream = null
  }
  cameraActive.value = false
}

function removePhoto() {
  photoFile.value = null
  if (photoPreview.value) {
    URL.revokeObjectURL(photoPreview.value)
    photoPreview.value = null
  }
  photoStatus.value = null
}

// ── Отправка формы ──────────────────────────────────────────
async function submit() {
  if (!canSubmit.value) return
  error.value = ''
  loading.value = true

  try {
    // 1. Создаём студента
    const { data } = await students.create({
      name: form.value.name.trim(),
      surname: form.value.surname.trim(),
      classroom_id: form.value.classroom_id,
    })

    const student = data.data

    // 2. Если есть фото — загружаем
    if (photoFile.value && student?.id) {
      try {
        const photoRes = await students.uploadPhoto(student.id, photoFile.value)
        const pData = photoRes.data
        student.photo_url = pData.photo_url
        student.face_registered = pData.face_registered

        if (pData.status === 'ok') {
          photoStatus.value = { type: 'success', message: 'Лицо зарегистрировано' }
        } else {
          photoStatus.value = { type: 'warning', message: pData.message || 'Фото загружено, но лицо не распознано' }
        }
      } catch (photoErr) {
        const msg = photoErr.response?.data?.message || 'Ошибка загрузки фото'
        photoStatus.value = { type: 'warning', message: msg }
      }
    }

    emit('registered', student)
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || 'Ошибка регистрации студента'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.7);
  display: flex; align-items: center; justify-content: center;
  z-index: 100;
}
.modal {
  background: #0d1220;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 16px;
  width: 480px;
  max-height: 90vh;
  overflow-y: auto;
}
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid rgba(255,255,255,0.07);
}
.modal-header h2 { font-size: 16px; font-weight: 600; color: #f1f5f9; margin: 0; }
.close-btn {
  width: 28px; height: 28px; border: none;
  background: transparent; color: #64748b;
  cursor: pointer; border-radius: 6px; font-size: 14px;
}
.close-btn:hover { color: #f1f5f9; background: rgba(255,255,255,0.06); }

.modal-body { padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
label { font-size: 12px; font-weight: 500; color: #94a3b8; }
select, input {
  padding: 10px 14px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: #f1f5f9; font-size: 14px; font-family: inherit;
}
select:focus, input:focus { outline: none; border-color: #6366f1; }
select option { background: #0d1220; }

.photo-section { display: flex; flex-direction: column; gap: 12px; }
.photo-preview {
  position: relative; width: 160px; height: 160px;
  border-radius: 12px; overflow: hidden;
  border: 2px solid rgba(99,102,241,0.4);
}
.photo-preview img { width: 100%; height: 100%; object-fit: cover; }
.remove-photo {
  position: absolute; top: 6px; right: 6px;
  width: 24px; height: 24px; border-radius: 50%;
  background: rgba(0,0,0,0.7); border: none;
  color: #fff; cursor: pointer; font-size: 12px;
  display: flex; align-items: center; justify-content: center;
}
.remove-photo:hover { background: #ef4444; }

.photo-actions { display: flex; gap: 10px; }
.btn-photo {
  display: flex; align-items: center; gap: 6px;
  padding: 10px 16px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 8px; color: #cbd5e1;
  cursor: pointer; font-size: 13px; font-family: inherit;
  transition: all 0.15s;
}
.btn-photo:hover {
  background: rgba(99,102,241,0.1);
  border-color: rgba(99,102,241,0.4);
  color: #f1f5f9;
}
.btn-photo svg { flex-shrink: 0; }

.camera-container {
  border-radius: 12px; overflow: hidden;
  border: 1px solid rgba(255,255,255,0.1);
}
.camera-container video {
  width: 100%; max-height: 300px;
  background: #000; display: block;
}
.camera-controls {
  display: flex; gap: 10px; padding: 12px;
  background: rgba(0,0,0,0.4);
}
.btn-capture {
  flex: 2; padding: 8px 16px;
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  border: none; border-radius: 8px;
  color: white; cursor: pointer; font-size: 13px; font-weight: 600;
}
.btn-cancel-cam {
  flex: 1; padding: 8px 16px;
  background: transparent;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px; color: #94a3b8;
  cursor: pointer; font-size: 13px;
}
.btn-cancel-cam:hover { color: #f1f5f9; }

.photo-status {
  padding: 8px 12px; border-radius: 8px; font-size: 12px;
}
.photo-status.success {
  background: rgba(34,197,94,0.1); color: #22c55e;
  border: 1px solid rgba(34,197,94,0.2);
}
.photo-status.warning {
  background: rgba(245,158,11,0.1); color: #f59e0b;
  border: 1px solid rgba(245,158,11,0.2);
}

.error { font-size: 12px; color: #ef4444; margin: 0; }

.modal-footer {
  display: flex; gap: 10px; padding: 16px 24px;
  border-top: 1px solid rgba(255,255,255,0.07);
}
.btn-cancel {
  flex: 1; padding: 10px;
  background: transparent;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px; color: #94a3b8;
  cursor: pointer; font-size: 13px;
}
.btn-cancel:hover { color: #f1f5f9; }
.btn-submit {
  flex: 2; padding: 10px;
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  border: none; border-radius: 8px;
  color: white; cursor: pointer;
  font-size: 13px; font-weight: 600;
}
.btn-submit:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
