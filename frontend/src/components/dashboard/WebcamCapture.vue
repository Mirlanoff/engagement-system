<template>
  <div v-if="session" class="webcam-panel" :class="{ minimized }">
    <div class="webcam-header">
      <span class="status-dot" :class="statusClass"></span>
      <span class="webcam-title">{{ headerLabel }}</span>
      <button class="icon-btn" @click="minimized = !minimized" :title="minimized ? 'Развернуть' : 'Свернуть'">
        {{ minimized ? '▢' : '–' }}
      </button>
      <button class="icon-btn close" @click="endLesson" title="Завершить урок">✕</button>
    </div>

    <div v-show="!minimized" class="webcam-body">
      <video
        ref="video"
        class="webcam-video"
        autoplay
        playsinline
        muted
      ></video>
      <canvas ref="canvas" class="webcam-canvas-hidden"></canvas>

      <div class="webcam-footer">
        <div class="info-line">
          <span>📡 {{ statusLabel }}</span>
          <span v-if="lastSentAt" class="muted">Кадр: {{ lastSentAt }}</span>
        </div>
        <div v-if="errorMessage" class="error-line">{{ errorMessage }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { sessions as sessionsApi } from '@/api'

const props = defineProps({
  session: { type: Object, default: null },
  intervalSeconds: { type: Number, default: 5 },
})
const emit = defineEmits(['ended', 'error'])

const video    = ref(null)
const canvas   = ref(null)
const minimized = ref(false)

const stream     = ref(null)
const sending    = ref(false)
const lastStatus = ref('idle')          // idle | starting | streaming | analyzing | fallback | error
const lastSentAt = ref('')
const errorMessage = ref('')

let sendTimer = null

const headerLabel = computed(() => {
  const sub = props.session?.subject || props.session?.classroom_name || 'Урок'
  return `🎥 Камера • ${sub}`
})

const statusClass = computed(() => {
  if (lastStatus.value === 'streaming' || lastStatus.value === 'analyzing') return 'live'
  if (lastStatus.value === 'fallback') return 'warn'
  if (lastStatus.value === 'error')    return 'err'
  return 'idle'
})

const statusLabel = computed(() => ({
  idle:       'Ожидание',
  starting:   'Запуск камеры…',
  streaming:  'Анализ идёт',
  analyzing:  'Анализ идёт',
  ml_offline: 'ML недоступен — данных не будет',
  error:      'Ошибка',
}[lastStatus.value] || lastStatus.value))

watch(() => props.session?.id, async (newId, oldId) => {
  if (oldId) await stopCapture()
  if (newId) await startCapture()
}, { immediate: true })

onBeforeUnmount(stopCapture)

async function startCapture() {
  errorMessage.value = ''
  lastStatus.value   = 'starting'

  try {
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' },
      audio: false,
    })
  } catch (e) {
    errorMessage.value = 'Не удалось получить доступ к камере: ' + (e.message || e.name)
    lastStatus.value   = 'error'
    emit('error', e)
    return
  }

  // Дождёмся монтирования <video> элемента
  await waitFor(() => video.value)
  if (!video.value) return
  video.value.srcObject = stream.value
  await video.value.play().catch(() => {})

  lastStatus.value = 'streaming'

  // Запускаем периодическую отправку кадров
  sendTimer = setInterval(() => sendFrame(), props.intervalSeconds * 1000)
  // И отправим первый кадр сразу
  setTimeout(sendFrame, 500)
}

async function stopCapture() {
  if (sendTimer) { clearInterval(sendTimer); sendTimer = null }
  if (stream.value) {
    stream.value.getTracks().forEach(t => t.stop())
    stream.value = null
  }
  if (video.value) video.value.srcObject = null
  lastStatus.value = 'idle'
}

async function sendFrame() {
  if (!props.session?.id || !video.value || !canvas.value || sending.value) return
  if (!video.value.videoWidth) return // ещё не готов

  sending.value = true
  try {
    const w = video.value.videoWidth
    const h = video.value.videoHeight
    canvas.value.width  = w
    canvas.value.height = h
    const ctx = canvas.value.getContext('2d')
    ctx.drawImage(video.value, 0, 0, w, h)
    const dataUrl = canvas.value.toDataURL('image/jpeg', 0.7)
    const b64     = dataUrl.split(',')[1]

    const { data } = await sessionsApi.ingestFrame(props.session.id, b64)
    if (data?.status === 'fallback') {
      lastStatus.value = 'fallback'
    } else {
      lastStatus.value = 'analyzing'
    }
    lastSentAt.value = new Date().toLocaleTimeString()
    errorMessage.value = ''
  } catch (e) {
    lastStatus.value   = 'error'
    errorMessage.value = e.response?.data?.message || e.message || 'Ошибка отправки кадра'
  } finally {
    sending.value = false
  }
}

async function endLesson() {
  if (!props.session?.id) return
  try {
    await sessionsApi.end(props.session.id)
  } catch {}
  await stopCapture()
  emit('ended', props.session)
}

function waitFor(predicate, timeoutMs = 1000) {
  return new Promise(resolve => {
    if (predicate()) return resolve(true)
    const start = Date.now()
    const t = setInterval(() => {
      if (predicate() || Date.now() - start > timeoutMs) {
        clearInterval(t); resolve(predicate())
      }
    }, 30)
  })
}
</script>

<style scoped>
.webcam-panel {
  position: fixed;
  right: 20px;
  bottom: 20px;
  width: 320px;
  background: #0d1220;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 14px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.5);
  overflow: hidden;
  z-index: 90;
  font-size: 12px;
}
.webcam-panel.minimized { width: 240px; }

.webcam-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.07);
  background: rgba(255,255,255,0.03);
}
.webcam-title {
  flex: 1;
  font-weight: 600;
  color: #f1f5f9;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.icon-btn {
  width: 24px; height: 24px;
  display: inline-flex; align-items: center; justify-content: center;
  background: transparent; border: none; cursor: pointer;
  color: #94a3b8; border-radius: 6px;
}
.icon-btn:hover { background: rgba(255,255,255,0.06); color: #f1f5f9; }
.icon-btn.close:hover { background: rgba(239,68,68,0.15); color: #ef4444; }

.status-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #475569;
}
.status-dot.live { background: #22c55e; box-shadow: 0 0 8px #22c55e; animation: pulse 1.5s infinite; }
.status-dot.warn { background: #f59e0b; }
.status-dot.err  { background: #ef4444; }

@keyframes pulse {
  0%,100% { opacity: 1; }
  50% { opacity: 0.4; }
}

.webcam-body { padding: 0; }
.webcam-video {
  width: 100%;
  display: block;
  background: #000;
  aspect-ratio: 4/3;
  object-fit: cover;
  transform: scaleX(-1); /* зеркало для удобства */
}
.webcam-canvas-hidden { display: none; }

.webcam-footer {
  padding: 8px 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  border-top: 1px solid rgba(255,255,255,0.07);
}
.info-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: #cbd5e1;
}
.muted { color: #64748b; }
.error-line {
  color: #ef4444;
  font-size: 11px;
  word-break: break-word;
}
</style>
