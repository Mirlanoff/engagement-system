import { ref, readonly } from 'vue'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

const from = ref(daysAgoIso(7))
const to   = ref(todayIso())

function setRange(newFrom, newTo) {
  from.value = newFrom
  to.value   = newTo
}

export function useAnalyticsFilters() {
  return {
    from: readonly(from),
    to:   readonly(to),
    setRange,
  }
}
