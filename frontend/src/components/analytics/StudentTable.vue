<template>
  <div class="student-table-wrap">
    <table class="student-table">
      <thead>
        <tr>
          <th class="col-status">Статус</th>
          <th class="col-name">Имя</th>
          <th class="col-engagement">Вовлечённость</th>
          <th class="col-emotion">Эмоция</th>
          <th class="col-gaze">Взгляд на доске</th>
          <th class="col-head">Поза головы</th>
          <th class="col-detect">Детекция</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in students"
          :key="row.student_id"
          class="student-row"
          :class="`level-${levelOf(row.avg_engagement)}`"
        >
          <td class="col-status">
            <span class="dot" :class="`level-${levelOf(row.avg_engagement)}`"></span>
          </td>
          <td class="col-name">
            <span class="name-text">{{ row.name || displayId(row.student_id) }}</span>
          </td>
          <td class="col-engagement">
            <div class="engagement-cell">
              <div class="bar">
                <div
                  class="bar-fill"
                  :class="`level-${levelOf(row.avg_engagement)}`"
                  :style="{ width: barWidth(row.avg_engagement) }"
                ></div>
              </div>
              <span class="score" :class="`level-${levelOf(row.avg_engagement)}`">
                {{ formatPct(row.avg_engagement) }}
              </span>
            </div>
          </td>
          <td class="col-emotion">
            <span
              class="emotion"
              :title="emotionLabel(row.dominant_emotion)"
            >{{ emotionEmoji(row.dominant_emotion) }}</span>
          </td>
          <td class="col-gaze">
            <span class="pct" :class="`level-${levelOf(row.gaze_on_board_pct)}`">
              {{ formatPct(row.gaze_on_board_pct) }}
            </span>
          </td>
          <td class="col-head">
            <span class="pct" :class="`level-${levelOf(row.head_on_board_pct)}`">
              {{ formatPct(row.head_on_board_pct) }}
            </span>
          </td>
          <td class="col-detect">
            <span class="pct muted">{{ formatPct(row.detection_rate) }}</span>
            <span class="snapshots">{{ row.total_snapshots }}</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
defineProps({
  students: { type: Array, default: () => [] },
})

const EMOTION_EMOJI = {
  neutral:   '😐',
  calm:      '😐',
  happy:     '😊',
  joy:       '😊',
  positive:  '😊',
  sad:       '😢',
  sadness:   '😢',
  angry:     '😠',
  anger:     '😠',
  surprised: '😮',
  surprise:  '😮',
  fear:      '😨',
  fearful:   '😨',
  disgust:   '🤢',
  disgusted: '🤢',
}
const EMOTION_LABEL = {
  neutral:   'Нейтрально',
  calm:      'Спокойствие',
  happy:     'Радость',
  joy:       'Радость',
  positive:  'Позитив',
  sad:       'Грусть',
  sadness:   'Грусть',
  angry:     'Злость',
  anger:     'Злость',
  surprised: 'Удивление',
  surprise:  'Удивление',
  fear:      'Тревога',
  fearful:   'Тревога',
  disgust:   'Отвращение',
  disgusted: 'Отвращение',
}

function emotionEmoji(emotion) {
  if (!emotion) return '—'
  return EMOTION_EMOJI[String(emotion).toLowerCase()] || '😐'
}

function emotionLabel(emotion) {
  if (!emotion) return 'Нет данных'
  return EMOTION_LABEL[String(emotion).toLowerCase()] || emotion
}

function levelOf(value) {
  const v = Number(value)
  if (!Number.isFinite(v) || v === 0) return 'unknown'
  if (v >= 70) return 'high'
  if (v >= 50) return 'medium'
  return 'low'
}

function formatPct(value) {
  const v = Number(value)
  if (!Number.isFinite(v)) return '—'
  return `${v.toFixed(1)}%`
}

function barWidth(value) {
  const v = Number(value)
  if (!Number.isFinite(v)) return '0%'
  return `${Math.max(0, Math.min(100, v))}%`
}

function displayId(id) {
  if (!id) return '—'
  const tail = String(id).slice(-4)
  return `Лицо ${tail}`
}
</script>

<style scoped>
.student-table-wrap {
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 12px;
  overflow: hidden;
  background: #1e293b;
}

.student-table {
  width: 100%;
  border-collapse: collapse;
}

.student-table thead th {
  position: sticky;
  top: 0;
  background: #1a2436;
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 12px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
  z-index: 1;
}
.student-table thead .col-status     { width: 64px; }
.student-table thead .col-engagement { width: 260px; }
.student-table thead .col-emotion    { width: 90px;  text-align: center; }
.student-table thead .col-gaze,
.student-table thead .col-head       { width: 130px; }
.student-table thead .col-detect     { width: 130px; }

.student-row td {
  padding: 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
  color: #e2e8f0;
  font-size: 13.5px;
  vertical-align: middle;
  transition: background 0.2s ease;
}
.student-row:hover td { background: rgba(255,255,255,0.04); }
.student-row:last-child td { border-bottom: none; }

.dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #475569;
  transition: background 0.3s ease, box-shadow 0.3s ease;
}
.dot.level-high   { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,0.6); }
.dot.level-medium { background: #f59e0b; box-shadow: 0 0 8px rgba(245,158,11,0.6); }
.dot.level-low    { background: #ef4444; box-shadow: 0 0 8px rgba(239,68,68,0.6); }
.dot.level-unknown { background: #475569; }

.name-text {
  font-weight: 500;
  color: #f1f5f9;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.engagement-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}
.bar {
  flex: 1;
  height: 8px;
  background: rgba(255,255,255,0.1);
  border-radius: 999px;
  overflow: hidden;
  min-width: 80px;
}
.bar-fill {
  height: 100%;
  width: 0%;
  background: #475569;
  border-radius: 999px;
  transition: width 0.3s ease, background-color 0.3s ease;
}
.bar-fill.level-high    { background: #22c55e; }
.bar-fill.level-medium  { background: #f59e0b; }
.bar-fill.level-low     { background: #ef4444; }
.bar-fill.level-unknown { background: #475569; }

.score, .pct {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 13px;
  color: #94a3b8;
  transition: color 0.3s ease;
  white-space: nowrap;
}
.score, .pct {
  min-width: 50px;
  text-align: right;
}
.score.level-high,   .pct.level-high   { color: #22c55e; }
.score.level-medium, .pct.level-medium { color: #f59e0b; }
.score.level-low,    .pct.level-low    { color: #ef4444; }
.pct.muted { color: #94a3b8; font-weight: 500; }

.col-emotion { text-align: center; }
.emotion { font-size: 20px; line-height: 1; }

.col-detect { white-space: nowrap; }
.snapshots {
  display: inline-block;
  margin-left: 8px;
  padding: 2px 8px;
  background: rgba(255,255,255,0.06);
  border-radius: 999px;
  font-size: 11px;
  color: #94a3b8;
  font-variant-numeric: tabular-nums;
}
</style>
