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
      <div class="video-wrap">
        <video
          ref="video"
          class="webcam-video"
          autoplay
          playsinline
          muted
        ></video>
        <canvas ref="canvas" class="webcam-canvas-hidden"></canvas>

        <!-- Диагностика поверх видео -->
        <div v-if="diagnostic" class="diagnostic-banner" :class="diagnostic.severity">
          <strong>{{ diagnostic.title }}</strong>
          <div v-if="diagnostic.hint" class="hint">{{ diagnostic.hint }}</div>
        </div>

        <!-- Лица в кадре -->
        <div v-if="lastFacesDetected !== null && !diagnostic" class="faces-counter">
          Лиц в кадре: <strong>{{ lastFacesDetected }}</strong>
          <span v-if="missingStudents.length"> · отсутствуют: {{ missingStudents.length }}</span>
        </div>
      </div>

      <div class="webcam-footer">
        <div class="info-line">
          <span>📡 {{ statusLabel }}</span>
          <span v-if="lastSentAt" class="muted">Кадр: {{ lastSentAt }}</span>
        </div>
        <div v-if="qualityLine" class="info-line muted">
          {{ qualityLine }}
        </div>
        <div v-if="captureRes" class="info-line muted">
          Разрешение: {{ captureRes }} · Интервал: {{ effectiveIntervalSec }}с
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
  // Базовый интервал между кадрами; адаптивно увеличивается, если ML
  // не успевает.
  intervalSeconds: { type: Number, default: 2 },
})
const emit = defineEmits(['ended', 'error'])

const video    = ref(null)
const canvas   = ref(null)
const minimized = ref(false)

const stream      = ref(null)
const sending     = ref(false)
const lastStatus  = ref('idle')
const lastSentAt  = ref('')
const errorMessage = ref('')

// Адаптивный интервал. Базовый = props.intervalSeconds; растёт до
// 6 секунд при медленных ответах ML, возвращается обратно при быстрых.
const effectiveInterval = ref(props.intervalSeconds * 1000)
const slowResponses = ref(0)
const fastResponses = ref(0)

// Диагностика последнего кадра
const lastFrameQuality   = ref(null) // { brightness, blur, ok }
const lastNotDetected    = ref(null) // 'too_dark' | 'too_blurry' | ...
const lastFacesDetected  = ref(null)
const missingStudents    = ref([])
const captureRes         = ref('')

let sendTimer = null
let stopped   = false

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
  fallback:   'ML недоступен — симуляция',
  error:      'Ошибка',
}[lastStatus.value] || lastStatus.value))

const effectiveIntervalSec = computed(() =>
  (effectiveInterval.value / 1000).toFixed(1).replace(/\.0$/, ''))

const qualityLine = computed(() => {
  const q = lastFrameQuality.value
  if (!q) return ''
  const parts = []
  if (typeof q.brightness === 'number') parts.push(`Яркость ${q.brightness.toFixed(0)}`)
  if (typeof q.blur === 'number')       parts.push(`Резкость ${q.blur.toFixed(0)}`)
  return parts.join(' · ')
})

// Текст-баннер с подсказкой пользователю
const diagnostic = computed(() => {
  if (lastStatus.value === 'starting') {
    return { severity: 'info', title: 'Запуск камеры…' }
  }
  if (lastStatus.value === 'error') {
    return { severity: 'err', title: errorMessage.value || 'Ошибка камеры' }
  }
  if (lastStatus.value === 'fallback') {
    return { severity: 'warn', title: 'ML-сервис недоступен',
      hint: 'Анализ работает в режиме симуляции — реальные метрики не пишутся.' }
  }
  if (!lastNotDetected.value) return null
  switch (lastNotDetected.value) {
    case 'too_dark':
      return { severity: 'warn', title: 'Слишком тёмный кадр',
        hint: 'Включите свет в классе или поверните камеру к окну.' }
    case 'too_blurry':
      return { severity: 'warn', title: 'Кадр размыт',
        hint: 'Протрите объектив, проверьте автофокус, исключите тряску.' }
    case 'no_faces_in_fov':
      return { severity: 'warn', title: 'В кадре нет лиц',
        hint: 'Попросите студентов смотреть в сторону камеры или скорректируйте угол.' }
    case 'face_too_small':
      return { severity: 'warn', title: 'Лица слишком далеко',
        hint: 'Подвиньте камеру ближе или используйте оптический зум.' }
    case 'model_unavailable':
      return { severity: 'err', title: 'ML-модели не загрузились',
        hint: 'Проверьте контейнер ml-service и логи.' }
    default:
      return { severity: 'warn', title: lastNotDetected.value }
  }
})

watch(() => props.session?.id, async (newId, oldId) => {
  if (oldId) await stopCapture()
  if (newId) await startCapture()
}, { immediate: true })

onBeforeUnmount(stopCapture)

async function startCapture() {
  errorMessage.value = ''
  lastStatus.value   = 'starting'
  stopped = false

  // Сначала пытаемся получить HD (1280×720), при отказе — 960×540.
  const constraintsList = [
    { video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' }, audio: false },
    { video: { width: { ideal: 960  }, height: { ideal: 540 }, facingMode: 'user' }, audio: false },
    { video: { width: { ideal: 640  }, height: { ideal: 480 }, facingMode: 'user' }, audio: false },
  ]

  let lastErr = null
  for (const constraints of constraintsList) {
    try {
      stream.value = await navigator.mediaDevices.getUserMedia(constraints)
      break
    } catch (e) {
      lastErr = e
    }
  }
  if (!stream.value) {
    errorMessage.value = 'Не удалось получить доступ к камере: ' + (lastErr?.message || lastErr?.name || 'unknown')
    lastStatus.value   = 'error'
    emit('error', lastErr)
    return
  }

  await waitFor(() => video.value)
  if (!video.value) return
  video.value.srcObject = stream.value
  await video.value.play().catch(() => {})

  // Зафиксируем фактическое разрешение
  await waitFor(() => video.value && video.value.videoWidth)
  if (video.value?.videoWidth) {
    captureRes.value = `${video.value.videoWidth}×${video.value.videoHeight}`
  }

  lastStatus.value = 'streaming'
  scheduleNextFrame(300) // первый кадр почти сразу
}

function scheduleNextFrame(delayMs = effectiveInterval.value) {
  if (sendTimer) clearTimeout(sendTimer)
  if (stopped) return
  sendTimer = setTimeout(async () => {
    await sendFrame()
    scheduleNextFrame()
  }, delayMs)
}

async function stopCapture() {
  stopped = true
  if (sendTimer) { clearTimeout(sendTimer); sendTimer = null }
  if (stream.value) {
    stream.value.getTracks().forEach(t => t.stop())
    stream.value = null
  }
  if (video.value) video.value.srcObject = null
  lastStatus.value = 'idle'
}

async function sendFrame() {
  if (!props.session?.id || !video.value || !canvas.value || sending.value) return
  if (!video.value.videoWidth) return

  sending.value = true
  const start = performance.now()
  try {
    const w = video.value.videoWidth
    const h = video.value.videoHeight
    canvas.value.width  = w
    canvas.value.height = h
    const ctx = canvas.value.getContext('2d')
    ctx.drawImage(video.value, 0, 0, w, h)
    const dataUrl = canvas.value.toDataURL('image/jpeg', 0.78)
    const b64     = dataUrl.split(',')[1]

    const { data } = await sessionsApi.ingestFrame(props.session.id, b64)
    const elapsed = performance.now() - start

    // Обновляем диагностику
    lastFrameQuality.value  = data?.frame_quality ?? null
    lastNotDetected.value   = data?.not_detected_reason ?? null
    lastFacesDetected.value = typeof data?.faces_detected === 'number' ? data.faces_detected : null
    missingStudents.value   = Array.isArray(data?.missing_students) ? data.missing_students : []

    if (data?.status === 'fallback') {
      lastStatus.value = 'fallback'
    } else {
      lastStatus.value = 'analyzing'
    }
    lastSentAt.value = new Date().toLocaleTimeString()
    errorMessage.value = ''

    adaptInterval(elapsed)
  } catch (e) {
    lastStatus.value   = 'error'
    errorMessage.value = e.response?.data?.message || e.message || 'Ошибка отправки кадра'
    // При ошибке немного отступаем
    slowResponses.value += 1
    fastResponses.value = 0
  } finally {
    sending.value = false
  }
}

const BASE_MS  = () => Math.max(1000, props.intervalSeconds * 1000)
const MIN_MS   = 1500
const MAX_MS   = 6000

function adaptInterval(elapsedMs) {
  if (elapsedMs > effectiveInterval.value) {
    // ML отстаёт от темпа — увеличиваем интервал
    slowResponses.value += 1
    fastResponses.value = 0
    if (slowResponses.value >= 2) {
      effectiveInterval.value = Math.min(MAX_MS, Math.max(BASE_MS(), effectiveInterval.value + 1000))
      slowResponses.value = 0
    }
  } else if (elapsedMs < effectiveInterval.value * 0.5) {
    // Запас по скорости — можно уменьшать
    fastResponses.value += 1
    slowResponses.value = 0
    if (fastResponses.value >= 3 && effectiveInterval.value > BASE_MS()) {
      effectiveInterval.value = Math.max(MIN_MS, BASE_MS(),
        effectiveInterval.value - 1000)
      fastResponses.value = 0
    }
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

function waitFor(predicate, timeoutMs = 1500) {
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
  width: 360px;
  background: #0d1220;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 14px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.5);
  overflow: hidden;
  z-index: 90;
  font-size: 12px;
}
.webcam-panel.minimized { width: 260px; }

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
.video-wrap { position: relative; }
.webcam-video {
  width: 100%;
  display: block;
  background: #000;
  aspect-ratio: 16/9;
  object-fit: cover;
  transform: scaleX(-1);
}
.webcam-canvas-hidden { display: none; }

.diagnostic-banner {
  position: absolute;
  left: 8px; right: 8px; bottom: 8px;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgba(0,0,0,0.6);
  border: 1px solid rgba(255,255,255,0.1);
  color: #fef3c7;
  font-size: 12px;
}
.diagnostic-banner.warn { background: rgba(120,53,15,0.85); border-color: rgba(245,158,11,0.6); color: #fde68a; }
.diagnostic-banner.err  { background: rgba(127,29,29,0.85); border-color: rgba(239,68,68,0.6);  color: #fecaca; }
.diagnostic-banner.info { background: rgba(30,58,138,0.85); border-color: rgba(59,130,246,0.6); color: #bfdbfe; }
.diagnostic-banner .hint { margin-top: 4px; opacity: 0.85; font-size: 11px; }

.faces-counter {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 4px 8px;
  background: rgba(0,0,0,0.55);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 6px;
  color: #cbd5e1;
  font-size: 11px;
}

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
