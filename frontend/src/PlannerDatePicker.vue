<script setup>
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  invalid: { type: Boolean, default: false },
  describedBy: { type: String, default: undefined },
})

const emit = defineEmits(['update:modelValue', 'change'])
const trigger = ref(null)
const calendarDialog = ref(null)
const isOpen = ref(false)
const today = new Date()
today.setHours(12, 0, 0, 0)

function dateKey(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function parseDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return null

  const year = Number(match[1])
  const month = Number(match[2]) - 1
  const day = Number(match[3])
  const date = new Date(year, month, day, 12)
  return date.getFullYear() === year && date.getMonth() === month && date.getDate() === day ? date : null
}

const initialDate = parseDate(props.modelValue) || today
const visibleYear = ref(initialDate.getFullYear())
const visibleMonth = ref(initialDate.getMonth())
const yearDraft = ref(String(visibleYear.value))
const choosingMonth = ref(false)
const todayKey = dateKey(today)
const monthNames = Array.from({ length: 12 }, (_, month) =>
  new Intl.DateTimeFormat(undefined, { month: 'short' }).format(new Date(2024, month, 1, 12)))
const weekdayNames = Array.from({ length: 7 }, (_, day) =>
  new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(new Date(2024, 0, day + 1, 12)))

const monthLabel = computed(() => new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })
  .format(new Date(visibleYear.value, visibleMonth.value, 1, 12)))
const selectedDateLabel = computed(() => {
  const date = parseDate(props.modelValue)
  return date
    ? new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' }).format(date)
    : 'Choose date'
})

const calendarDays = computed(() => {
  const firstWeekday = (new Date(visibleYear.value, visibleMonth.value, 1, 12).getDay() + 6) % 7
  const lastDay = new Date(visibleYear.value, visibleMonth.value + 1, 0, 12).getDate()

  return Array.from({ length: firstWeekday + lastDay }, (_, index) => {
    const day = index - firstWeekday + 1
    if (day < 1) return null

    const date = new Date(visibleYear.value, visibleMonth.value, day, 12)
    return {
      day,
      key: dateKey(date),
      label: new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(date),
    }
  })
})

watch(() => props.modelValue, (value) => {
  const date = parseDate(value)
  if (!date) return
  visibleYear.value = date.getFullYear()
  visibleMonth.value = date.getMonth()
  yearDraft.value = String(visibleYear.value)
})

function movePeriod(direction) {
  if (choosingMonth.value) {
    const year = visibleYear.value + direction
    if (year < 1900 || year > 9999) return
    visibleYear.value = year
  } else {
    const date = new Date(visibleYear.value, visibleMonth.value + direction, 1, 12)
    if (date.getFullYear() < 1900 || date.getFullYear() > 9999) return
    visibleYear.value = date.getFullYear()
    visibleMonth.value = date.getMonth()
  }
  yearDraft.value = String(visibleYear.value)
}

function commitYear() {
  const year = Number(yearDraft.value)
  if (Number.isInteger(year) && year >= 1900 && year <= 9999) visibleYear.value = year
  yearDraft.value = String(visibleYear.value)
}

function chooseMonth(month) {
  visibleMonth.value = month
  choosingMonth.value = false
}

function chooseDate(value) {
  emit('update:modelValue', value)
  emit('change', value)
  closePicker()
}

function chooseToday() {
  visibleYear.value = today.getFullYear()
  visibleMonth.value = today.getMonth()
  choosingMonth.value = false
  chooseDate(todayKey)
}

function focus() {
  trigger.value?.focus({ preventScroll: true })
}

function openPicker() {
  choosingMonth.value = false
  calendarDialog.value?.showModal()
  isOpen.value = true
}

function closePicker() {
  calendarDialog.value?.close()
}

function onPickerClose() {
  isOpen.value = false
  nextTick(focus)
}

defineExpose({ focus, scrollIntoView: (options) => trigger.value?.scrollIntoView(options) })
</script>

<template>
  <div class="planner-date-picker">
    <button ref="trigger" class="planner-date-trigger" :class="{ 'is-invalid': invalid }" type="button"
      :aria-label="`Due on: ${selectedDateLabel}`" aria-haspopup="dialog" :aria-expanded="isOpen"
      :aria-invalid="invalid" :aria-describedby="describedBy" @click="openPicker">
      <span>{{ selectedDateLabel }}</span>
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.8" />
        <path d="M7 3v4m10-4v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
    </button>

    <dialog ref="calendarDialog" class="calendar-sheet" aria-label="Choose due date" @close="onPickerClose" @click.self="closePicker">
      <div class="calendar-sheet-top">
        <h2>Choose date</h2>
        <button type="button" aria-label="Close calendar" @click="closePicker">×</button>
      </div>
      <div class="planner-calendar">
        <div class="planner-calendar-header">
          <button class="planner-calendar-arrow" type="button" :aria-label="choosingMonth ? 'Previous year' : 'Previous month'"
            @click="movePeriod(-1)">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 5-7 7 7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
          <button class="planner-calendar-title" type="button" :aria-expanded="choosingMonth" @click="choosingMonth = !choosingMonth">
            <span>{{ choosingMonth ? visibleYear : monthLabel }}</span>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
          <button class="planner-calendar-arrow" type="button" :aria-label="choosingMonth ? 'Next year' : 'Next month'"
            @click="movePeriod(1)">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
        </div>

        <template v-if="choosingMonth">
          <label class="planner-calendar-year">
            <span>Year</span>
            <input v-model="yearDraft" type="number" min="1900" max="9999" inputmode="numeric" @change="commitYear" @keydown.enter.prevent="commitYear" />
          </label>
          <div class="planner-calendar-months">
            <button v-for="(month, index) in monthNames" :key="index" type="button"
              :class="{ 'is-current': index === visibleMonth }" @click="chooseMonth(index)">{{ month }}</button>
          </div>
        </template>

        <template v-else>
          <div class="planner-calendar-weekdays" aria-hidden="true">
            <span v-for="weekday in weekdayNames" :key="weekday">{{ weekday }}</span>
          </div>
          <div class="planner-calendar-days">
            <template v-for="(date, index) in calendarDays" :key="date?.key || `blank-${index}`">
              <span v-if="!date" aria-hidden="true"></span>
              <button v-else class="planner-calendar-day" :class="{ 'is-selected': date.key === modelValue, 'is-today': date.key === todayKey }"
                type="button" :aria-label="date.label" :aria-pressed="date.key === modelValue" @click="chooseDate(date.key)">{{ date.day }}</button>
            </template>
          </div>
          <div class="planner-calendar-footer">
            <button type="button" @click="chooseToday">Today</button>
          </div>
        </template>
      </div>
    </dialog>
  </div>
</template>
