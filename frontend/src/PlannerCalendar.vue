<script setup>
import { computed, onMounted, ref } from 'vue'
import PlannerDatePicker from './PlannerDatePicker.vue'

const emit = defineEmits(['manage-task'])

function dateKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function dateFromKey(key) {
  return new Date(`${key}T12:00:00`)
}

const today = dateKey(new Date())
const visibleMonth = ref(today.slice(0, 7))
const selectedDate = ref(today)
const days = ref([])
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const formError = ref('')
const formDialog = ref(null)
const editingDayOffId = ref(null)
const form = ref({ title: '', start_on: today, end_on: today, goon_mode: false })

const dayByDate = computed(() => new Map(days.value.map((day) => [day.date, day])))
const selectedDay = computed(() => dayByDate.value.get(selectedDate.value))
const monthLabel = computed(() => new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })
  .format(dateFromKey(`${visibleMonth.value}-01`)))
const selectedLabel = computed(() => new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
  .format(dateFromKey(selectedDate.value)))

function taskProgress(day) {
  const tasks = day.scheduled_tasks || []
  const done = tasks.filter((task) => task.status === 'completed').length

  if (day.date > today) return { tone: 'upcoming', label: 'upcoming' }
  if (done === 0) return { tone: 'none-done', label: 'none done' }
  if (done === tasks.length) return { tone: 'all-done', label: 'all done' }
  return { tone: 'some-done', label: `${done} of ${tasks.length} done` }
}

const calendarCells = computed(() => {
  const [year, month] = visibleMonth.value.split('-').map(Number)
  const firstWeekday = (new Date(year, month - 1, 1, 12).getDay() + 6) % 7
  const count = new Date(year, month, 0, 12).getDate()
  return [
    ...Array.from({ length: firstWeekday }, () => null),
    ...Array.from({ length: count }, (_, index) => {
      const key = `${visibleMonth.value}-${String(index + 1).padStart(2, '0')}`
      return dayByDate.value.get(key) || { date: key, is_weekend: false, is_day_off: false, holiday_names: [], personal_days_off: [], scheduled_tasks: [] }
    }),
  ]
})

async function loadMonth() {
  loading.value = true
  error.value = ''
  try {
    const response = await fetch(`/api/v1/calendar?month=${visibleMonth.value}`, {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    })
    if (!response.ok) throw new Error('Calendar could not be loaded.')
    const result = await response.json()
    days.value = result.days
    if (!selectedDate.value.startsWith(visibleMonth.value)) selectedDate.value = `${visibleMonth.value}-01`
  } catch (cause) {
    error.value = cause.message || 'Calendar could not be loaded.'
  } finally {
    loading.value = false
  }
}

function moveMonth(offset) {
  const [year, month] = visibleMonth.value.split('-').map(Number)
  const next = new Date(year, month - 1 + offset, 1, 12)
  visibleMonth.value = dateKey(next).slice(0, 7)
  selectedDate.value = `${visibleMonth.value}-01`
  loadMonth()
}

function openDayOffForm(period = null) {
  editingDayOffId.value = period?.id ?? null
  form.value = period
    ? { title: period.title === 'Vacation' ? '' : period.title, start_on: period.start_on, end_on: period.end_on, goon_mode: period.goon_mode }
    : { title: '', start_on: selectedDate.value, end_on: selectedDate.value, goon_mode: false }
  formError.value = ''
  formDialog.value?.showModal()
}

async function saveDayOff() {
  if (!form.value.start_on || !form.value.end_on || form.value.end_on < form.value.start_on) {
    formError.value = 'Choose a valid date range.'
    return
  }
  saving.value = true
  formError.value = ''
  try {
    const response = await fetch(editingDayOffId.value === null
      ? '/api/v1/calendar/days-off'
      : `/api/v1/calendar/days-off/${editingDayOffId.value}`, {
      method: editingDayOffId.value === null ? 'POST' : 'PUT',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(form.value),
    })
    if (!response.ok) throw new Error('Could not save days off.')
    formDialog.value?.close()
    await loadMonth()
  } catch (cause) {
    formError.value = cause.message || 'Could not save days off.'
  } finally {
    saving.value = false
  }
}

async function removeDayOff(period) {
  if (saving.value) return
  saving.value = true
  error.value = ''
  try {
    const response = await fetch(`/api/v1/calendar/days-off/${period.id}`, {
      method: 'DELETE', headers: { Accept: 'application/json' },
    })
    if (!response.ok) throw new Error('Could not remove days off.')
    await loadMonth()
  } catch (cause) {
    error.value = cause.message || 'Could not remove days off.'
  } finally {
    saving.value = false
  }
}

onMounted(loadMonth)
defineExpose({ refresh: loadMonth })
</script>

<template>
  <section class="days-calendar" aria-labelledby="calendar-title">
    <header class="days-calendar-heading">
      <h1 id="calendar-title">Calendar</h1>
      <button class="days-calendar-add" type="button" @click="openDayOffForm()"><span aria-hidden="true">+</span>Add vacation</button>
    </header>

    <div class="days-calendar-card">
      <div class="days-calendar-month">
        <button type="button" aria-label="Previous month" @click="moveMonth(-1)">‹</button>
        <h2>{{ monthLabel }}</h2>
        <button type="button" aria-label="Next month" @click="moveMonth(1)">›</button>
      </div>
      <div class="days-calendar-weekdays" aria-hidden="true">
        <span v-for="weekday in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="weekday">{{ weekday }}</span>
      </div>
      <div class="days-calendar-grid" :aria-busy="loading">
        <template v-for="(day, index) in calendarCells" :key="day?.date || `blank-${index}`">
          <span v-if="!day" aria-hidden="true"></span>
          <button v-else type="button" class="days-calendar-day"
            :class="[{ 'is-selected': day.date === selectedDate, 'is-today': day.date === today, 'is-national': day.holiday_names.length, 'is-personal': day.personal_days_off.length, 'is-goon': day.goon_mode, 'is-weekend': day.is_weekend }, day.scheduled_tasks?.length ? `is-task-${taskProgress(day).tone}` : '']"
            :aria-pressed="day.date === selectedDate" :aria-label="`${day.date}${day.holiday_names.length ? `, ${day.holiday_names.join(', ')}` : ''}${day.personal_days_off.length ? ', personal days off' : ''}${day.goon_mode ? ', Goon mode' : ''}${day.scheduled_tasks?.length ? `, ${day.scheduled_tasks.length} scheduled ${day.scheduled_tasks.length === 1 ? 'task' : 'tasks'}, ${taskProgress(day).label}` : ''}`"
            @click="selectedDate = day.date">
            <span class="days-calendar-number">{{ Number(day.date.slice(-2)) }}</span>
            <span v-if="day.scheduled_tasks?.length" class="days-calendar-task-count" aria-hidden="true">{{ day.scheduled_tasks.length }}</span>
            <i v-if="day.holiday_names.length || day.personal_days_off.length" aria-hidden="true"></i>
          </button>
        </template>
      </div>
    </div>

    <p v-if="error" class="days-calendar-error" role="alert">{{ error }} <button type="button" @click="loadMonth">Retry</button></p>
    <div class="days-calendar-selected">
      <h2>{{ selectedLabel }}</h2>
      <template v-if="selectedDay">
        <p class="days-calendar-status">{{ selectedDay.goon_mode ? 'Goon mode · planning paused' : selectedDay.is_day_off ? 'Day off' : selectedDay.day_type === 'unverified' ? 'Holiday data unavailable' : 'Workday' }}</p>
        <p v-for="name in selectedDay.holiday_names" :key="name" class="days-calendar-event national">{{ name }}</p>
        <div v-for="period in selectedDay.personal_days_off" :key="period.id" class="days-calendar-event personal">
          <span>{{ period.title }}</span>
          <span class="days-calendar-event-actions">
            <button type="button" :disabled="saving" :aria-label="`Edit ${period.title}`" @click="openDayOffForm(period)">Edit</button>
            <button type="button" :disabled="saving" :aria-label="`Remove ${period.title}`" @click="removeDayOff(period)">Remove</button>
          </span>
        </div>
        <div v-if="selectedDay.scheduled_tasks?.length" class="days-calendar-tasks">
          <h3>Tasks</h3>
          <div v-for="assignment in selectedDay.scheduled_tasks" :key="assignment.id" class="days-calendar-task" :class="{ 'is-completed': assignment.status === 'completed' }">
            <span class="days-calendar-task-copy">
              <span class="days-calendar-task-title">{{ assignment.task.title }}</span>
              <span class="days-calendar-task-meta">
                {{ assignment.task.type === 'day' ? 'Day' : 'Evening' }}<template v-if="assignment.status === 'completed'"> · Done</template><template v-else-if="assignment.status === 'not_completed'"> · Not done</template><template v-else-if="assignment.source === 'advance_accepted'"> · Accepted</template>
              </span>
            </span>
            <button class="days-calendar-task-menu" type="button" :aria-label="`Manage ${assignment.task.title}`" @click="emit('manage-task', assignment)">
              <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
            </button>
          </div>
        </div>
      </template>
    </div>

    <dialog ref="formDialog" class="days-off-dialog" aria-labelledby="days-off-title" @click.self="formDialog?.close()">
      <form @submit.prevent="saveDayOff">
        <header>
          <h2 id="days-off-title">{{ editingDayOffId === null ? 'Add vacation' : 'Edit vacation' }}</h2>
          <button type="button" aria-label="Close" @click="formDialog?.close()">×</button>
        </header>
        <div class="days-off-fields">
          <label class="days-off-name"><span>Name <small>optional</small></span><input v-model="form.title" maxlength="100" type="text" /></label>
          <div class="days-off-dates">
            <div class="days-off-date"><span>From</span><PlannerDatePicker v-model="form.start_on" label="Start date" /></div>
            <div class="days-off-date"><span>To</span><PlannerDatePicker v-model="form.end_on" label="End date" /></div>
          </div>
          <label class="days-off-goon">
            <input v-model="form.goon_mode" type="checkbox" />
            <span><strong>Goon mode</strong><small>Pause planning for these dates</small></span>
          </label>
          <p v-if="formError" class="days-calendar-error" role="alert">{{ formError }}</p>
        </div>
        <footer><button type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save vacation' }}</button></footer>
      </form>
    </dialog>
  </section>
</template>

<style scoped>
.days-calendar { display: grid; gap: 18px; }
.days-calendar-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 5px 2px 6px; }
.days-calendar-heading h1 { margin: 0; color: var(--ink); font-size: clamp(36px, 7vw, 46px); letter-spacing: -.065em; line-height: 1.1; }
.days-calendar-add { display: inline-flex; align-items: center; gap: 8px; flex: none; padding: 12px 16px; border: 0; border-radius: 16px; background: var(--sun); box-shadow: 0 8px 20px rgba(226, 165, 0, .18); color: var(--ink); font-size: 14px; font-weight: 800; cursor: pointer; }
.days-calendar-add span { font-size: 22px; font-weight: 400; line-height: .7; }
.days-calendar-add:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(226, 165, 0, .24); }
.days-calendar-card { padding: 16px 14px 18px; border: 1px solid var(--line); border-radius: 26px; background: white; box-shadow: var(--shadow); }
.days-calendar-month { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.days-calendar-month h2 { margin: 0; font-size: 22px; letter-spacing: -.04em; }
.days-calendar-month button { width: 38px; height: 38px; border: 0; border-radius: 13px; background: #eef5f1; color: var(--ink); font-size: 30px; line-height: 30px; cursor: pointer; }
.days-calendar-weekdays, .days-calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 3px; }
.days-calendar-weekdays { margin-bottom: 7px; color: var(--muted); text-align: center; font-size: 11px; font-weight: 700; }
.days-calendar-weekdays span { padding: 5px 0; }
.days-calendar-day { position: relative; display: grid; place-items: center; width: 100%; aspect-ratio: 1; padding: 0; border: 2px solid transparent; border-radius: 14px; background: transparent; color: var(--ink); font-size: 15px; font-weight: 700; cursor: pointer; }
.days-calendar-day.is-weekend { background: #f1f7f3; color: var(--green-deep); }
.days-calendar-day.is-national { background: var(--sun-soft); color: var(--ink); }
.days-calendar-day.is-personal { background: #d9f4e8; color: var(--green-deep); }
.days-calendar-day.is-goon { background: #ffe9d9; color: #79431f; }
.days-calendar-day.is-task-all-done { background: #dff3e5; color: var(--ink); }
.days-calendar-day.is-task-some-done { background: #fff1c8; color: var(--ink); }
.days-calendar-day.is-task-none-done { background: #fbe8e5; color: var(--ink); }
.days-calendar-day.is-task-upcoming { background: #eef3f0; color: var(--ink); }
.days-calendar-day.is-selected { border-color: var(--green-deep); }
.days-calendar-day.is-today .days-calendar-number { text-decoration: underline; text-decoration-color: var(--green); text-decoration-thickness: 2px; text-underline-offset: 4px; }
.days-calendar-task-count { position: absolute; top: 3px; right: 3px; display: grid; place-items: center; min-width: 17px; height: 17px; padding: 0 3px; border: 1px solid #d6e5db; border-radius: 9px; background: white; color: var(--ink); font-size: 10px; font-weight: 800; line-height: 1; }
.days-calendar-day i { position: absolute; bottom: 4px; width: 4px; height: 4px; border-radius: 50%; background: currentColor; }
.days-calendar-selected { min-height: 128px; padding: 19px 21px; border: 1px solid var(--line); border-radius: 24px; background: white; box-shadow: var(--shadow); }
.days-calendar-selected h2 { margin: 0 0 5px; font-size: 20px; letter-spacing: -.04em; }
.days-calendar-status { margin: 0 0 10px; color: var(--muted); font-size: 14px; }
.days-calendar-event { margin: 8px 0 0; padding: 11px 13px; border-radius: 12px; font-size: 14px; font-weight: 650; }
.days-calendar-event.national { background: var(--sun-soft); }
.days-calendar-event.personal { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--green-soft); }
.days-calendar-event-actions { display: inline-flex; gap: 12px; }
.days-calendar-event button { border: 0; background: transparent; color: var(--green-deep); font-size: 13px; font-weight: 750; cursor: pointer; }
.days-calendar-tasks { display: grid; gap: 8px; margin-top: 17px; padding-top: 15px; border-top: 1px solid var(--line); }
.days-calendar-tasks h3 { margin: 0 0 2px; font-size: 15px; }
.days-calendar-task { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 9px 10px 9px 13px; border-radius: 12px; background: #f5f9f7; }
.days-calendar-task.is-completed { color: var(--muted); }
.days-calendar-task-copy { display: grid; gap: 2px; min-width: 0; }
.days-calendar-task-title { min-width: 0; font-size: 14px; font-weight: 700; overflow-wrap: anywhere; }
.days-calendar-task-meta { color: var(--muted); font-size: 12px; }
.days-calendar-task-menu { display: grid; flex: none; place-items: center; width: 36px; height: 36px; padding: 0; border: 1px solid var(--line); border-radius: 11px; background: white; color: var(--green-deep); cursor: pointer; }
.days-calendar-task-menu svg { width: 18px; height: 18px; }
.days-calendar-error { margin: 0; color: var(--error); font-size: 14px; }
.days-calendar-error button { border: 0; background: transparent; color: inherit; font-weight: 750; text-decoration: underline; cursor: pointer; }
.days-off-dialog[open] { position: fixed; z-index: 30; top: auto; right: 0; bottom: 0; left: 0; width: min(100%, 640px); max-width: none; max-height: min(90dvh, 700px); margin: 0 auto; padding: 0; border: 1px solid var(--line); border-radius: 25px 25px 0 0; background: white; color: var(--ink); box-shadow: 0 -18px 60px #12392d30; overflow: auto; }
.days-off-dialog::backdrop { background: #102c2580; backdrop-filter: blur(4px); }
.days-off-dialog form { display: grid; gap: 0; }
.days-off-dialog header { display: flex; align-items: center; justify-content: space-between; padding: 24px 24px 8px; }
.days-off-dialog h2 { margin: 0; font-size: 27px; letter-spacing: -.05em; }
.days-off-dialog header button { width: 37px; height: 37px; border: 0; border-radius: 12px; background: #eef5f1; color: var(--ink); font-size: 28px; cursor: pointer; }
.days-off-fields { display: grid; gap: 20px; padding: 20px 24px 24px; }
.days-off-name, .days-off-date { display: grid; gap: 8px; font-size: 14px; font-weight: 750; }
.days-off-name small { color: var(--muted); font-weight: 500; }
.days-off-name input { width: 100%; padding: 13px 14px; border: 1px solid var(--line); border-radius: 13px; outline-color: var(--green); background: #f8fbf9; color: var(--ink); }
.days-off-dates { display: grid; grid-template-columns: 1fr; gap: 16px; }
.days-off-goon { display: flex; align-items: center; gap: 12px; padding: 15px; border: 1px solid var(--line); border-radius: 15px; background: #f8fbf9; cursor: pointer; }
.days-off-goon input { appearance: none; flex: none; width: 21px; height: 21px; margin: 0; border: 2px solid #9ab5a9; border-radius: 6px; background: white; cursor: pointer; }
.days-off-goon input:checked { border-color: var(--green-deep); background: var(--green-deep); }
.days-off-goon input:checked::after { display: block; color: white; content: '✓'; font-size: 16px; font-weight: 800; line-height: 17px; text-align: center; }
.days-off-goon span { display: grid; gap: 2px; }
.days-off-goon strong { font-size: 14px; }
.days-off-goon small { color: var(--muted); font-size: 12px; font-weight: 500; }
.days-off-dialog footer { position: sticky; bottom: 0; padding: 16px 24px max(16px, env(safe-area-inset-bottom)); border-top: 1px solid var(--line); background: white; }
.days-off-dialog footer button { width: 100%; padding: 17px; border: 0; border-radius: 15px; background: var(--sun); color: var(--ink); font-weight: 800; cursor: pointer; }
@media (max-width: 390px) { .days-calendar-heading h1 { font-size: 34px; } .days-calendar-add { gap: 5px; padding: 11px 12px; font-size: 12px; } .days-calendar-add span { font-size: 19px; } }
</style>
