<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import PlannerDatePicker from './PlannerDatePicker.vue'
import PlannerSelect from './PlannerSelect.vue'

const tomorrow = new Date()
tomorrow.setDate(tomorrow.getDate() + 1)

const tomorrowLabel = new Intl.DateTimeFormat(undefined, {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
}).format(tomorrow)

const activeView = ref('tomorrow')
const entryKind = ref('task')
const serverAvailable = ref(true)
const isCheckingServer = ref(false)
const tasks = ref([])
const taskSearch = ref('')
const taskTypeFilter = ref('all')
const taskSort = ref('newest')
const overdueOnly = ref(false)
const taskFilterTrigger = ref(null)
const taskFilterDialog = ref(null)
const taskFiltersOpen = ref(false)
const todayDate = ref(localDate(new Date()))
const projects = ref([])
const selectedProjectId = ref(null)
const projectTaskDialog = ref(null)
const projectTaskSearch = ref('')
const projectTaskSavingId = ref(null)
const projectTaskError = ref('')
const selectedTaskId = ref(null)
const taskReturnView = ref('tasks')
const tasksLoading = ref(false)
const tasksError = ref('')
const projectsLoading = ref(false)
const projectsError = ref('')
const saveError = ref('')
const saveMessage = ref('')
const fieldErrors = reactive({})
const isSaving = ref(false)
const clientRequestIds = reactive({ task: crypto.randomUUID(), project: crypto.randomUUID() })
const taskTitleInput = ref(null)
const optionsTrigger = ref(null)
const optionsDialog = ref(null)
const optionsOpen = ref(false)
const energyDetail = ref(null)
const priorityDetail = ref(null)
const repeatDetail = ref(null)
const multiSessionDetail = ref(null)
const advanceNoticeDetail = ref(null)
const advanceNoticeSelect = ref(null)
const dayPreferenceDetail = ref(null)
const dayPreferenceSelect = ref(null)
const deadlineDetail = ref(null)
const dependenciesDetail = ref(null)
const projectDetail = ref(null)
const contextDetail = ref(null)
const dependencySearch = ref('')
const dependencySearchInput = ref(null)
const scheduleIntervalInput = ref(null)
const weekdayField = ref(null)
const monthDayInput = ref(null)
const countMinimumInput = ref(null)
const countMaximumInput = ref(null)
const countPeriodInput = ref(null)
const deadlineDateInput = ref(null)
const deadlineAmountInput = ref(null)
const repeatErrorFields = ['repeat', 'interval', 'weekdays', 'day_of_month', 'min_occurrences', 'max_occurrences', 'period_interval']
const repeatHasError = computed(() => repeatErrorFields.some((field) => fieldErrors[field]))
const deadlineErrorFields = ['deadline', 'deadline_date', 'deadline_amount']
const deadlineHasError = computed(() => deadlineErrorFields.some((field) => fieldErrors[field]))
const optionsHaveError = computed(() => Object.keys(fieldErrors).some((field) => field !== 'title'))
const dependencyChoices = computed(() => [...tasks.value, ...projects.value].filter((item) =>
  entryKind.value === 'project' || item.id !== taskForm.project_id))
const availableDependencies = computed(() => dependencyChoices.value.filter((item) =>
  item.title.toLocaleLowerCase().includes(dependencySearch.value.trim().toLocaleLowerCase())))
const selectedProject = computed(() => projects.value.find((project) => project.id === selectedProjectId.value))
const selectedTask = computed(() => tasks.value.find((task) => task.id === selectedTaskId.value))
const projectOptions = computed(() => [
  { value: null, label: 'None' },
  ...projects.value.map((project) => ({ value: project.id, label: project.title })),
])
const projectSteps = computed(() => tasks.value.filter((task) => task.project_id === selectedProjectId.value))
const eligibleProjectTasks = computed(() => tasks.value.filter((task) =>
  task.item_type === 'task'
  && task.lifecycle_state === 'active'
  && task.project_id === null
  && task.recurrence_type === 'one_time'
  && !task.dependencies?.some((dependency) => dependency.id === selectedProjectId.value)
  && !selectedProject.value?.dependencies?.some((dependency) => dependency.id === task.id)))
const filteredProjectTasks = computed(() => eligibleProjectTasks.value.filter((task) =>
  task.title.toLocaleLowerCase().includes(projectTaskSearch.value.trim().toLocaleLowerCase())))
const hasTaskListFilters = computed(() => Boolean(
  taskSearch.value.trim() || taskTypeFilter.value !== 'all' || taskSort.value !== 'newest' || overdueOnly.value,
))
const visibleTasks = computed(() => {
  const search = taskSearch.value.trim().toLocaleLowerCase()
  const priorityOrder = { high: 0, standard: 1, low: 2 }
  const matchingTasks = tasks.value.filter((task) => {
    if (taskTypeFilter.value !== 'all' && task.type !== taskTypeFilter.value) return false
    if (overdueOnly.value && !isOverdue(task)) return false
    if (!search) return true
    return task.title.toLocaleLowerCase().includes(search)
      || (task.description || '').toLocaleLowerCase().includes(search)
  })

  return matchingTasks.sort((first, second) => {
    if (taskSort.value === 'priority') {
      const order = (priorityOrder[first.priority] ?? 3) - (priorityOrder[second.priority] ?? 3)
      if (order !== 0) return order
    }

    if (taskSort.value === 'deadline') {
      const order = (first.deadline_on || '9999-12-31').localeCompare(second.deadline_on || '9999-12-31')
      if (order !== 0) return order
      if (first.deadline_on && first.deadline_is_hard !== second.deadline_is_hard) {
        return first.deadline_is_hard ? -1 : 1
      }
    }

    return second.id - first.id
  })
})
const priorityOptions = [
  { value: 'low', label: 'Low' },
  { value: 'standard', label: 'Standard' },
  { value: 'high', label: 'High' },
]
const taskSortOptions = [
  { value: 'newest', label: 'Newest first' },
  { value: 'priority', label: 'High priority' },
  { value: 'deadline', label: 'Due soonest' },
]
const weekdayChoices = [
  { value: 1, short: 'Mon', name: 'Monday' },
  { value: 2, short: 'Tue', name: 'Tuesday' },
  { value: 3, short: 'Wed', name: 'Wednesday' },
  { value: 4, short: 'Thu', name: 'Thursday' },
  { value: 5, short: 'Fri', name: 'Friday' },
  { value: 6, short: 'Sat', name: 'Saturday' },
  { value: 0, short: 'Sun', name: 'Sunday' },
]
const scheduleUnitOptions = [
  { value: 'day', label: 'Days' },
  { value: 'week', label: 'Weeks' },
  { value: 'month', label: 'Months' },
]
const countPeriodOptions = [
  { value: 'week', label: 'Weeks' },
  { value: 'month', label: 'Months' },
]
const deadlineUnitOptions = [
  { value: 'day', label: 'Days' },
  { value: 'week', label: 'Weeks' },
  { value: 'month', label: 'Months' },
  { value: 'year', label: 'Years' },
]
const advanceNoticeOptions = [
  { value: null, label: 'None' },
  ...Array.from({ length: 14 }, (_, index) => ({
    value: index + 1,
    label: `${index + 1} ${index === 0 ? 'day' : 'days'}`,
  })),
]
const dayPreferenceOptions = [
  { value: null, label: 'No preference' },
  { value: 'workday', label: 'Workdays' },
  { value: 'non_workday', label: 'Non-workdays' },
]
const monthDayOptions = Array.from({ length: 31 }, (_, index) => ({ value: index + 1, label: String(index + 1) }))

function initialRepeatValues() {
  return {
    unit: 'week',
    interval: 1,
    weekdays: [new Date().getDay()],
    day_of_month: new Date().getDate(),
    count_mode: 'exact',
    min_occurrences: 2,
    max_occurrences: 3,
    period_unit: 'week',
    period_interval: 1,
  }
}

const repeatForm = reactive(initialRepeatValues())

function initialDeadlineValues() {
  return {
    mode: 'none',
    date: '',
    amount: 1,
    unit: 'week',
    end_unit: 'week',
    is_hard: false,
  }
}

const deadlineForm = reactive(initialDeadlineValues())
const taskForm = reactive({
  title: '',
  description: '',
  type: 'evening',
  energy_cost: 'standard',
  priority: 'standard',
  recurrence_type: 'one_time',
  is_multi_session: false,
  advance_notice_days: null,
  day_preference: null,
  day_preference_is_hard: false,
  dependency_ids: [],
  project_id: null,
})

watch(() => taskForm.project_id, (projectId) => {
  if (projectId !== null) taskForm.dependency_ids = taskForm.dependency_ids.filter((id) => id !== projectId)
})

watch(() => taskForm.type, (type) => {
  taskForm.energy_cost = type === 'evening' ? 'standard' : null
  taskForm.priority = type === 'evening' ? 'standard' : null
  if (type === 'day') {
    taskForm.is_multi_session = false
    taskForm.advance_notice_days = null
    taskForm.day_preference = null
    taskForm.day_preference_is_hard = false
    resetDeadlineForm()
  }
})

watch(() => taskForm.recurrence_type, (type) => {
  if (type !== 'one_time') {
    taskForm.is_multi_session = false
    resetDeadlineForm()
  }
})

function priorityLabel(priority) {
  return priorityOptions.find((option) => option.value === priority)?.label || 'Standard'
}

function dayPreferenceLabel(preference, isHard) {
  if (preference === null) return 'No preference'
  const days = preference === 'workday' ? 'workdays' : 'non-workdays'
  return isHard ? `${days[0].toUpperCase()}${days.slice(1)} only` : `Prefer ${days}`
}

function localDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function isOverdue(task) {
  return Boolean(task.deadline_on && task.deadline_on < todayDate.value)
}

function resetTaskListView() {
  taskSearch.value = ''
  taskTypeFilter.value = 'all'
  taskSort.value = 'newest'
  overdueOnly.value = false
}

function openTaskFilters() {
  if (!taskFilterDialog.value?.open) taskFilterDialog.value?.showModal()
  taskFiltersOpen.value = true
}

function closeTaskFilters() {
  taskFilterDialog.value?.close()
}

function clearTaskListFilters() {
  resetTaskListView()
  closeTaskFilters()
}

function onTaskFiltersClose() {
  taskFiltersOpen.value = false
  taskFilterTrigger.value?.focus({ preventScroll: true })
}

function refreshToday() {
  todayDate.value = localDate(new Date())
}

function validDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T12:00:00`)
  return !Number.isNaN(date.getTime()) && localDate(date) === value
}

function deadlineOn() {
  if (deadlineForm.mode === 'none') return null
  if (deadlineForm.mode === 'date') return validDate(deadlineForm.date) ? deadlineForm.date : null

  const date = new Date()
  date.setHours(12, 0, 0, 0)

  if (deadlineForm.mode === 'end_of_period') {
    if (deadlineForm.end_unit === 'week') {
      date.setDate(date.getDate() + (7 - date.getDay()) % 7)
    } else {
      date.setDate(new Date(date.getFullYear(), date.getMonth() + 1, 0, 12).getDate())
    }
  } else if (deadlineForm.mode === 'relative') {
    if (!validWholeNumber(deadlineForm.amount, 1, 999)) return null
    const amount = Number(deadlineForm.amount)

    if (deadlineForm.unit === 'day' || deadlineForm.unit === 'week') {
      date.setDate(date.getDate() + amount * (deadlineForm.unit === 'week' ? 7 : 1))
    } else {
      const months = amount * (deadlineForm.unit === 'year' ? 12 : 1)
      const targetMonth = date.getMonth() + months
      const lastDay = new Date(date.getFullYear(), targetMonth + 1, 0, 12).getDate()
      const day = Math.min(date.getDate(), lastDay)
      date.setDate(1)
      date.setMonth(targetMonth)
      date.setDate(day)
    }
  }

  return localDate(date)
}

function formatDeadline(dateString) {
  const [year, month, day] = dateString.split('-').map(Number)
  const date = new Date(year, month - 1, day, 12)
  const options = { month: 'short', day: 'numeric' }
  if (year !== new Date().getFullYear()) options.year = 'numeric'
  return new Intl.DateTimeFormat(undefined, options).format(date)
}

function deadlineLabel() {
  if (deadlineForm.mode === 'none') return 'No deadline'
  const date = deadlineOn()
  if (!date) return 'Choose a date'
  return `${deadlineForm.is_hard ? 'Hard' : 'Soft'} · ${formatDeadline(date)}`
}

function repeatLabel(type, rule) {
  if (type === 'one_time' || !rule) return 'One-time'

  if (type === 'schedule') {
    const interval = Number(rule.interval)
    if (rule.unit === 'day') return interval === 1 ? 'Every day' : `Every ${interval} days`
    if (rule.unit === 'month') {
      const period = interval === 1 ? 'Every month' : `Every ${interval} months`
      return `${period} · day ${rule.day_of_month}`
    }

    const selectedDays = [...(rule.weekdays || [])]
      .sort((a, b) => (a + 6) % 7 - (b + 6) % 7)
    const days = selectedDays
      .map((day) => {
        const choice = weekdayChoices.find((item) => item.value === day)
        return selectedDays.length === 1 ? choice?.name : choice?.short
      })
      .filter(Boolean)
      .join(', ')
    return interval === 1 ? `Every ${days}` : `Every ${interval} weeks · ${days}`
  }

  const minimum = Number(rule.min_occurrences)
  const maximum = Number(rule.max_occurrences)
  const count = minimum === maximum ? `${minimum}` : `${minimum}–${maximum}`
  const period = rule.period_interval === 1
    ? `each ${rule.period_unit}`
    : `every ${rule.period_interval} ${rule.period_unit}s`
  return `${count} ${minimum === maximum && minimum === 1 ? 'time' : 'times'} ${period}`
}

const optionsSummary = computed(() => {
  const changes = []

  if (entryKind.value === 'project') {
    if (taskForm.priority !== 'standard') changes.push(`${priorityLabel(taskForm.priority)} priority`)
  } else if (taskForm.type === 'evening') {
    if (taskForm.energy_cost === 'high') changes.push('High energy')
    if (taskForm.priority !== 'standard') changes.push(`${priorityLabel(taskForm.priority)} priority`)
    if (taskForm.advance_notice_days !== null) changes.push(`${taskForm.advance_notice_days}-day notice`)
    if (taskForm.day_preference !== null) changes.push(dayPreferenceLabel(taskForm.day_preference, taskForm.day_preference_is_hard))
  }
  if (entryKind.value === 'task' && taskForm.recurrence_type !== 'one_time') changes.push(repeatLabel(taskForm.recurrence_type, currentRepeatRule()))
  if (entryKind.value === 'project' || (taskForm.type === 'evening' && taskForm.recurrence_type === 'one_time')) {
    if (entryKind.value === 'task' && taskForm.is_multi_session) changes.push('Multi-session')
    if (deadlineForm.mode !== 'none') {
      const date = deadlineOn()
      changes.push(date ? `${deadlineForm.is_hard ? 'Hard' : 'Soft'} deadline ${formatDeadline(date)}` : 'Deadline')
    }
  }
  if (entryKind.value === 'task' && taskForm.project_id !== null) {
    changes.push(projects.value.find((project) => project.id === taskForm.project_id)?.title || 'Project')
  }
  if (taskForm.description.trim()) changes.push('Description')
  if (taskForm.dependency_ids.length) changes.push(`${taskForm.dependency_ids.length} ${taskForm.dependency_ids.length === 1 ? 'dependency' : 'dependencies'}`)

  if (changes.length === 0) return 'Defaults'
  if (changes.length > 2) return `${changes.slice(0, 2).join(' · ')} +${changes.length - 2}`
  return changes.join(' · ')
})

function currentRepeatRule() {
  if (taskForm.recurrence_type === 'schedule') {
    const rule = {
      unit: repeatForm.unit,
      interval: repeatForm.interval,
    }
    if (repeatForm.unit === 'week') rule.weekdays = [...repeatForm.weekdays].sort((a, b) => a - b)
    if (repeatForm.unit === 'month') rule.day_of_month = repeatForm.day_of_month
    return rule
  }

  if (taskForm.recurrence_type === 'count') {
    return {
      period_unit: repeatForm.period_unit,
      period_interval: repeatForm.period_interval,
      min_occurrences: repeatForm.min_occurrences,
      max_occurrences: repeatForm.count_mode === 'exact'
        ? repeatForm.min_occurrences
        : repeatForm.max_occurrences,
    }
  }

  return null
}

function validWholeNumber(value, minimum, maximum) {
  const number = Number(value)
  return Number.isInteger(number) && number >= minimum && number <= maximum
}

function validateTaskForm() {
  const errors = {}
  if (!taskForm.title.trim()) errors.title = entryKind.value === 'project' ? 'Give this project a name.' : 'Give this task a name.'

  if (entryKind.value === 'project') {
    if (deadlineForm.mode === 'date' && !validDate(deadlineForm.date)) errors.deadline_date = 'Choose a date.'
    if (deadlineForm.mode === 'relative' && !validWholeNumber(deadlineForm.amount, 1, 999)) errors.deadline_amount = 'Enter 1–999.'
    return errors
  }

  if (taskForm.project_id !== null && taskForm.recurrence_type !== 'one_time') {
    errors.project = 'Project steps must be one-time.'
  }
  if (taskForm.type === 'evening' && taskForm.advance_notice_days !== null
    && !validWholeNumber(taskForm.advance_notice_days, 1, 14)) {
    errors.advance_notice = 'Choose 1–14 days.'
  }
  if (taskForm.type === 'evening' && taskForm.day_preference !== null
    && !['workday', 'non_workday'].includes(taskForm.day_preference)) {
    errors.day_preference = 'Choose a day type.'
  }

  if (taskForm.recurrence_type === 'one_time') {
    if (taskForm.type === 'evening' && deadlineForm.mode === 'date' && !validDate(deadlineForm.date)) {
      errors.deadline_date = 'Choose a date.'
    }
    if (taskForm.type === 'evening' && deadlineForm.mode === 'relative' && !validWholeNumber(deadlineForm.amount, 1, 999)) {
      errors.deadline_amount = 'Enter 1–999.'
    }
    return errors
  }

  if (taskForm.recurrence_type === 'schedule') {
    if (!validWholeNumber(repeatForm.interval, 1, 365)) {
      errors.interval = 'Enter 1–365.'
    }
    if (repeatForm.unit === 'week' && repeatForm.weekdays.length === 0) {
      errors.weekdays = 'Select a day.'
    }
    if (repeatForm.unit === 'month' && !validWholeNumber(repeatForm.day_of_month, 1, 31)) {
      errors.day_of_month = 'Select a day.'
    }
  }

  if (taskForm.recurrence_type === 'count') {
    if (!validWholeNumber(repeatForm.min_occurrences, 1, 99)) {
      errors.min_occurrences = 'Enter 1–99.'
    }
    if (repeatForm.count_mode === 'range') {
      if (!validWholeNumber(repeatForm.max_occurrences, 1, 99)) {
        errors.max_occurrences = 'Enter 1–99.'
      }
      if (!errors.min_occurrences && !errors.max_occurrences
        && Number(repeatForm.max_occurrences) < Number(repeatForm.min_occurrences)) {
        errors.max_occurrences = 'Keep this at or above Minimum.'
      }
    }
    if (!validWholeNumber(repeatForm.period_interval, 1, 120)) {
      errors.period_interval = 'Enter 1–120.'
    }
  }

  return errors
}

function replaceFieldErrors(errors) {
  for (const field of Object.keys(fieldErrors)) delete fieldErrors[field]
  Object.assign(fieldErrors, errors)
}

function clearFieldError(field) {
  delete fieldErrors[field]
  saveError.value = ''
}

function clearRepeatErrors() {
  for (const field of repeatErrorFields) delete fieldErrors[field]
}

function clearDeadlineErrors() {
  for (const field of deadlineErrorFields) delete fieldErrors[field]
}

function onDayPreferenceChange(value) {
  if (value === null) taskForm.day_preference_is_hard = false
  clearFieldError('day_preference')
}

function resetDeadlineForm() {
  Object.assign(deadlineForm, initialDeadlineValues())
  clearDeadlineErrors()
}

function onDeadlineModeChange() {
  clearDeadlineErrors()
  if (deadlineForm.mode !== 'none') return
  deadlineForm.is_hard = false
  deadlineDetail.value.open = false
  deadlineDetail.value.querySelector('summary')?.focus({ preventScroll: true })
}

function onRepeatTypeChange() {
  clearRepeatErrors()
  finishRepeatChoice()
}

function onScheduleUnitChange() {
  clearFieldError('weekdays')
  clearFieldError('day_of_month')
}

function mapServerErrors(errors) {
  const fieldMap = {
    title: ['title', 'Give this task a name.'],
    priority: ['priority', 'Choose a priority.'],
    project_id: ['project', 'Choose an active project.'],
    is_multi_session: ['sessions', 'Check sessions.'],
    advance_notice_days: ['advance_notice', 'Choose 1–14 days.'],
    day_preference: ['day_preference', 'Choose a day type.'],
    day_preference_is_hard: ['day_preference', 'Check the day preference.'],
    dependency_ids: ['dependencies', 'Check dependencies.'],
    'dependency_ids.*': ['dependencies', 'Choose saved tasks.'],
    deadline_on: deadlineForm.mode === 'date'
      ? ['deadline_date', 'Choose a date.']
      : ['deadline', 'Check the deadline.'],
    deadline_is_hard: ['deadline', 'Check the deadline.'],
    'recurrence_rule.interval': ['interval', 'Enter 1–365.'],
    'recurrence_rule.weekdays': ['weekdays', 'Select a day.'],
    'recurrence_rule.day_of_month': ['day_of_month', 'Select a day.'],
    'recurrence_rule.min_occurrences': ['min_occurrences', 'Enter 1–99.'],
    'recurrence_rule.max_occurrences': ['max_occurrences', 'Check Maximum.'],
    'recurrence_rule.period_interval': ['period_interval', 'Enter 1–120.'],
  }
  const mapped = {}

  for (const key of Object.keys(errors)) {
    const [field, message] = fieldMap[key]
      || (key.startsWith('dependency_ids.') ? fieldMap['dependency_ids.*'] : null)
      || (key.startsWith('recurrence_rule.weekdays.') ? fieldMap['recurrence_rule.weekdays'] : null)
      || (key.startsWith('recurrence_') ? ['repeat', 'Check the repeat settings.'] : [null, null])
    if (field && !mapped[field]) mapped[field] = message
  }

  return mapped
}

async function focusFirstError() {
  const firstField = Object.keys(fieldErrors)[0]
  if (!firstField) return

  if (firstField !== 'title') openOptions()
  if (firstField === 'priority' && priorityDetail.value) priorityDetail.value.open = true
  else if (firstField === 'project' && projectDetail.value) projectDetail.value.open = true
  else if (firstField === 'sessions' && multiSessionDetail.value) multiSessionDetail.value.open = true
  else if (firstField === 'advance_notice' && advanceNoticeDetail.value) advanceNoticeDetail.value.open = true
  else if (firstField === 'day_preference' && dayPreferenceDetail.value) dayPreferenceDetail.value.open = true
  else if (firstField === 'dependencies' && dependenciesDetail.value) dependenciesDetail.value.open = true
  else if (deadlineHasError.value && deadlineDetail.value) deadlineDetail.value.open = true
  else if (repeatHasError.value && repeatDetail.value) repeatDetail.value.open = true
  await nextTick()

  const targets = {
    title: taskTitleInput.value,
    priority: priorityDetail.value?.querySelector('summary'),
    project: projectDetail.value?.querySelector('summary'),
    sessions: multiSessionDetail.value?.querySelector('summary'),
    advance_notice: advanceNoticeSelect.value || advanceNoticeDetail.value?.querySelector('summary'),
    day_preference: dayPreferenceSelect.value || dayPreferenceDetail.value?.querySelector('summary'),
    dependencies: dependencySearchInput.value || dependenciesDetail.value?.querySelector('summary'),
    deadline: deadlineDetail.value?.querySelector('summary'),
    deadline_date: deadlineDateInput.value,
    deadline_amount: deadlineAmountInput.value,
    repeat: repeatDetail.value?.querySelector('summary'),
    interval: scheduleIntervalInput.value,
    weekdays: weekdayField.value?.querySelector('input'),
    day_of_month: monthDayInput.value,
    min_occurrences: countMinimumInput.value,
    max_occurrences: countMaximumInput.value,
    period_interval: countPeriodInput.value,
  }
  const target = targets[firstField]
  target?.focus?.({ preventScroll: true })
  const targetElement = target?.$el || target
  targetElement?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })
}

function finishRepeatChoice() {
  if (taskForm.recurrence_type !== 'one_time') return
  repeatDetail.value.open = false
  repeatDetail.value.querySelector('summary')?.focus({ preventScroll: true })
}

async function checkServer() {
  isCheckingServer.value = true

  try {
    const response = await fetch('/api/v1/health', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })

    if (!response.ok || (await response.json()).status !== 'ok') {
      throw new Error('Planner server is unavailable')
    }

    serverAvailable.value = true
  } catch {
    serverAvailable.value = false
  } finally {
    isCheckingServer.value = false
  }
}

async function loadTasks() {
  tasksLoading.value = true
  tasksError.value = ''

  try {
    const response = await fetch('/api/v1/tasks', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })

    if (!response.ok) {
      throw new Error('Could not load tasks')
    }

    tasks.value = (await response.json()).data
  } catch {
    tasksError.value = "Tasks couldn't be loaded. Try again."
  } finally {
    tasksLoading.value = false
  }
}

async function loadProjects() {
  projectsLoading.value = true
  projectsError.value = ''

  try {
    const response = await fetch('/api/v1/projects', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })

    if (!response.ok) throw new Error('Could not load projects')
    projects.value = (await response.json()).data
  } catch {
    projectsError.value = "Projects couldn't be loaded. Try again."
  } finally {
    projectsLoading.value = false
  }
}

function showTasks() {
  activeView.value = 'tasks'
  selectedTaskId.value = null
  loadTasks()
  loadProjects()
}

function openTaskDetails(task, returnView = 'tasks') {
  selectedTaskId.value = task.id
  taskReturnView.value = returnView
  activeView.value = 'tasks'
}

function closeTaskDetails() {
  selectedTaskId.value = null
  activeView.value = taskReturnView.value
}

function showProjects() {
  activeView.value = 'projects'
  selectedProjectId.value = null
  loadProjects()
  loadTasks()
}

async function openProjectForm() {
  resetCaptureForm()
  clientRequestIds.project = crypto.randomUUID()
  setEntryKind('project')
  activeView.value = 'capture'
  loadTasks()
  loadProjects()
  await nextTick()
  taskTitleInput.value?.focus()
}

function setEntryKind(kind) {
  entryKind.value = kind
  if (kind === 'project' && !taskForm.priority) taskForm.priority = 'standard'
  replaceFieldErrors({})
  saveError.value = ''
  saveMessage.value = ''
  closeAllDetails()
}

function openOptions() {
  if (!optionsDialog.value?.open) optionsDialog.value?.showModal()
  optionsOpen.value = true
}

function closeOptions() {
  optionsDialog.value?.close()
}

function onOptionsClose() {
  optionsOpen.value = false
  optionsTrigger.value?.focus({ preventScroll: true })
}

async function openTaskForm(projectId = null) {
  resetCaptureForm()
  clientRequestIds.task = crypto.randomUUID()
  setEntryKind('task')
  taskForm.project_id = projectId
  activeView.value = 'capture'
  loadTasks()
  loadProjects()
  await nextTick()
  taskTitleInput.value?.focus()
}

function openProjectTaskPicker() {
  projectTaskSearch.value = ''
  projectTaskError.value = ''
  projectTaskDialog.value?.showModal()
  loadTasks()
}

function createTaskInProject() {
  projectTaskDialog.value?.close()
  openTaskForm(selectedProjectId.value)
}

async function addExistingTaskToProject(task) {
  if (projectTaskSavingId.value !== null || selectedProjectId.value === null) return

  projectTaskSavingId.value = task.id
  projectTaskError.value = ''

  try {
    const response = await fetch(`/api/v1/tasks/${task.id}/project`, {
      method: 'PATCH',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: selectedProjectId.value }),
    })
    const result = await response.json()

    if (!response.ok) {
      throw new Error(result.errors?.project_id?.[0] || "Couldn't add this task. Try again.")
    }

    tasks.value = tasks.value.map((item) => item.id === task.id ? result.data : item)
    projectTaskDialog.value?.close()
  } catch (error) {
    projectTaskError.value = error.message || "Couldn't add this task. Try again."
  } finally {
    projectTaskSavingId.value = null
  }
}

function closeOtherDetails(event) {
  if (!event.target.open) return

  for (const detail of [energyDetail.value, priorityDetail.value, repeatDetail.value, multiSessionDetail.value, advanceNoticeDetail.value, dayPreferenceDetail.value, deadlineDetail.value, projectDetail.value, dependenciesDetail.value, contextDetail.value]) {
    if (detail && detail !== event.target) detail.open = false
  }
}

function finishChoice(field) {
  const detail = {
    energy: energyDetail.value,
    priority: priorityDetail.value,
  }[field]
  if (!detail) return

  detail.open = false
  detail.querySelector('summary')?.focus({ preventScroll: true })
}

function finishPriorityChoice() {
  clearFieldError('priority')
  finishChoice('priority')
}

function closeAllDetails() {
  for (const detail of [energyDetail.value, priorityDetail.value, repeatDetail.value, multiSessionDetail.value, advanceNoticeDetail.value, dayPreferenceDetail.value, deadlineDetail.value, projectDetail.value, dependenciesDetail.value, contextDetail.value]) {
    if (detail) detail.open = false
  }
}

function resetCaptureForm() {
  taskForm.title = ''
  taskForm.description = ''
  taskForm.type = 'evening'
  taskForm.energy_cost = 'standard'
  taskForm.priority = 'standard'
  taskForm.recurrence_type = 'one_time'
  taskForm.is_multi_session = false
  taskForm.advance_notice_days = null
  taskForm.day_preference = null
  taskForm.day_preference_is_hard = false
  taskForm.dependency_ids = []
  taskForm.project_id = null
  dependencySearch.value = ''
  Object.assign(repeatForm, initialRepeatValues())
  resetDeadlineForm()
  replaceFieldErrors({})
  saveError.value = ''
  saveMessage.value = ''
  closeAllDetails()
}

async function saveTask() {
  if (isSaving.value) return

  saveError.value = ''
  saveMessage.value = ''
  replaceFieldErrors(validateTaskForm())

  if (Object.keys(fieldErrors).length) {
    await focusFirstError()
    return
  }

  isSaving.value = true

  try {
    const isProject = entryKind.value === 'project'
    const taskInput = {
      client_request_id: clientRequestIds[entryKind.value],
      title: taskForm.title.trim(),
      description: taskForm.description.trim() || null,
      dependency_ids: taskForm.dependency_ids.length ? [...taskForm.dependency_ids] : null,
    }

    if (isProject) {
      taskInput.priority = taskForm.priority
      if (deadlineForm.mode !== 'none') {
        taskInput.deadline_on = deadlineOn()
        taskInput.deadline_is_hard = deadlineForm.is_hard
      }
    } else {
      taskInput.type = taskForm.type
      taskInput.recurrence_type = taskForm.recurrence_type
      taskInput.project_id = taskForm.project_id

      if (taskForm.type === 'evening') {
        taskInput.energy_cost = taskForm.energy_cost
        taskInput.priority = taskForm.priority
        taskInput.advance_notice_days = taskForm.advance_notice_days
        if (taskForm.day_preference !== null) {
          taskInput.day_preference = taskForm.day_preference
          taskInput.day_preference_is_hard = taskForm.day_preference_is_hard
        }
      }

      if (taskForm.recurrence_type !== 'one_time') {
        taskInput.recurrence_rule = currentRepeatRule()
      } else if (taskForm.type === 'evening') {
        taskInput.is_multi_session = taskForm.is_multi_session
        if (deadlineForm.mode !== 'none') {
          taskInput.deadline_on = deadlineOn()
          taskInput.deadline_is_hard = deadlineForm.is_hard
        }
      }
    }

    const response = await fetch(isProject ? '/api/v1/projects' : '/api/v1/tasks', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(taskInput),
    })
    const result = await response.json()

    if (!response.ok) {
      if (response.status === 422 && result.errors) {
        replaceFieldErrors(mapServerErrors(result.errors))
        if (Object.keys(fieldErrors).length) {
          await focusFirstError()
          return
        }
      }
      throw new Error(`Couldn't save this ${isProject ? 'project' : 'task'}. Try again.`)
    }

    const savedProjectId = taskForm.project_id
    if (isProject) {
      projects.value = [result.data, ...projects.value.filter((project) => project.id !== result.data.id)]
      selectedProjectId.value = result.data.id
      activeView.value = 'projects'
    } else {
      tasks.value = [result.data, ...tasks.value.filter((task) => task.id !== result.data.id)]
      if (savedProjectId !== null) {
        selectedProjectId.value = savedProjectId
        activeView.value = 'projects'
      } else {
        selectedTaskId.value = null
        activeView.value = 'tasks'
      }
    }
    resetCaptureForm()
    clientRequestIds[entryKind.value] = crypto.randomUUID()
  } catch (error) {
    saveError.value = error.message || `Couldn't save this ${entryKind.value}. Try again.`
  } finally {
    isSaving.value = false
  }
}

let todayRefreshInterval
onMounted(() => {
  checkServer()
  refreshToday()
  document.addEventListener('visibilitychange', refreshToday)
  todayRefreshInterval = window.setInterval(refreshToday, 60_000)
})
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', refreshToday)
  window.clearInterval(todayRefreshInterval)
})
</script>

<template>
  <div class="app-shell">
    <header class="app-header">
      <button class="brand-lockup" type="button" aria-label="Planner home" @click="activeView = 'tomorrow'">
        <img class="brand-flower" src="/sunflower.svg?v=2" alt="" />
        <span class="wordmark">Planner</span>
      </button>
    </header>

    <main class="main-content">
      <div v-if="!serverAvailable" class="connection-notice" role="alert">
        <p>Planner can't reach its server right now.</p>
        <button type="button" :disabled="isCheckingServer" @click="checkServer">
          {{ isCheckingServer ? 'Checking…' : 'Try again' }}
        </button>
      </div>

      <template v-if="activeView === 'tomorrow'">
        <section class="hero hero-tomorrow" aria-labelledby="tomorrow-title">
          <div class="hero-content">
            <p class="hero-kicker">Your day ahead</p>
            <h1 id="tomorrow-title">Tomorrow<span class="title-dot">.</span></h1>
            <p class="hero-date">{{ tomorrowLabel }}</p>
          </div>
          <img class="hero-flower" src="/sunflower.svg?v=2" alt="" aria-hidden="true" />
        </section>

        <button class="add-task-shortcut" type="button" @click="openTaskForm()">
          <span class="shortcut-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
            </svg>
          </span>
          <span>Add a task</span>
          <svg class="shortcut-arrow" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>

        <section class="empty-plan" aria-labelledby="empty-plan-title">
          <div class="empty-plan-mark" aria-hidden="true">
            <img src="/sunflower.svg?v=2" alt="" />
          </div>
          <div>
            <p class="section-kicker">No plan yet</p>
            <h2 id="empty-plan-title">Tomorrow is open.</h2>
            <p>Your plan for tomorrow will appear here.</p>
          </div>
        </section>
      </template>

      <template v-else-if="activeView === 'tasks'">
        <template v-if="selectedTask">
          <header class="task-detail-heading">
            <button class="project-back" type="button" @click="closeTaskDetails">← {{ taskReturnView === 'projects' ? 'Project' : 'Tasks' }}</button>
            <h1>{{ selectedTask.title }}</h1>
          </header>
          <section class="task-detail" aria-label="Task details">
            <p v-if="selectedTask.description" class="task-detail-description">{{ selectedTask.description }}</p>
            <dl class="task-detail-facts">
              <div><dt>Type</dt><dd>{{ selectedTask.type === 'day' ? 'Day' : 'Evening' }}</dd></div>
              <div v-if="selectedTask.project_id"><dt>Project</dt><dd>{{ projects.find((project) => project.id === selectedTask.project_id)?.title || 'Project' }}</dd></div>
              <div v-if="selectedTask.type === 'evening'"><dt>Energy</dt><dd>{{ selectedTask.energy_cost === 'high' ? 'High' : 'Standard' }}</dd></div>
              <div v-if="selectedTask.type === 'evening'"><dt>Priority</dt><dd>{{ priorityLabel(selectedTask.priority) }}</dd></div>
              <div><dt>Repeat</dt><dd>{{ repeatLabel(selectedTask.recurrence_type, selectedTask.recurrence_rule) }}</dd></div>
              <div v-if="selectedTask.is_multi_session"><dt>Sessions</dt><dd>Multi-session</dd></div>
              <div v-if="selectedTask.advance_notice_days"><dt>Advance notice</dt><dd>{{ selectedTask.advance_notice_days }} {{ selectedTask.advance_notice_days === 1 ? 'day' : 'days' }}</dd></div>
              <div v-if="selectedTask.day_preference"><dt>Day preference</dt><dd>{{ dayPreferenceLabel(selectedTask.day_preference, selectedTask.day_preference_is_hard) }}</dd></div>
              <div v-if="selectedTask.deadline_on"><dt>Deadline</dt><dd>{{ formatDeadline(selectedTask.deadline_on) }} · {{ selectedTask.deadline_is_hard ? 'Hard' : 'Soft' }}</dd></div>
              <div v-if="selectedTask.dependencies?.length"><dt>Dependencies</dt><dd>{{ selectedTask.dependencies.map((dependency) => dependency.title).join(', ') }}</dd></div>
            </dl>
          </section>
        </template>
        <template v-else>
          <header class="collection-heading">
            <div class="collection-title">
              <h1>Tasks</h1>
              <span v-if="!tasksLoading && !tasksError" class="collection-count">{{ visibleTasks.length === tasks.length ? tasks.length : `${visibleTasks.length}/${tasks.length}` }}</span>
            </div>
            <button class="collection-add" type="button" @click="openTaskForm()">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
              <span>Add task</span>
            </button>
          </header>
          <section v-if="tasks.length > 0" class="task-list-controls" aria-label="Find and sort tasks">
            <label class="task-list-search">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" stroke-width="1.8" /><path d="m15.5 15.5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
              <span class="visually-hidden">Search task titles and descriptions</span>
              <input v-model="taskSearch" type="search" placeholder="Search tasks" />
            </label>
            <button ref="taskFilterTrigger" class="task-filter-button" :class="{ 'is-active': taskTypeFilter !== 'all' || taskSort !== 'newest' }"
              type="button" aria-label="Filter and sort tasks" aria-haspopup="dialog" :aria-expanded="taskFiltersOpen" @click="openTaskFilters">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M7 12h10m-7 5h4" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
              <span>Filter</span>
              <span v-if="taskTypeFilter !== 'all' || taskSort !== 'newest'" class="task-filter-dot" aria-hidden="true"></span>
            </button>
            <button class="task-overdue-filter" type="button" :aria-pressed="overdueOnly" @click="overdueOnly = !overdueOnly">Overdue</button>
          </section>
          <dialog ref="taskFilterDialog" class="options-sheet task-filter-sheet" aria-labelledby="task-filter-title"
            @click.self="closeTaskFilters" @close="onTaskFiltersClose">
            <header class="options-sheet-header task-filter-heading">
              <h2 id="task-filter-title">Filter &amp; Sort</h2>
              <div class="task-filter-heading-actions">
                <button v-if="hasTaskListFilters" class="task-filter-clear" type="button" @click="clearTaskListFilters">Clear all</button>
                <button class="project-task-picker-close" type="button" aria-label="Close" @click="closeTaskFilters">×</button>
              </div>
            </header>
            <div class="options-sheet-body task-filter-sheet-body">
              <div class="task-filter-section">
                <h3>Type</h3>
                <div class="task-type-filter" role="group" aria-label="Task type">
                  <button type="button" :aria-pressed="taskTypeFilter === 'all'" @click="taskTypeFilter = 'all'">All</button>
                  <button type="button" :aria-pressed="taskTypeFilter === 'day'" @click="taskTypeFilter = 'day'">Day</button>
                  <button type="button" :aria-pressed="taskTypeFilter === 'evening'" @click="taskTypeFilter = 'evening'">Evening</button>
                </div>
              </div>
              <div class="task-filter-section">
                <h3>Sort</h3>
                <div class="task-filter-sort-options" role="group" aria-label="Sort tasks">
                  <button v-for="option in taskSortOptions" :key="option.value" type="button"
                    :aria-pressed="taskSort === option.value" @click="taskSort = option.value">
                    {{ option.label }}
                    <svg v-if="taskSort === option.value" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  </button>
                </div>
              </div>
            </div>
          </dialog>
          <section class="task-list task-list-main" aria-label="Saved tasks">
            <p v-if="tasksLoading" class="list-message">Loading tasks…</p>
            <div v-else-if="tasksError" class="list-message" role="alert">
              <p>{{ tasksError }}</p>
              <button type="button" @click="loadTasks">Try again</button>
            </div>
            <p v-else-if="tasks.length === 0" class="list-message">No tasks saved yet.</p>
            <div v-else-if="visibleTasks.length === 0" class="list-message">
              <p>{{ overdueOnly && taskTypeFilter === 'all' && !taskSearch.trim() ? 'No overdue tasks.' : 'No matching tasks.' }}</p>
              <button type="button" @click="resetTaskListView">Reset view</button>
            </div>
            <ul v-else>
              <li v-for="task in visibleTasks" :key="task.id">
                <button class="task-row" :class="{ 'has-hard-deadline': task.deadline_on && task.deadline_is_hard, 'is-overdue': isOverdue(task) }"
                  type="button" @click="openTaskDetails(task)">
                  <span class="task-row-copy">
                    <strong>{{ task.title }}</strong>
                    <span v-if="task.description" class="task-row-description">
                      <span class="task-row-label">Description</span>
                      <span class="task-row-description-text">{{ task.description }}</span>
                    </span>
                    <span class="task-row-facts">
                      <span class="task-row-type" :class="task.type === 'day' ? 'is-day' : 'is-evening'">{{ task.type === 'day' ? 'Day' : 'Evening' }}</span>
                      <span v-if="task.project_id" class="task-row-fact">
                        <span class="task-row-label">Project</span>
                        <span class="task-row-fact-value">{{ projects.find((project) => project.id === task.project_id)?.title || `#${task.project_id}` }}</span>
                      </span>
                      <span v-if="task.type === 'evening'" class="task-row-fact">
                        <span class="task-row-label">Priority</span>
                        {{ priorityLabel(task.priority) }}
                      </span>
                      <span v-if="task.deadline_on" class="task-row-deadline" :class="task.deadline_is_hard ? 'is-hard' : 'is-soft'">
                        <span class="task-row-label">Deadline</span>
                        {{ isOverdue(task) ? 'Overdue · ' : '' }}{{ task.deadline_is_hard ? 'Hard' : 'Soft' }} · {{ formatDeadline(task.deadline_on) }}
                      </span>
                      <span v-else-if="task.recurrence_type !== 'one_time'" class="task-row-fact">
                        <span class="task-row-label">Repeat</span>
                        {{ repeatLabel(task.recurrence_type, task.recurrence_rule) }}
                      </span>
                    </span>
                  </span>
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
              </li>
            </ul>
          </section>
        </template>
      </template>

      <template v-else-if="activeView === 'capture'">
        <header class="capture-heading">
          <button class="project-back" type="button" @click="entryKind === 'project' ? showProjects() : showTasks()">← {{ entryKind === 'project' ? 'Projects' : 'Tasks' }}</button>
          <h1>New {{ entryKind }}</h1>
        </header>

        <section class="task-panel" :aria-label="entryKind === 'project' ? 'Add a project' : 'Add a task'">
          <form class="task-form" novalidate @submit.prevent="saveTask">
            <div class="capture-kind" role="group" aria-label="Add">
              <button type="button" :aria-pressed="entryKind === 'task'" @click="setEntryKind('task')">Task</button>
              <button type="button" :aria-pressed="entryKind === 'project'" @click="setEntryKind('project')">Project</button>
            </div>
            <label class="field" :class="{ 'has-error': fieldErrors.title }">
              <span>{{ entryKind === 'project' ? 'Project name' : 'What needs doing?' }}</span>
              <input ref="taskTitleInput" v-model="taskForm.title" type="text" maxlength="255" required
                :aria-invalid="Boolean(fieldErrors.title)" :aria-describedby="fieldErrors.title ? 'task-title-error' : undefined"
                @input="clearFieldError('title')" />
              <span v-if="fieldErrors.title" id="task-title-error" class="field-error">{{ fieldErrors.title }}</span>
            </label>

            <fieldset v-if="entryKind === 'task'" class="capture-type">
              <legend>Task type</legend>
              <div class="choice-grid type-choices" role="radiogroup" aria-label="Task type">
                <label class="choice-option" :class="{ selected: taskForm.type === 'evening' }">
                  <input v-model="taskForm.type" type="radio" name="task-type" value="evening" />
                  <span>Evening</span>
                  <small>Substantial</small>
                </label>
                <label class="choice-option" :class="{ selected: taskForm.type === 'day' }">
                  <input v-model="taskForm.type" type="radio" name="task-type" value="day" />
                  <span>Day</span>
                  <small>Quick item</small>
                </label>
              </div>
            </fieldset>

            <button ref="optionsTrigger" class="options-trigger" :class="{ 'has-error': optionsHaveError }" type="button"
              aria-haspopup="dialog" aria-controls="task-options" :aria-expanded="optionsOpen" @click="openOptions">
              <span>Options</span>
              <span class="options-trigger-value">{{ optionsHaveError ? 'Check options' : optionsSummary }}</span>
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>

            <p v-if="saveError" class="form-error" role="alert">{{ saveError }}</p>
            <p v-if="saveMessage" class="form-success" role="status">{{ saveMessage }}</p>
            <button class="primary-button" type="submit" :disabled="isSaving">
              {{ isSaving ? 'Saving…' : entryKind === 'project' ? 'Save project' : 'Save task' }}
            </button>
          </form>

          <dialog id="task-options" ref="optionsDialog" class="options-sheet" aria-labelledby="task-options-title"
            @close="onOptionsClose" @click.self="closeOptions">
            <header class="options-sheet-header">
              <h2 id="task-options-title">Options</h2>
            </header>
            <div class="options-sheet-body">
              <div class="form-details">
                <details v-if="entryKind === 'task' && taskForm.type === 'evening'" ref="energyDetail" class="form-detail" @toggle="closeOtherDetails">
                  <summary>
                    <span>Energy needed</span>
                    <span class="detail-value">{{ taskForm.energy_cost === 'high' ? 'High' : 'Standard' }}</span>
                  </summary>
                  <div class="detail-content">
                    <div class="choice-grid energy-choices" role="radiogroup" aria-label="Energy needed">
                      <label class="choice-option energy-option" :class="{ selected: taskForm.energy_cost === 'standard' }">
                        <input v-model="taskForm.energy_cost" type="radio" name="energy" value="standard" @change="finishChoice('energy')" />
                        <span>Standard</span>
                      </label>
                      <label class="choice-option energy-option" :class="{ selected: taskForm.energy_cost === 'high' }">
                        <input v-model="taskForm.energy_cost" type="radio" name="energy" value="high" @change="finishChoice('energy')" />
                        <span>High</span>
                      </label>
                    </div>
                  </div>
                </details>

                <details v-if="entryKind === 'project' || taskForm.type === 'evening'" ref="priorityDetail" class="form-detail" :class="{ 'has-error': fieldErrors.priority }" @toggle="closeOtherDetails">
                  <summary>
                    <span>Priority</span>
                    <span class="detail-value">{{ priorityLabel(taskForm.priority) }}</span>
                  </summary>
                  <div class="detail-content">
                    <div class="choice-grid priority-choices" role="radiogroup" aria-label="Priority">
                      <label v-for="option in priorityOptions" :key="option.value" class="choice-option priority-option" :class="{ selected: taskForm.priority === option.value }">
                        <input v-model="taskForm.priority" type="radio" name="priority" :value="option.value" @change="finishPriorityChoice" />
                        <span>{{ option.label }}</span>
                      </label>
                    </div>
                    <p v-if="fieldErrors.priority" class="field-error choice-error">{{ fieldErrors.priority }}</p>
                  </div>
                </details>

                <details v-if="entryKind === 'task'" ref="repeatDetail" class="form-detail" :class="{ 'has-error': repeatHasError }" @toggle="closeOtherDetails">
                  <summary>
                    <span>Repeat</span>
                    <span class="detail-value">{{ repeatLabel(taskForm.recurrence_type, currentRepeatRule()) }}</span>
                  </summary>
                  <div class="detail-content repeat-content">
                    <div class="repeat-modes" role="radiogroup" aria-label="Repeat">
                      <label class="repeat-mode" :class="{ selected: taskForm.recurrence_type === 'one_time' }">
                        <input v-model="taskForm.recurrence_type" type="radio" name="repeat-type" value="one_time" @change="onRepeatTypeChange" />
                        <span>One-time</span>
                      </label>
                      <label class="repeat-mode" :class="{ selected: taskForm.recurrence_type === 'schedule' }">
                        <input v-model="taskForm.recurrence_type" type="radio" name="repeat-type" value="schedule" :disabled="taskForm.project_id !== null" @change="onRepeatTypeChange" />
                        <span>Set days</span>
                      </label>
                      <label class="repeat-mode" :class="{ selected: taskForm.recurrence_type === 'count' }">
                        <input v-model="taskForm.recurrence_type" type="radio" name="repeat-type" value="count" :disabled="taskForm.project_id !== null" @change="onRepeatTypeChange" />
                        <span>Count target</span>
                      </label>
                    </div>
                    <p v-if="taskForm.project_id !== null" class="field-note">Project steps are one-time.</p>
                    <p v-if="fieldErrors.repeat" class="field-error">{{ fieldErrors.repeat }}</p>

                    <div v-if="taskForm.recurrence_type === 'schedule'" class="repeat-settings">
                      <div class="repeat-fields">
                        <label class="repeat-field repeat-number" :class="{ 'has-error': fieldErrors.interval }">
                          <span>Every</span>
                          <input ref="scheduleIntervalInput" v-model.number="repeatForm.interval" type="number" min="1" max="365" required
                            :aria-invalid="Boolean(fieldErrors.interval)" :aria-describedby="fieldErrors.interval ? 'repeat-interval-error' : undefined"
                            @input="clearFieldError('interval')" />
                          <span v-if="fieldErrors.interval" id="repeat-interval-error" class="field-error">{{ fieldErrors.interval }}</span>
                        </label>
                        <div class="repeat-field repeat-unit">
                          <span>Unit</span>
                          <PlannerSelect v-model="repeatForm.unit" :options="scheduleUnitOptions" label="Repeat unit" @change="onScheduleUnitChange" />
                        </div>
                      </div>

                      <fieldset v-if="repeatForm.unit === 'week'" ref="weekdayField" class="weekday-field" :class="{ 'has-error': fieldErrors.weekdays }" :aria-describedby="fieldErrors.weekdays ? 'repeat-weekdays-error' : undefined">
                        <legend>On</legend>
                        <div class="weekday-options">
                          <label v-for="day in weekdayChoices" :key="day.value" class="weekday-option" :class="{ selected: repeatForm.weekdays.includes(day.value) }">
                            <input v-model="repeatForm.weekdays" type="checkbox" :value="day.value" :aria-label="day.name" :aria-invalid="Boolean(fieldErrors.weekdays)" @change="clearFieldError('weekdays')" />
                            <span>{{ day.short }}</span>
                          </label>
                        </div>
                        <p v-if="fieldErrors.weekdays" id="repeat-weekdays-error" class="field-error">{{ fieldErrors.weekdays }}</p>
                      </fieldset>

                      <div v-if="repeatForm.unit === 'month'" class="repeat-field" :class="{ 'has-error': fieldErrors.day_of_month }">
                        <span>Day of month (last day if shorter)</span>
                        <PlannerSelect ref="monthDayInput" v-model="repeatForm.day_of_month" :options="monthDayOptions" label="Day of month" grid
                          :invalid="Boolean(fieldErrors.day_of_month)" :described-by="fieldErrors.day_of_month ? 'repeat-month-day-error' : undefined"
                          @change="clearFieldError('day_of_month')" />
                        <span v-if="fieldErrors.day_of_month" id="repeat-month-day-error" class="field-error">{{ fieldErrors.day_of_month }}</span>
                      </div>

                    </div>

                    <div v-if="taskForm.recurrence_type === 'count'" class="repeat-settings">
                      <div class="count-modes" role="radiogroup" aria-label="Count type">
                        <label :class="{ selected: repeatForm.count_mode === 'exact' }">
                          <input v-model="repeatForm.count_mode" type="radio" name="count-mode" value="exact" @change="clearFieldError('max_occurrences')" />
                          <span>Exact</span>
                        </label>
                        <label :class="{ selected: repeatForm.count_mode === 'range' }">
                          <input v-model="repeatForm.count_mode" type="radio" name="count-mode" value="range" @change="clearFieldError('max_occurrences')" />
                          <span>Range</span>
                        </label>
                      </div>
                      <div class="repeat-fields">
                        <label class="repeat-field repeat-number" :class="{ 'has-error': fieldErrors.min_occurrences }">
                          <span>{{ repeatForm.count_mode === 'exact' ? 'Times' : 'Minimum' }}</span>
                          <input ref="countMinimumInput" v-model.number="repeatForm.min_occurrences" type="number" min="1" max="99" required
                            :aria-invalid="Boolean(fieldErrors.min_occurrences)" :aria-describedby="fieldErrors.min_occurrences ? 'repeat-minimum-error' : undefined"
                            @input="clearFieldError('min_occurrences')" />
                          <span v-if="fieldErrors.min_occurrences" id="repeat-minimum-error" class="field-error">{{ fieldErrors.min_occurrences }}</span>
                        </label>
                        <label v-if="repeatForm.count_mode === 'range'" class="repeat-field repeat-number" :class="{ 'has-error': fieldErrors.max_occurrences }">
                          <span>Maximum</span>
                          <input ref="countMaximumInput" v-model.number="repeatForm.max_occurrences" type="number" min="1" max="99" required
                            :aria-invalid="Boolean(fieldErrors.max_occurrences)" :aria-describedby="fieldErrors.max_occurrences ? 'repeat-maximum-error' : undefined"
                            @input="clearFieldError('max_occurrences')" />
                          <span v-if="fieldErrors.max_occurrences" id="repeat-maximum-error" class="field-error">{{ fieldErrors.max_occurrences }}</span>
                        </label>
                      </div>
                      <div class="repeat-fields">
                        <label class="repeat-field repeat-number" :class="{ 'has-error': fieldErrors.period_interval }">
                          <span>Every</span>
                          <input ref="countPeriodInput" v-model.number="repeatForm.period_interval" type="number" min="1" max="120" required
                            :aria-invalid="Boolean(fieldErrors.period_interval)" :aria-describedby="fieldErrors.period_interval ? 'repeat-period-error' : undefined"
                            @input="clearFieldError('period_interval')" />
                          <span v-if="fieldErrors.period_interval" id="repeat-period-error" class="field-error">{{ fieldErrors.period_interval }}</span>
                        </label>
                        <div class="repeat-field repeat-unit">
                          <span>Period</span>
                          <PlannerSelect v-model="repeatForm.period_unit" :options="countPeriodOptions" label="Count period" />
                        </div>
                      </div>
                    </div>
                  </div>
                </details>

                <details v-if="entryKind === 'task' && taskForm.type === 'evening' && taskForm.recurrence_type === 'one_time'" ref="multiSessionDetail" class="form-detail" @toggle="closeOtherDetails">
                  <summary>
                    <span>Sessions</span>
                    <span class="detail-value">{{ taskForm.is_multi_session ? 'Multiple' : 'One' }}</span>
                  </summary>
                  <div class="detail-content">
                    <label class="session-option">
                      <input v-model="taskForm.is_multi_session" type="checkbox" />
                      <span>Keep this task active until marked Completed</span>
                    </label>
                  </div>
                </details>

                <details v-if="entryKind === 'task' && taskForm.type === 'evening'" ref="advanceNoticeDetail" class="form-detail" :class="{ 'has-error': fieldErrors.advance_notice }" @toggle="closeOtherDetails">
                  <summary>
                    <span>Advance notice</span>
                    <span class="detail-value">{{ taskForm.advance_notice_days === null ? 'None' : `${taskForm.advance_notice_days} ${taskForm.advance_notice_days === 1 ? 'day' : 'days'}` }}</span>
                  </summary>
                  <div class="detail-content">
                    <div class="advance-notice-select">
                      <PlannerSelect ref="advanceNoticeSelect" v-model="taskForm.advance_notice_days" :options="advanceNoticeOptions"
                        label="Advance notice" :invalid="Boolean(fieldErrors.advance_notice)"
                        :described-by="fieldErrors.advance_notice ? 'advance-notice-error' : undefined"
                        @change="clearFieldError('advance_notice')" />
                    </div>
                    <p v-if="fieldErrors.advance_notice" id="advance-notice-error" class="field-error">{{ fieldErrors.advance_notice }}</p>
                  </div>
                </details>

                <details v-if="entryKind === 'task' && taskForm.type === 'evening'" ref="dayPreferenceDetail" class="form-detail" :class="{ 'has-error': fieldErrors.day_preference }" @toggle="closeOtherDetails">
                  <summary>
                    <span>Day preference</span>
                    <span class="detail-value">{{ dayPreferenceLabel(taskForm.day_preference, taskForm.day_preference_is_hard) }}</span>
                  </summary>
                  <div class="detail-content day-preference-content">
                    <div class="day-preference-select">
                      <PlannerSelect ref="dayPreferenceSelect" v-model="taskForm.day_preference" :options="dayPreferenceOptions"
                        label="Day preference" :invalid="Boolean(fieldErrors.day_preference)"
                        :described-by="fieldErrors.day_preference ? 'day-preference-error' : undefined"
                        @change="onDayPreferenceChange" />
                    </div>
                    <label v-if="taskForm.day_preference !== null" class="session-option">
                      <input v-model="taskForm.day_preference_is_hard" type="checkbox" @change="clearFieldError('day_preference')" />
                      <span>Hard: only on these days</span>
                    </label>
                    <p v-if="fieldErrors.day_preference" id="day-preference-error" class="field-error">{{ fieldErrors.day_preference }}</p>
                  </div>
                </details>

                <details v-if="entryKind === 'project' || (taskForm.type === 'evening' && taskForm.recurrence_type === 'one_time')" ref="deadlineDetail" class="form-detail" :class="{ 'has-error': deadlineHasError }" @toggle="closeOtherDetails">
                  <summary>
                    <span>Deadline</span>
                    <span class="detail-value">{{ deadlineLabel() }}</span>
                  </summary>
                  <div class="detail-content deadline-content">
                    <div class="deadline-modes" role="radiogroup" aria-label="Deadline timing">
                      <label class="repeat-mode" :class="{ selected: deadlineForm.mode === 'none' }">
                        <input v-model="deadlineForm.mode" type="radio" name="deadline-mode" value="none" @change="onDeadlineModeChange" />
                        <span>No deadline</span>
                      </label>
                      <label class="repeat-mode" :class="{ selected: deadlineForm.mode === 'date' }">
                        <input v-model="deadlineForm.mode" type="radio" name="deadline-mode" value="date" @change="onDeadlineModeChange" />
                        <span>Date</span>
                      </label>
                      <label class="repeat-mode" :class="{ selected: deadlineForm.mode === 'end_of_period' }">
                        <input v-model="deadlineForm.mode" type="radio" name="deadline-mode" value="end_of_period" @change="onDeadlineModeChange" />
                        <span>End of</span>
                      </label>
                      <label class="repeat-mode" :class="{ selected: deadlineForm.mode === 'relative' }">
                        <input v-model="deadlineForm.mode" type="radio" name="deadline-mode" value="relative" @change="onDeadlineModeChange" />
                        <span>From today</span>
                      </label>
                    </div>

                    <div v-if="deadlineForm.mode !== 'none'" class="deadline-settings">
                      <div v-if="deadlineForm.mode === 'date'" class="repeat-field" :class="{ 'has-error': fieldErrors.deadline_date }">
                        <span>Due on</span>
                        <PlannerDatePicker ref="deadlineDateInput" v-model="deadlineForm.date"
                          :invalid="Boolean(fieldErrors.deadline_date)" :described-by="fieldErrors.deadline_date ? 'deadline-date-error' : undefined"
                          @change="clearFieldError('deadline_date')" />
                        <span v-if="fieldErrors.deadline_date" id="deadline-date-error" class="field-error">{{ fieldErrors.deadline_date }}</span>
                      </div>

                      <div v-if="deadlineForm.mode === 'end_of_period'" class="deadline-period-modes" role="radiogroup" aria-label="End of">
                        <label :class="{ selected: deadlineForm.end_unit === 'week' }">
                          <input v-model="deadlineForm.end_unit" type="radio" name="deadline-end-unit" value="week" />
                          <span>Week</span>
                        </label>
                        <label :class="{ selected: deadlineForm.end_unit === 'month' }">
                          <input v-model="deadlineForm.end_unit" type="radio" name="deadline-end-unit" value="month" />
                          <span>Month</span>
                        </label>
                      </div>

                      <div v-if="deadlineForm.mode === 'relative'" class="repeat-fields">
                        <label class="repeat-field repeat-number" :class="{ 'has-error': fieldErrors.deadline_amount }">
                          <span>In</span>
                          <input ref="deadlineAmountInput" v-model.number="deadlineForm.amount" type="number" min="1" max="999"
                            :aria-invalid="Boolean(fieldErrors.deadline_amount)" :aria-describedby="fieldErrors.deadline_amount ? 'deadline-amount-error' : undefined"
                            @input="clearFieldError('deadline_amount')" />
                          <span v-if="fieldErrors.deadline_amount" id="deadline-amount-error" class="field-error">{{ fieldErrors.deadline_amount }}</span>
                        </label>
                        <div class="repeat-field repeat-unit">
                          <span>Unit</span>
                          <PlannerSelect v-model="deadlineForm.unit" :options="deadlineUnitOptions" label="Deadline unit" />
                        </div>
                      </div>

                      <label class="deadline-hard">
                        <input v-model="deadlineForm.is_hard" type="checkbox" />
                        <span>Hard deadline</span>
                      </label>
                    </div>
                    <p v-if="fieldErrors.deadline" class="field-error">{{ fieldErrors.deadline }}</p>
                  </div>
                </details>

                <details v-if="entryKind === 'task'" ref="projectDetail" class="form-detail" :class="{ 'has-error': fieldErrors.project }" @toggle="closeOtherDetails">
                  <summary>
                    <span>Project</span>
                    <span class="detail-value">{{ projects.find((project) => project.id === taskForm.project_id)?.title || 'None' }}</span>
                  </summary>
                  <div class="detail-content">
                    <p v-if="taskForm.recurrence_type !== 'one_time'" class="field-note">Recurring tasks cannot belong to a project.</p>
                    <p v-else-if="projectsLoading" class="dependency-message">Loading projects…</p>
                    <p v-else-if="projectsError" class="dependency-message">Projects couldn't be loaded.</p>
                    <p v-else-if="projects.length === 0" class="dependency-message">Create a project first.</p>
                    <PlannerSelect v-else v-model="taskForm.project_id" :options="projectOptions" label="Project"
                      :invalid="Boolean(fieldErrors.project)" @change="clearFieldError('project')" />
                    <p v-if="fieldErrors.project" class="field-error">{{ fieldErrors.project }}</p>
                  </div>
                </details>

                <details ref="dependenciesDetail" class="form-detail" :class="{ 'has-error': fieldErrors.dependencies }" @toggle="closeOtherDetails">
                  <summary>
                    <span>Dependencies</span>
                    <span class="detail-value">{{ taskForm.dependency_ids.length || 'None' }}</span>
                  </summary>
                  <div class="detail-content dependency-content">
                    <p v-if="tasksLoading || projectsLoading" class="dependency-message">Loading…</p>
                    <p v-else-if="tasksError || projectsError" class="dependency-message">Saved items couldn't be loaded.</p>
                    <p v-else-if="dependencyChoices.length === 0" class="dependency-message">Save another task or project first.</p>
                    <template v-else>
                      <label v-if="dependencyChoices.length > 6" class="dependency-search">
                        <span class="visually-hidden">Find a dependency</span>
                        <input ref="dependencySearchInput" v-model="dependencySearch" type="search" placeholder="Find a dependency" />
                      </label>
                      <div class="dependency-list" role="group" aria-label="Dependencies">
                        <label v-for="item in availableDependencies" :key="item.id" class="dependency-option" :class="{ selected: taskForm.dependency_ids.includes(item.id) }">
                          <input v-model="taskForm.dependency_ids" type="checkbox" :value="item.id" @change="clearFieldError('dependencies')" />
                          <span>{{ item.title }}</span>
                          <small>{{ item.item_type === 'project' ? 'Project' : 'Task' }}</small>
                        </label>
                        <p v-if="availableDependencies.length === 0" class="dependency-message">No matches.</p>
                      </div>
                    </template>
                    <p v-if="fieldErrors.dependencies" class="field-error">{{ fieldErrors.dependencies }}</p>
                  </div>
                </details>

                <details ref="contextDetail" class="form-detail" @toggle="closeOtherDetails">
                  <summary>
                    <span>Description</span>
                    <span class="detail-value">{{ taskForm.description.trim() ? 'Added' : 'Optional' }}</span>
                  </summary>
                  <div class="detail-content">
                    <label class="field">
                      <span class="visually-hidden">Description</span>
                      <textarea v-model="taskForm.description" rows="3" maxlength="5000"></textarea>
                    </label>
                  </div>
                </details>
              </div>
            </div>
            <div class="options-sheet-footer">
              <button type="button" @click="closeOptions">Done</button>
            </div>
          </dialog>
        </section>

      </template>

      <template v-else>
        <header class="projects-heading">
          <button v-if="selectedProject" class="project-back" type="button" @click="selectedProjectId = null">← Projects</button>
          <h1>{{ selectedProject?.title || 'Projects' }}</h1>
          <button v-if="!selectedProject" class="collection-add" type="button" @click="openProjectForm">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
            <span>New project</span>
          </button>
        </header>

        <template v-if="selectedProject">
          <section v-if="selectedProject.description || selectedProject.priority !== 'standard' || selectedProject.deadline_on || selectedProject.dependencies?.length" class="project-overview">
            <p v-if="selectedProject.description" class="task-description">{{ selectedProject.description }}</p>
            <div class="task-meta">
              <span v-if="selectedProject.priority !== 'standard'" :class="selectedProject.priority === 'high' ? 'tag-priority-high' : 'tag-priority-low'">{{ priorityLabel(selectedProject.priority) }} priority</span>
              <span v-if="selectedProject.deadline_on" :class="selectedProject.deadline_is_hard ? 'tag-deadline-hard' : 'tag-deadline-soft'">{{ selectedProject.deadline_is_hard ? 'Hard' : 'Soft' }} deadline · {{ formatDeadline(selectedProject.deadline_on) }}</span>
            </div>
            <p v-if="selectedProject.dependencies?.length" class="task-dependencies">
              {{ selectedProject.dependencies_complete ? 'Dependencies done' : 'Waiting for' }}: {{ selectedProject.dependencies.map((dependency) => dependency.title).join(', ') }}
            </p>
          </section>

          <button class="add-task-shortcut" type="button" @click="openProjectTaskPicker">
            <span class="shortcut-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" /></svg>
            </span>
            <span>Add task to project</span>
            <svg class="shortcut-arrow" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>

          <dialog ref="projectTaskDialog" class="options-sheet project-task-picker" aria-labelledby="project-task-picker-title" @click.self="projectTaskDialog?.close()">
            <header class="options-sheet-header project-task-picker-header">
              <h2 id="project-task-picker-title">Add task to project</h2>
              <button class="project-task-picker-close" type="button" aria-label="Close" @click="projectTaskDialog?.close()">×</button>
            </header>
            <div class="options-sheet-body project-task-picker-body">
              <button class="project-task-create" type="button" :disabled="projectTaskSavingId !== null" @click="createTaskInProject">
                <span>New task</span>
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </button>
              <h3 class="project-task-list-title">Existing tasks</h3>
              <label v-if="eligibleProjectTasks.length > 6 || projectTaskSearch" class="project-task-search">
                <span class="visually-hidden">Search tasks</span>
                <input v-model="projectTaskSearch" type="search" placeholder="Search tasks" />
              </label>
              <p v-if="tasksLoading" class="project-task-empty">Loading tasks…</p>
              <div v-else-if="tasksError" class="project-task-empty" role="alert">
                <p>{{ tasksError }}</p>
                <button type="button" @click="loadTasks">Try again</button>
              </div>
              <p v-else-if="eligibleProjectTasks.length === 0" class="project-task-empty">No unassigned one-time tasks.</p>
              <p v-else-if="filteredProjectTasks.length === 0" class="project-task-empty">No matches.</p>
              <ul v-else class="project-task-choices">
                <li v-for="task in filteredProjectTasks" :key="task.id">
                  <button type="button" :disabled="projectTaskSavingId !== null" @click="addExistingTaskToProject(task)">
                    <span class="project-task-choice-label">
                      <strong>{{ task.title }}</strong>
                      <small>{{ task.type === 'day' ? 'Day' : 'Evening' }}<template v-if="task.is_multi_session"> · Multi-session</template></small>
                    </span>
                    <span class="project-task-choice-action">{{ projectTaskSavingId === task.id ? 'Adding…' : 'Add' }}</span>
                  </button>
                </li>
              </ul>
              <p v-if="projectTaskError" class="form-error" role="alert">{{ projectTaskError }}</p>
            </div>
          </dialog>

          <section class="task-list" aria-labelledby="project-tasks-title">
            <div class="list-heading"><h2 id="project-tasks-title">Tasks</h2></div>
            <p v-if="tasksLoading" class="list-message">Loading tasks…</p>
            <p v-else-if="tasksError" class="list-message">Tasks couldn't be loaded.</p>
            <p v-else-if="projectSteps.length === 0" class="list-message">No tasks in this project yet.</p>
            <ul v-else>
              <li v-for="task in projectSteps" :key="task.id" class="task-card">
                <button class="task-card-cover" type="button" :aria-label="`View ${task.title}`" @click="openTaskDetails(task, 'projects')"></button>
                <div>
                  <h3>{{ task.title }}</h3>
                  <p v-if="task.description" class="task-description">{{ task.description }}</p>
                  <p v-if="task.dependencies?.length" class="task-dependencies">{{ task.dependencies_complete ? 'Dependencies done' : 'Waiting for' }}: {{ task.dependencies.map((dependency) => dependency.title).join(', ') }}</p>
                </div>
                <p class="task-meta">
                  <span :class="task.type === 'day' ? 'tag-day' : 'tag-evening'">{{ task.type === 'day' ? 'Day' : 'Evening' }}</span>
                  <span v-if="task.is_multi_session" class="tag-multi-session">Multi-session</span>
                  <span v-if="task.deadline_on" :class="task.deadline_is_hard ? 'tag-deadline-hard' : 'tag-deadline-soft'">{{ formatDeadline(task.deadline_on) }}</span>
                </p>
              </li>
            </ul>
          </section>
        </template>

        <template v-else>
          <p v-if="projectsLoading" class="list-message">Loading projects…</p>
          <div v-else-if="projectsError" class="list-message" role="alert">
            <p>{{ projectsError }}</p>
            <button type="button" @click="loadProjects">Try again</button>
          </div>
          <p v-else-if="projects.length === 0" class="list-message">No projects saved yet.</p>
          <div v-else class="project-grid">
            <button v-for="project in projects" :key="project.id" class="project-card" type="button" @click="selectedProjectId = project.id">
              <span class="project-card-title">{{ project.title }}</span>
              <span class="project-card-meta">
                {{ tasks.filter((task) => task.project_id === project.id).length }} {{ tasks.filter((task) => task.project_id === project.id).length === 1 ? 'task' : 'tasks' }}
                <span v-if="project.deadline_on"> · Due {{ formatDeadline(project.deadline_on) }}</span>
              </span>
            </button>
          </div>
        </template>
      </template>
    </main>

    <nav class="main-nav" aria-label="Main navigation">
      <button type="button" :aria-pressed="activeView === 'tomorrow'" @click="activeView = 'tomorrow'">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8" />
          <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4m0-14.2-1.4 1.4M6.3 17.7l-1.4 1.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        </svg>
        <span>Tomorrow</span>
      </button>
      <button type="button" :aria-pressed="activeView === 'tasks'" @click="showTasks">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M8 6h12M8 12h12M8 18h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          <path d="m3 6 1.2 1.2L6 5.2M3 12l1.2 1.2L6 11.2M3 18l1.2 1.2L6 17.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span>Tasks</span>
      </button>
      <button type="button" :aria-pressed="activeView === 'projects'" @click="showProjects">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H10l2 2h6.5A2.5 2.5 0 0 1 21 9.5v8A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5v-10Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
          <path d="M3 10h18" stroke="currentColor" stroke-width="1.8" />
        </svg>
        <span>Projects</span>
      </button>
    </nav>
  </div>
</template>
