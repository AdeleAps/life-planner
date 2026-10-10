<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import PlannerDatePicker from './PlannerDatePicker.vue'
import PlannerCalendar from './PlannerCalendar.vue'
import PlanningFocusPanel from './PlanningFocusPanel.vue'
import PlannerSelect from './PlannerSelect.vue'
import { existingReminderSubscription, getReminderConfiguration, registerPlannerWorker, saveSubscription, subscribeToReminders, supportsReminders, unsubscribeFromReminders } from './push.js'

const activeView = ref('tomorrow')
const showResetButton = import.meta.env.DEV
const resetBusy = ref(false)
const resetFeedback = ref('')
const resetFailed = ref(false)
const remindersAvailable = ref(false)
const remindersEnabled = ref(false)
const remindersBusy = ref(false)
const remindersError = ref('')
const reminderPublicKey = ref('')
const entryKind = ref('task')
const serverAvailable = ref(true)
const isCheckingServer = ref(false)
const tasks = ref([])
const taskSearch = ref('')
const taskTypeFilter = ref('all')
const taskSort = ref('newest')
const overdueOnly = ref(false)
const showCompleted = ref(false)
const taskFilterTrigger = ref(null)
const taskFilterDialog = ref(null)
const taskFiltersOpen = ref(false)
const todayDate = ref(localDate(new Date()))
const todayLabel = computed(() => new Intl.DateTimeFormat(undefined, {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
}).format(new Date(`${todayDate.value}T12:00:00`)))
const todayScheduledTasks = ref([])
const todayTasksLoading = ref(false)
const todayTasksError = ref('')
const todayDoneCount = computed(() => todayScheduledTasks.value.filter(isTodayTaskDone).length)
const todayAllDone = computed(() => todayScheduledTasks.value.length > 0 && todayDoneCount.value === todayScheduledTasks.value.length)
const todayTaskGroups = computed(() => [
  { type: 'day', label: 'Day' },
  { type: 'evening', label: 'Evening' },
].map((group) => ({
  ...group,
  items: todayScheduledTasks.value.filter((item) => item.task.type === group.type)
    .sort((first, second) => Number(isTodayTaskDone(first)) - Number(isTodayTaskDone(second))),
})).filter((group) => group.items.length))
const tomorrowDate = computed(() => {
  const date = new Date(`${todayDate.value}T12:00:00`)
  date.setDate(date.getDate() + 1)
  return localDate(date)
})
const tomorrowLabel = computed(() => new Intl.DateTimeFormat(undefined, {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
}).format(new Date(`${tomorrowDate.value}T12:00:00`)))
const tomorrowSetup = reactive({ energy_level: 'standard', day_off: false, dinner_enabled: false, dinner_dish: '' })
const savedTomorrowSetup = ref({ energy_level: 'standard', day_off: false, dinner_enabled: false, dinner_dish: '' })
const tomorrowSetupSaved = ref(false)
const tomorrowSetupDialog = ref(null)
const tomorrowSetupDishInput = ref(null)
const tomorrowSetupLoading = ref(false)
const tomorrowSetupSaving = ref(false)
const tomorrowSetupLoadError = ref('')
const tomorrowSetupError = ref('')
const focusPrompts = ref([])
const focusDraft = reactive({ week: '', month: '' })
const focusPromptError = ref('')
const focusRefreshKey = ref(0)
const pendingReviewTasks = ref([])
const pendingReviewLoading = ref(false)
const pendingReviewError = ref('')
const reviewDoneById = ref({})
const tomorrowReady = computed(() => tomorrowSetupSaved.value && !tomorrowSetupLoading.value && !tomorrowSetupLoadError.value
  && !pendingReviewLoading.value && !pendingReviewError.value && pendingReviewTasks.value.length === 0)
const tomorrowDishError = ref('')
const tomorrowTaskError = ref('')
const tomorrowScheduledTasks = ref([])
const tomorrowManualTaskIds = ref([])
const savedTomorrowManualTaskIds = ref([])
const tomorrowTaskSearch = ref('')
const tomorrowTaskPickerOpen = ref(false)
const captureForTomorrow = ref(false)
const tomorrowDraftBeforeCreate = ref(null)
const tomorrowSetupSummary = computed(() => [
  `${savedTomorrowSetup.value.energy_level[0].toUpperCase()}${savedTomorrowSetup.value.energy_level.slice(1)} energy`,
  savedTomorrowSetup.value.day_off ? 'Day Off' : null,
  savedTomorrowSetup.value.dinner_enabled ? `Dinner${savedTomorrowSetup.value.dinner_dish ? ` · ${savedTomorrowSetup.value.dinner_dish}` : ''}` : null,
  tomorrowScheduledTasks.value.length ? `${tomorrowScheduledTasks.value.length} ${tomorrowScheduledTasks.value.length === 1 ? 'task' : 'tasks'}` : null,
].filter(Boolean).join(' · '))
const tomorrowSelectedTasks = computed(() => tomorrowManualTaskIds.value.map((id) =>
  tasks.value.find((task) => task.id === id) || tomorrowScheduledTasks.value.find((item) => item.task.id === id)?.task,
).filter(Boolean))
const tomorrowTaskChoices = computed(() => {
  const query = tomorrowTaskSearch.value.trim().toLocaleLowerCase()
  const otherScheduledIds = tomorrowScheduledTasks.value.filter((item) => item.source !== 'manual').map((item) => item.task.id)

  return tasks.value.filter((task) => task.lifecycle_state === 'active'
    && !tomorrowManualTaskIds.value.includes(task.id)
    && !otherScheduledIds.includes(task.id)
    && (!query || task.title.toLocaleLowerCase().includes(query) || (task.description || '').toLocaleLowerCase().includes(query)))
})
const projects = ref([])
const selectedProjectId = ref(null)
const projectTaskDialog = ref(null)
const projectTaskSearch = ref('')
const projectTaskSavingId = ref(null)
const projectTaskError = ref('')
const removingTaskId = ref(null)
const removalError = ref('')
const actionDialog = ref(null)
const pendingAction = ref(null)
const actionBusy = ref(false)
const actionError = ref('')
const selectedTaskId = ref(null)
const taskReturnView = ref('tasks')
const taskCompletions = ref([])
const completionCount = ref(0)
const completionsLoading = ref(false)
const completionsError = ref('')
const completionBusy = ref(false)
const completionError = ref('')
const completionRequest = ref(null)
const completionToast = ref(null)
const editingTaskId = ref(null)
const editingTaskRevision = ref(null)
const editingProjectId = ref(null)
const editingProjectRevision = ref(null)
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
const specialInstructionsDetail = ref(null)
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
const dependencyChoices = computed(() => {
  const savedDependencies = editingTaskId.value !== null
    ? (selectedTask.value?.dependencies || [])
    : editingProjectId.value !== null ? (selectedProject.value?.dependencies || []) : []
  const choices = [...tasks.value, ...projects.value, ...savedDependencies]
  return choices.filter((item, index) =>
    choices.findIndex((choice) => choice.id === item.id) === index
    && item.id !== editingTaskId.value
    && item.id !== editingProjectId.value
    && (entryKind.value === 'project' || item.id !== taskForm.project_id))
})
const availableDependencies = computed(() => dependencyChoices.value.filter((item) =>
  item.title.toLocaleLowerCase().includes(dependencySearch.value.trim().toLocaleLowerCase())))
const selectedProject = computed(() => projects.value.find((project) => project.id === selectedProjectId.value))
const selectedTask = computed(() => tasks.value.find((task) => task.id === selectedTaskId.value))
const completedTodayCount = computed(() => taskCompletions.value.filter((completion) =>
  localDate(new Date(completion.completed_at)) === todayDate.value).length)
const projectOptions = computed(() => [
  { value: null, label: 'None' },
  ...projects.value.map((project) => ({ value: project.id, label: project.title })),
])
const projectSteps = computed(() => tasks.value.filter((task) =>
  task.project_id === selectedProjectId.value && task.lifecycle_state === 'active'))
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
  taskSearch.value.trim() || taskTypeFilter.value !== 'all' || taskSort.value !== 'newest' || overdueOnly.value || showCompleted.value,
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
  special_instructions: '',
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
  return Boolean(task.lifecycle_state === 'active' && task.deadline_on && task.deadline_on < todayDate.value)
}

function resetTaskListView() {
  taskSearch.value = ''
  taskTypeFilter.value = 'all'
  taskSort.value = 'newest'
  overdueOnly.value = false
  showCompleted.value = false
}

watch(showCompleted, () => {
  loadTasks()
})

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

async function loadReminderStatus() {
  if (!supportsReminders()) return

  try {
    const configuration = await getReminderConfiguration()
    if (!configuration.available) return

    reminderPublicKey.value = configuration.public_key
    remindersAvailable.value = true
    await registerPlannerWorker()
    const subscription = await existingReminderSubscription()
    remindersEnabled.value = Boolean(subscription)
    if (subscription) await saveSubscription(subscription)
  } catch {
    remindersError.value = 'Could not check reminders.'
  }
}

async function toggleReminders() {
  if (remindersBusy.value) return
  remindersBusy.value = true
  remindersError.value = ''

  try {
    if (remindersEnabled.value) {
      const subscription = await existingReminderSubscription()
      if (subscription) await unsubscribeFromReminders(subscription)
      remindersEnabled.value = false
      return
    }

    const permission = Notification.permission === 'granted'
      ? 'granted'
      : await Notification.requestPermission()

    if (permission !== 'granted') {
      remindersError.value = 'Allow notifications in your iPhone settings.'
      return
    }

    await registerPlannerWorker()
    await subscribeToReminders(reminderPublicKey.value)
    remindersEnabled.value = true
  } catch {
    remindersError.value = 'Could not enable reminders. Open Planner from your Home Screen and try again.'
  } finally {
    remindersBusy.value = false
  }
}

function isTodayTaskDone(item) {
  return item.status === 'completed' || item.task.lifecycle_state === 'completed'
}

function todayLibraryTask(item) {
  return tasks.value.find((task) => task.id === item.task.id)
}

let latestTodayTasksLoad = 0

async function loadTodayTasks() {
  const loadId = ++latestTodayTasksLoad
  const planDate = todayDate.value
  todayTasksLoading.value = true
  todayTasksError.value = ''

  try {
    const response = await fetch(`/api/v1/planning-days/${planDate}/tasks`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
    if (!response.ok) throw new Error('Could not load today')
    const result = await response.json()
    if (loadId === latestTodayTasksLoad && planDate === todayDate.value) todayScheduledTasks.value = result.data
  } catch {
    if (loadId === latestTodayTasksLoad) todayTasksError.value = "Couldn't load today's tasks. Try again."
  } finally {
    if (loadId === latestTodayTasksLoad) todayTasksLoading.value = false
  }
}

function showToday() {
  activeView.value = 'today'
  selectedTaskId.value = null
  loadTodayTasks()
  loadTasks()
  loadProjects()
}

function showTomorrow() {
  activeView.value = 'tomorrow'
  loadTomorrowSetup()
  loadPendingReviewTasks()
}

function reviewDateLabel(date) {
  if (date === todayDate.value) return 'Today'
  return new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
    .format(new Date(`${date}T12:00:00`))
}

let latestPendingReviewLoad = 0

async function loadPendingReviewTasks() {
  const loadId = ++latestPendingReviewLoad
  pendingReviewLoading.value = true
  pendingReviewError.value = ''

  try {
    const response = await fetch('/api/v1/planning-review/pending', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
    if (!response.ok) throw new Error('Could not load task review')
    const result = await response.json()
    if (loadId !== latestPendingReviewLoad) return
    pendingReviewTasks.value = result.data
    reviewDoneById.value = Object.fromEntries(result.data.map((item) => [item.id, true]))
  } catch {
    if (loadId === latestPendingReviewLoad) pendingReviewError.value = "Couldn't load tasks to review. Try again."
  } finally {
    if (loadId === latestPendingReviewLoad) pendingReviewLoading.value = false
  }
}

let latestTomorrowSetupLoad = 0

async function loadTomorrowSetup() {
  const loadId = ++latestTomorrowSetupLoad
  const planDate = tomorrowDate.value
  tomorrowSetupLoading.value = true
  tomorrowSetupLoadError.value = ''
  tomorrowSetupSaved.value = false

  try {
    const response = await fetch(`/api/v1/planning-days/${planDate}/setup`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
    if (!response.ok) throw new Error('Could not load tomorrow setup')
    const { data, scheduled_tasks: scheduledTasks } = await response.json()
    if (loadId !== latestTomorrowSetupLoad || planDate !== tomorrowDate.value) return

    tomorrowScheduledTasks.value = scheduledTasks || []
    savedTomorrowManualTaskIds.value = tomorrowScheduledTasks.value.filter((item) => item.source === 'manual').map((item) => item.task.id)
    tomorrowManualTaskIds.value = [...savedTomorrowManualTaskIds.value]
    savedTomorrowSetup.value = {
      energy_level: data.energy_level,
      day_off: data.day_off,
      dinner_enabled: data.dinner_enabled,
      dinner_dish: data.dinner_dish || '',
    }
    tomorrowSetupSaved.value = data.saved
    Object.assign(tomorrowSetup, savedTomorrowSetup.value)
  } catch {
    if (loadId === latestTomorrowSetupLoad) tomorrowSetupLoadError.value = "Couldn't load tomorrow's setup. Try again."
  } finally {
    if (loadId === latestTomorrowSetupLoad) tomorrowSetupLoading.value = false
  }
}

function openTomorrowSetup() {
  if (!tomorrowSetupDialog.value?.open) tomorrowSetupDialog.value?.showModal()
  tomorrowTaskPickerOpen.value = false
  tomorrowTaskSearch.value = ''
  loadTasks()
  loadTomorrowSetup()
  loadPendingReviewTasks()
  claimFocusPrompts()
}

async function claimFocusPrompts() {
  focusPromptError.value = ''

  try {
    const response = await fetch('/api/v1/focuses/tomorrow-prompts', {
      method: 'POST', headers: { Accept: 'application/json' },
    })
    if (!response.ok) throw new Error('Could not load focus questions.')
    const result = await response.json()
    if (!tomorrowSetupDialog.value?.open) return
    focusPrompts.value = result.prompts || []
    focusDraft.week = ''
    focusDraft.month = ''
  } catch {
    focusPromptError.value = 'Could not load focus questions.'
  }
}

function closeTomorrowSetup() {
  tomorrowSetupDialog.value?.close()
}

function onTomorrowSetupClose() {
  Object.assign(tomorrowSetup, savedTomorrowSetup.value)
  tomorrowManualTaskIds.value = [...savedTomorrowManualTaskIds.value]
  tomorrowTaskPickerOpen.value = false
  tomorrowTaskSearch.value = ''
  tomorrowTaskError.value = ''
  tomorrowDishError.value = ''
  tomorrowSetupError.value = ''
  if (!captureForTomorrow.value) focusPrompts.value = []
  focusPromptError.value = ''
}

function toggleTomorrowDayOff() {
  tomorrowSetup.day_off = !tomorrowSetup.day_off
  if (tomorrowSetup.day_off) {
    tomorrowSetup.dinner_enabled = false
    tomorrowSetup.dinner_dish = ''
    tomorrowDishError.value = ''
  }
}

function toggleTomorrowDinner() {
  tomorrowSetup.dinner_enabled = !tomorrowSetup.dinner_enabled
  if (tomorrowSetup.dinner_enabled) tomorrowSetup.day_off = false
  tomorrowDishError.value = ''
}

function addTaskToTomorrow(task) {
  if (!task.dependencies_complete || tomorrowManualTaskIds.value.includes(task.id)) return
  tomorrowManualTaskIds.value = [...tomorrowManualTaskIds.value, task.id]
  tomorrowTaskSearch.value = ''
  tomorrowTaskError.value = ''
}

function removeTaskFromTomorrow(taskId) {
  tomorrowManualTaskIds.value = tomorrowManualTaskIds.value.filter((id) => id !== taskId)
  tomorrowTaskError.value = ''
}

function createTaskForTomorrow() {
  tomorrowDraftBeforeCreate.value = {
    setup: { ...tomorrowSetup },
    taskIds: [...tomorrowManualTaskIds.value],
  }
  captureForTomorrow.value = true
  closeTomorrowSetup()
  openTaskForm()
}

async function returnToTomorrowSetup(createdTask = null) {
  const draft = tomorrowDraftBeforeCreate.value
  captureForTomorrow.value = false
  tomorrowDraftBeforeCreate.value = null
  activeView.value = 'tomorrow'
  await nextTick()

  if (draft) {
    Object.assign(tomorrowSetup, draft.setup)
    tomorrowManualTaskIds.value = [...draft.taskIds]
  }
  if (createdTask && !createdTask.dependencies_complete) {
    tomorrowTaskError.value = 'Finish dependencies before adding this task.'
  } else if (createdTask && !tomorrowManualTaskIds.value.includes(createdTask.id)) {
    tomorrowManualTaskIds.value.push(createdTask.id)
  }
  tomorrowTaskPickerOpen.value = false
  tomorrowSetupDialog.value?.showModal()
}

async function saveTomorrowSetup() {
  if (tomorrowSetupSaving.value || pendingReviewLoading.value || pendingReviewError.value) return
  tomorrowDishError.value = ''
  tomorrowTaskError.value = ''
  tomorrowSetupError.value = ''

  if (tomorrowSetup.dinner_enabled && !tomorrowSetup.dinner_dish.trim()) {
    tomorrowDishError.value = 'Add the dish for dinner.'
    await nextTick()
    tomorrowSetupDishInput.value?.focus()
    return
  }

  tomorrowSetupSaving.value = true

  try {
    for (const prompt of focusPrompts.value) {
      const response = await fetch(`/api/v1/focuses/${prompt.kind}/${prompt.starts_on}`, {
        method: 'PUT',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ focus: focusDraft[prompt.kind].trim() || null }),
      })
      if (!response.ok) {
        focusPromptError.value = 'Could not save your focus. Try again.'
        return
      }
    }
    if (focusPrompts.value.length) focusRefreshKey.value++
    focusPrompts.value = []

    const response = await fetch(`/api/v1/planning-days/${tomorrowDate.value}/setup`, {
      method: 'PUT',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        energy_level: tomorrowSetup.energy_level,
        day_off: tomorrowSetup.day_off,
        dinner_enabled: tomorrowSetup.dinner_enabled,
        dinner_dish: tomorrowSetup.dinner_enabled ? tomorrowSetup.dinner_dish.trim() : null,
        manual_task_ids: tomorrowManualTaskIds.value,
        task_review: pendingReviewTasks.value.map((item) => ({
          scheduled_task_id: item.id,
          done: reviewDoneById.value[item.id],
        })),
      }),
    })
    const result = await response.json()

    if (!response.ok) {
      if (result.errors?.dinner_dish) {
        tomorrowDishError.value = 'Add the dish for dinner.'
        await nextTick()
        tomorrowSetupDishInput.value?.focus()
        return
      }
      if (result.errors?.manual_task_ids || Object.keys(result.errors || {}).some((key) => key.startsWith('manual_task_ids.'))) {
        tomorrowTaskError.value = result.errors.manual_task_ids?.[0] || 'Check the tasks added for tomorrow.'
        return
      }
      if (result.errors?.task_review || Object.keys(result.errors || {}).some((key) => key.startsWith('task_review.'))) {
        await loadPendingReviewTasks()
        tomorrowSetupError.value = 'Tasks changed. Check the review and save again.'
        return
      }
      throw new Error("Couldn't save tomorrow's setup. Try again.")
    }

    savedTomorrowSetup.value = {
      energy_level: result.data.energy_level,
      day_off: result.data.day_off,
      dinner_enabled: result.data.dinner_enabled,
      dinner_dish: result.data.dinner_dish || '',
    }
    tomorrowScheduledTasks.value = result.scheduled_tasks || []
    savedTomorrowManualTaskIds.value = tomorrowScheduledTasks.value.filter((item) => item.source === 'manual').map((item) => item.task.id)
    tomorrowManualTaskIds.value = [...savedTomorrowManualTaskIds.value]
    tomorrowSetupSaved.value = true
    Object.assign(tomorrowSetup, savedTomorrowSetup.value)
    await Promise.all([loadPendingReviewTasks(), loadTodayTasks(), loadTasks(), loadProjects()])
    closeTomorrowSetup()
  } catch {
    tomorrowSetupError.value = "Couldn't save tomorrow's setup. Try again."
  } finally {
    tomorrowSetupSaving.value = false
  }
}

watch(tomorrowDate, loadTomorrowSetup)
watch(todayDate, () => {
  loadTodayTasks()
  loadPendingReviewTasks()
})

async function resetTestDay() {
  if (resetBusy.value) return
  resetBusy.value = true
  resetFeedback.value = ''
  resetFailed.value = false

  try {
    const response = await fetch('/api/v1/dev/reset', {
      method: 'POST',
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) throw new Error('Reset failed. Try again.')
    const result = await response.json()

    todayScheduledTasks.value = result.scheduled_tasks
    await Promise.all([loadTomorrowSetup(), loadPendingReviewTasks(), loadTasks()])
    resetFeedback.value = `Tomorrow cleared. ${result.scheduled_tasks.length} tasks set for today.`
  } catch {
    resetFailed.value = true
    resetFeedback.value = 'Reset failed. Try again.'
  } finally {
    resetBusy.value = false
  }
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

function formatCompletionDate(dateString) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(dateString))
}

function completionLabel(type) {
  if (type === 'session') return 'Session finished'
  if (type === 'final') return 'Task completed'
  return 'Finished once'
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
  if (entryKind.value === 'task' && taskForm.special_instructions.trim()) changes.push('Special instructions')
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

let latestTaskLoad = 0

async function loadTasks() {
  const loadId = ++latestTaskLoad
  tasksLoading.value = true
  tasksError.value = ''

  try {
    const url = showCompleted.value ? '/api/v1/tasks?include_completed=1' : '/api/v1/tasks'
    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })

    if (!response.ok) {
      throw new Error('Could not load tasks')
    }

    const result = await response.json()
    if (loadId === latestTaskLoad) tasks.value = result.data
  } catch {
    if (loadId === latestTaskLoad) tasksError.value = "Tasks couldn't be loaded. Try again."
  } finally {
    if (loadId === latestTaskLoad) tasksLoading.value = false
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

let latestCompletionLoad = 0
let completionToastTimeout

function celebrateCompletion(type, projectCompleted = false) {
  window.clearTimeout(completionToastTimeout)
  completionToast.value = {
    id: crypto.randomUUID(),
    message: projectCompleted ? 'Project complete. Nice work!' : type === 'final' ? 'Task complete. Nice work!' : type === 'session' ? 'Session finished. Nice work!' : 'One more done. Nice work!',
  }
  completionToastTimeout = window.setTimeout(() => { completionToast.value = null }, 2800)
}

async function loadTaskCompletions(taskId) {
  const loadId = ++latestCompletionLoad
  completionsLoading.value = true
  completionsError.value = ''

  try {
    const response = await fetch(`/api/v1/tasks/${taskId}/completions`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
    if (!response.ok) throw new Error('Could not load completion history')

    const result = await response.json()
    if (loadId === latestCompletionLoad && selectedTaskId.value === taskId) {
      taskCompletions.value = result.data
      completionCount.value = result.total
    }
  } catch {
    if (loadId === latestCompletionLoad && selectedTaskId.value === taskId) {
      completionsError.value = "Completion history couldn't be loaded."
    }
  } finally {
    if (loadId === latestCompletionLoad) completionsLoading.value = false
  }
}

function openTaskDetails(task, returnView = 'tasks') {
  selectedTaskId.value = task.id
  taskReturnView.value = returnView
  activeView.value = 'tasks'
  taskCompletions.value = []
  completionCount.value = 0
  completionError.value = ''
  completionRequest.value = null
  loadTaskCompletions(task.id)
}

function closeTaskDetails() {
  ++latestCompletionLoad
  selectedTaskId.value = null
  activeView.value = taskReturnView.value
}

function openQuickCompletion(task) {
  if (task.lifecycle_state !== 'active' || completionBusy.value || actionBusy.value) return
  pendingAction.value = { kind: 'quick-completion', task }
  actionError.value = ''
  actionDialog.value?.showModal()
}

async function requestTaskCompletion(type, task = selectedTask.value) {
  if (!task || task.lifecycle_state !== 'active' || completionBusy.value || actionBusy.value) return

  const fromDetails = selectedTaskId.value === task.id
  if (fromDetails && (completionsLoading.value || completionsError.value)) return

  refreshToday()
  let todayCount = fromDetails ? completedTodayCount.value : 0

  if (!fromDetails && (task.recurrence_type !== 'one_time' || task.is_multi_session)) {
    actionBusy.value = true
    actionError.value = ''
    try {
      const response = await fetch(`/api/v1/tasks/${task.id}/completions`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      })
      if (!response.ok) throw new Error('Could not check completion history')
      const result = await response.json()
      todayCount = result.data.filter((completion) => localDate(new Date(completion.completed_at)) === todayDate.value).length
    } catch {
      actionError.value = "Couldn't check whether this task was finished today. Try again."
      return
    } finally {
      actionBusy.value = false
    }
  }

  if ((task.recurrence_type !== 'one_time' || task.is_multi_session) && todayCount > 0) {
    pendingAction.value = { kind: 'repeat-completion', task, completionType: type, todayCount }
    actionError.value = ''
    if (!actionDialog.value?.open) actionDialog.value?.showModal()
    return
  }

  submitTaskCompletion(type, task)
}

async function submitTaskCompletion(type, task = selectedTask.value) {
  if (!task || completionBusy.value) return

  const fromDetails = selectedTaskId.value === task.id
  const fromActionDialog = pendingAction.value?.kind === 'quick-completion' || pendingAction.value?.kind === 'repeat-completion'
  completionBusy.value = true
  actionBusy.value = fromActionDialog
  completionError.value = ''
  actionError.value = ''

  if (completionRequest.value?.taskId !== task.id || completionRequest.value?.type !== type
    || completionRequest.value?.revision !== task.revision) {
    completionRequest.value = { taskId: task.id, type, revision: task.revision, id: crypto.randomUUID() }
  }

  try {
    const response = await fetch(`/api/v1/tasks/${task.id}/completions`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        client_request_id: completionRequest.value.id,
        revision: completionRequest.value.revision,
        completion_type: type,
      }),
    })
    const result = await response.json()

    if (!response.ok) {
      if (response.status === 409) {
        completionRequest.value = null
        await loadTasks()
        if (selectedTaskId.value === task.id) await loadTaskCompletions(task.id)
        throw new Error('This task changed. Reopen it to get the latest version.')
      }
      throw new Error(result.errors?.completion_type?.[0] || "Couldn't save this completion. Try again.")
    }

    if (fromDetails) {
      taskCompletions.value = [result.completion, ...taskCompletions.value.filter((item) => item.id !== result.completion.id)]
      completionCount.value = result.completion_count
    }
    tasks.value = tasks.value.map((item) => item.id === task.id ? result.data : item)
    await loadTodayTasks()
    await loadPendingReviewTasks()
    completionRequest.value = null
    actionDialog.value?.close()
    pendingAction.value = null
    celebrateCompletion(type, result.project_completed)

    if (type === 'final') {
      if (fromDetails) {
        selectedTaskId.value = null
        activeView.value = taskReturnView.value
      }
      await loadTasks()
      if (result.data.project_id !== null) await loadProjects()
      if (result.project_completed) selectedProjectId.value = null
    }
  } catch (error) {
    if (fromActionDialog) actionError.value = error.message
    else completionError.value = error.message
  } finally {
    completionBusy.value = false
    actionBusy.value = false
  }
}

function showProjects() {
  activeView.value = 'projects'
  selectedProjectId.value = null
  loadProjects()
  loadTasks()
}

async function openProjectForm() {
  resetCaptureForm()
  editingTaskId.value = null
  editingTaskRevision.value = null
  editingProjectId.value = null
  editingProjectRevision.value = null
  clientRequestIds.project = crypto.randomUUID()
  setEntryKind('project')
  activeView.value = 'capture'
  loadTasks()
  loadProjects()
  await nextTick()
  taskTitleInput.value?.focus()
}

async function openProjectEdit(project) {
  resetCaptureForm()
  editingTaskId.value = null
  editingTaskRevision.value = null
  editingProjectId.value = project.id
  editingProjectRevision.value = project.revision
  entryKind.value = 'project'
  taskForm.title = project.title
  taskForm.description = project.description || ''
  taskForm.priority = project.priority || 'standard'
  taskForm.dependency_ids = (project.dependencies || []).map((dependency) => dependency.id)

  if (project.deadline_on) {
    deadlineForm.mode = 'date'
    deadlineForm.date = project.deadline_on
    deadlineForm.is_hard = Boolean(project.deadline_is_hard)
  }

  activeView.value = 'capture'
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
  editingTaskId.value = null
  editingTaskRevision.value = null
  editingProjectId.value = null
  editingProjectRevision.value = null
  clientRequestIds.task = crypto.randomUUID()
  setEntryKind('task')
  taskForm.project_id = projectId
  activeView.value = 'capture'
  loadTasks()
  loadProjects()
  await nextTick()
  taskTitleInput.value?.focus()
}

async function openTaskEdit(task) {
  resetCaptureForm()
  editingTaskId.value = task.id
  editingTaskRevision.value = task.revision
  editingProjectId.value = null
  editingProjectRevision.value = null
  entryKind.value = 'task'
  taskForm.type = task.type
  taskForm.recurrence_type = task.recurrence_type
  await nextTick()

  taskForm.title = task.title
  taskForm.description = task.description || ''
  taskForm.special_instructions = task.special_instructions || ''
  taskForm.energy_cost = task.energy_cost
  taskForm.priority = task.priority
  taskForm.is_multi_session = Boolean(task.is_multi_session)
  taskForm.advance_notice_days = task.advance_notice_days
  taskForm.day_preference = task.day_preference
  taskForm.day_preference_is_hard = Boolean(task.day_preference_is_hard)
  taskForm.project_id = task.project_id
  taskForm.dependency_ids = (task.dependencies || []).map((dependency) => dependency.id)

  if (task.recurrence_type === 'schedule' && task.recurrence_rule) {
    repeatForm.unit = task.recurrence_rule.unit
    repeatForm.interval = task.recurrence_rule.interval
    repeatForm.weekdays = [...(task.recurrence_rule.weekdays || [])]
    repeatForm.day_of_month = task.recurrence_rule.day_of_month || repeatForm.day_of_month
  } else if (task.recurrence_type === 'count' && task.recurrence_rule) {
    repeatForm.period_unit = task.recurrence_rule.period_unit
    repeatForm.period_interval = task.recurrence_rule.period_interval
    repeatForm.min_occurrences = task.recurrence_rule.min_occurrences
    repeatForm.max_occurrences = task.recurrence_rule.max_occurrences
    repeatForm.count_mode = task.recurrence_rule.min_occurrences === task.recurrence_rule.max_occurrences ? 'exact' : 'range'
  }

  if (task.deadline_on) {
    deadlineForm.mode = 'date'
    deadlineForm.date = task.deadline_on
    deadlineForm.is_hard = Boolean(task.deadline_is_hard)
  }

  activeView.value = 'capture'
  await nextTick()
  taskTitleInput.value?.focus()
}

async function cancelCapture() {
  if (captureForTomorrow.value) {
    resetCaptureForm()
    await returnToTomorrowSetup()
    return
  }

  if (editingProjectId.value !== null) {
    editingProjectId.value = null
    editingProjectRevision.value = null
    resetCaptureForm()
    activeView.value = 'projects'
    return
  }

  if (editingTaskId.value !== null) {
    editingTaskId.value = null
    editingTaskRevision.value = null
    resetCaptureForm()
    activeView.value = 'tasks'
    return
  }

  if (entryKind.value === 'project') showProjects()
  else showTasks()
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

function openTaskDelete(task) {
  pendingAction.value = { kind: 'delete-task', task }
  actionError.value = ''
  actionDialog.value?.showModal()
}

async function openProjectDelete(project) {
  await loadProjects()
  const currentProject = projects.value.find((item) => item.id === project.id) || project
  pendingAction.value = { kind: currentProject.steps_count > 0 ? 'delete-project' : 'delete-empty-project', project: currentProject }
  actionError.value = ''
  actionDialog.value?.showModal()
}

function closeActionDialog() {
  if (actionBusy.value) return
  actionDialog.value?.close()
  pendingAction.value = null
  actionError.value = ''
}

async function offerEmptyProjectDeletion(projectId) {
  const project = projects.value.find((item) => item.id === projectId)
  if (!project) return

  await nextTick()
  pendingAction.value = { kind: 'empty-project', project }
  actionError.value = ''
  actionDialog.value?.showModal()
}

async function removeTaskFromProject(task) {
  if (removingTaskId.value !== null) return
  removingTaskId.value = task.id
  removalError.value = ''

  try {
    const response = await fetch(`/api/v1/tasks/${task.id}/project`, {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) throw new Error("Couldn't remove this task from its project.")
    const result = await response.json()

    tasks.value = tasks.value.map((item) => item.id === task.id ? result.data : item)
    if (selectedTaskId.value === task.id && taskReturnView.value === 'projects') {
      selectedTaskId.value = null
      activeView.value = 'projects'
    }
    await loadProjects()
    if (result.project_empty) await offerEmptyProjectDeletion(task.project_id)
  } catch (error) {
    removalError.value = error.message || "Couldn't remove this task from its project."
  } finally {
    removingTaskId.value = null
  }
}

async function deleteTask() {
  const task = pendingAction.value?.task
  if (!task || actionBusy.value) return
  actionBusy.value = true
  actionError.value = ''

  try {
    const response = await fetch(`/api/v1/tasks/${task.id}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) throw new Error("Couldn't delete this task. Try again.")
    const result = await response.json()

    tasks.value = tasks.value.filter((item) => item.id !== task.id)
    if (selectedTaskId.value === task.id) {
      selectedTaskId.value = null
      activeView.value = taskReturnView.value
    }
    await Promise.all([loadProjects(), loadTomorrowSetup(), loadTodayTasks()])
    actionDialog.value?.close()
    pendingAction.value = null
    if (result.project_empty) await offerEmptyProjectDeletion(result.project_id)
  } catch (error) {
    actionError.value = error.message || "Couldn't delete this task. Try again."
  } finally {
    actionBusy.value = false
  }
}

async function deleteProject(taskAction) {
  const project = pendingAction.value?.project
  if (!project || actionBusy.value) return
  actionBusy.value = true
  actionError.value = ''

  try {
    const response = await fetch(`/api/v1/projects/${project.id}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ task_action: taskAction }),
    })
    if (!response.ok) throw new Error("Couldn't delete this project. Try again.")

    if (selectedProjectId.value === project.id) selectedProjectId.value = null
    if (selectedTask.value?.project_id === project.id) selectedTaskId.value = null
    projects.value = projects.value.filter((item) => item.id !== project.id)
    if (taskAction === 'delete') {
      tasks.value = tasks.value.filter((task) => task.project_id !== project.id)
    } else {
      tasks.value = tasks.value.map((task) => task.project_id === project.id ? { ...task, project_id: null, revision: task.revision + 1 } : task)
    }
    actionDialog.value?.close()
    pendingAction.value = null
    await Promise.all([loadTasks(), loadTomorrowSetup(), loadTodayTasks()])
  } catch (error) {
    actionError.value = error.message || "Couldn't delete this project. Try again."
  } finally {
    actionBusy.value = false
  }
}

function closeOtherDetails(event) {
  if (!event.target.open) return

  for (const detail of [energyDetail.value, priorityDetail.value, repeatDetail.value, multiSessionDetail.value, advanceNoticeDetail.value, dayPreferenceDetail.value, deadlineDetail.value, projectDetail.value, dependenciesDetail.value, contextDetail.value, specialInstructionsDetail.value]) {
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
  for (const detail of [energyDetail.value, priorityDetail.value, repeatDetail.value, multiSessionDetail.value, advanceNoticeDetail.value, dayPreferenceDetail.value, deadlineDetail.value, projectDetail.value, dependenciesDetail.value, contextDetail.value, specialInstructionsDetail.value]) {
    if (detail) detail.open = false
  }
}

function resetCaptureForm() {
  taskForm.title = ''
  taskForm.description = ''
  taskForm.special_instructions = ''
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
    const createdForTomorrow = captureForTomorrow.value && !isProject && editingTaskId.value === null
    const taskInput = {
      title: taskForm.title.trim(),
      description: taskForm.description.trim() || null,
      dependency_ids: taskForm.dependency_ids.length ? [...taskForm.dependency_ids] : null,
    }
    if (editingTaskId.value !== null) taskInput.revision = editingTaskRevision.value
    else if (editingProjectId.value !== null) taskInput.revision = editingProjectRevision.value
    else taskInput.client_request_id = clientRequestIds[entryKind.value]

    if (isProject) {
      taskInput.priority = taskForm.priority
      if (deadlineForm.mode !== 'none') {
        taskInput.deadline_on = deadlineOn()
        taskInput.deadline_is_hard = deadlineForm.is_hard
      }
    } else {
      taskInput.special_instructions = taskForm.special_instructions.trim() || null
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

    const saveUrl = isProject
      ? (editingProjectId.value !== null ? `/api/v1/projects/${editingProjectId.value}` : '/api/v1/projects')
      : (editingTaskId.value !== null ? `/api/v1/tasks/${editingTaskId.value}` : '/api/v1/tasks')
    const response = await fetch(saveUrl, {
      method: editingTaskId.value !== null || editingProjectId.value !== null ? 'PUT' : 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(taskInput),
    })
    const result = await response.json()

    if (!response.ok) {
      if (response.status === 409) {
        if (isProject) await loadProjects()
        else await loadTasks()
        throw new Error(`This ${isProject ? 'project' : 'task'} changed since you opened it. Reopen it to edit the latest version.`)
      }
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
      if (createdForTomorrow) {
        selectedTaskId.value = null
      } else if (editingTaskId.value !== null) {
        selectedTaskId.value = result.data.id
        activeView.value = 'tasks'
      } else if (savedProjectId !== null) {
        selectedProjectId.value = savedProjectId
        activeView.value = 'projects'
      } else {
        selectedTaskId.value = null
        activeView.value = 'tasks'
      }
    }
    editingTaskId.value = null
    editingTaskRevision.value = null
    editingProjectId.value = null
    editingProjectRevision.value = null
    resetCaptureForm()
    clientRequestIds[entryKind.value] = crypto.randomUUID()
    if (createdForTomorrow) await returnToTomorrowSetup(result.data)
  } catch (error) {
    saveError.value = error.message || `Couldn't save this ${entryKind.value}. Try again.`
  } finally {
    isSaving.value = false
  }
}

let todayRefreshInterval
onMounted(() => {
  checkServer()
  fetch('/api/v1/calendar/bootstrap', { headers: { Accept: 'application/json' }, cache: 'no-store' }).catch(() => {})
  refreshToday()
  loadReminderStatus()

  if (new URLSearchParams(window.location.search).get('setup') === 'tomorrow') {
    window.history.replaceState(null, '', window.location.pathname)
    openTomorrowSetup()
  } else {
    loadTomorrowSetup()
    loadPendingReviewTasks()
  }

  document.addEventListener('visibilitychange', refreshToday)
  todayRefreshInterval = window.setInterval(refreshToday, 60_000)
})
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', refreshToday)
  window.clearInterval(todayRefreshInterval)
  window.clearTimeout(completionToastTimeout)
})
</script>

<template>
  <div class="app-shell">
    <header class="app-header">
      <button class="brand-lockup" type="button" aria-label="Planner home" @click="showTomorrow">
        <img class="brand-flower" src="/sunflower.svg?v=2" alt="" />
        <span class="wordmark">Planner</span>
      </button>
      <button v-if="showResetButton && activeView === 'tomorrow'" class="test-reset-button" type="button"
        :disabled="resetBusy" @click="resetTestDay">{{ resetBusy ? 'Resetting…' : 'Reset' }}</button>
    </header>

    <main class="main-content">
      <p v-if="activeView === 'tomorrow' && resetFeedback" class="reset-feedback" :class="{ 'is-error': resetFailed }" role="status">{{ resetFeedback }}</p>
      <div v-if="!serverAvailable" class="connection-notice" role="alert">
        <p>Planner can't reach its server right now.</p>
        <button type="button" :disabled="isCheckingServer" @click="checkServer">
          {{ isCheckingServer ? 'Checking…' : 'Try again' }}
        </button>
      </div>

      <template v-if="activeView === 'tomorrow'">
        <PlanningFocusPanel :date="tomorrowDate" :refresh-key="focusRefreshKey" />

        <section class="hero hero-tomorrow" :class="{ 'has-setup': tomorrowReady }" aria-labelledby="tomorrow-title">
          <div class="hero-content">
            <p class="hero-kicker">Your day ahead</p>
            <h1 id="tomorrow-title">Tomorrow<span class="title-dot">.</span></h1>
            <p class="hero-date">{{ tomorrowLabel }}</p>
            <button class="hero-setup-button" :class="{ 'is-saved': tomorrowReady }"
              type="button" @click="openTomorrowSetup">
              <span class="hero-setup-symbol" aria-hidden="true">
                <svg v-if="tomorrowReady" viewBox="0 0 24 24" fill="none">
                  <path d="m5 12 4.5 4.5L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <svg v-else viewBox="0 0 24 24" fill="none">
                  <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                </svg>
              </span>
              <span class="hero-setup-copy">
                <strong>{{ tomorrowSetupLoading || pendingReviewLoading ? 'Loading setup…' : tomorrowSetupLoadError || pendingReviewError ? 'Setup unavailable' : pendingReviewTasks.length ? 'Review tasks' : tomorrowSetupSaved ? 'You’re set' : 'Set up tomorrow' }}</strong>
                <small>{{ tomorrowSetupLoading || pendingReviewLoading ? 'One moment' : tomorrowSetupLoadError || pendingReviewError ? 'Tap to retry' : pendingReviewTasks.length ? 'Finish review to plan tomorrow' : tomorrowSetupSaved ? tomorrowSetupSummary : 'Time to plan!' }}</small>
              </span>
              <svg class="hero-setup-arrow" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>
          </div>
          <img class="hero-flower" src="/sunflower.svg?v=2" alt="" aria-hidden="true" />
        </section>

        <div v-if="remindersAvailable" class="reminder-control">
          <span>20:00 reminder <strong v-if="remindersEnabled">On</strong></span>
          <button type="button" :disabled="remindersBusy" @click="toggleReminders">
            {{ remindersBusy ? 'Working…' : remindersEnabled ? 'Turn off' : 'Enable' }}
          </button>
          <p v-if="remindersError" role="alert">{{ remindersError }}</p>
        </div>

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

        <dialog ref="tomorrowSetupDialog" class="options-sheet tomorrow-setup-sheet" aria-labelledby="tomorrow-setup-title"
          @close="onTomorrowSetupClose" @click.self="closeTomorrowSetup">
          <header class="options-sheet-header tomorrow-setup-header">
            <div>
              <h2 id="tomorrow-setup-title">Set up tomorrow</h2>
              <p>{{ tomorrowLabel }}</p>
            </div>
            <button type="button" aria-label="Close setup" @click="closeTomorrowSetup">×</button>
          </header>
          <form class="tomorrow-setup-form" @submit.prevent="saveTomorrowSetup">
            <div class="options-sheet-body tomorrow-setup-body">
              <p v-if="tomorrowSetupLoading" class="list-message">Loading…</p>
              <div v-else-if="tomorrowSetupLoadError" class="tomorrow-setup-load-error" role="alert">
                <p>{{ tomorrowSetupLoadError }}</p>
                <button type="button" @click="loadTomorrowSetup">Try again</button>
              </div>
              <template v-else>
                <section v-if="pendingReviewLoading || pendingReviewError || pendingReviewTasks.length" class="tomorrow-review" aria-labelledby="tomorrow-review-title">
                  <div class="tomorrow-tasks-heading">
                    <h3 id="tomorrow-review-title">Review tasks</h3>
                    <span v-if="!pendingReviewLoading && !pendingReviewError">{{ pendingReviewTasks.length }}</span>
                  </div>
                  <p v-if="pendingReviewLoading" class="list-message">Loading…</p>
                  <div v-else-if="pendingReviewError" class="tomorrow-setup-load-error" role="alert">
                    <p>{{ pendingReviewError }}</p>
                    <button type="button" @click="loadPendingReviewTasks">Try again</button>
                  </div>
                  <template v-else>
                    <ul class="tomorrow-review-list">
                      <li v-for="item in pendingReviewTasks" :key="item.id">
                        <div class="tomorrow-review-task">
                          <small>{{ reviewDateLabel(item.plan_date) }}</small>
                          <strong>{{ item.task.title }}</strong>
                        </div>
                        <div class="tomorrow-review-choice" role="group" :aria-label="`Review ${item.task.title}`">
                          <button type="button" :class="{ selected: reviewDoneById[item.id] }" :aria-pressed="reviewDoneById[item.id]"
                            @click="reviewDoneById[item.id] = true">Done</button>
                          <button type="button" :class="{ selected: !reviewDoneById[item.id] }" :aria-pressed="!reviewDoneById[item.id]"
                            @click="reviewDoneById[item.id] = false">Not done</button>
                        </div>
                      </li>
                    </ul>
                  </template>
                </section>
                <section v-if="focusPrompts.length || focusPromptError" class="tomorrow-focus-prompts" aria-label="Upcoming focus">
                  <label v-for="prompt in focusPrompts" :key="prompt.kind" class="tomorrow-focus-field">
                    <span>{{ prompt.kind === 'week' ? 'Next week’s focus' : 'Next month’s focus' }} <small>optional</small></span>
                    <textarea v-model="focusDraft[prompt.kind]" rows="2" maxlength="500"></textarea>
                  </label>
                  <p v-if="focusPromptError" class="form-error" role="alert">{{ focusPromptError }}</p>
                </section>
                <fieldset class="tomorrow-energy">
                  <legend>Energy</legend>
                  <div class="choice-grid tomorrow-energy-options" role="radiogroup" aria-label="Energy for tomorrow">
                    <label v-for="level in ['low', 'standard']" :key="level" class="choice-option tomorrow-energy-option"
                      :class="{ selected: tomorrowSetup.energy_level === level }">
                      <input v-model="tomorrowSetup.energy_level" type="radio" name="tomorrow-energy" :value="level" />
                      <span>{{ level[0].toUpperCase() + level.slice(1) }}</span>
                    </label>
                  </div>
                </fieldset>

                <div class="tomorrow-setup-setting">
                  <button class="task-completed-toggle" type="button" role="switch" :aria-checked="tomorrowSetup.day_off"
                    @click="toggleTomorrowDayOff">
                    <span>Day Off</span>
                    <span class="task-toggle-track" aria-hidden="true"><span class="task-toggle-thumb"></span></span>
                  </button>
                  <p>Planner adds no tasks automatically.</p>
                </div>

                <div class="tomorrow-setup-setting">
                  <button class="task-completed-toggle" type="button" role="switch" :aria-checked="tomorrowSetup.dinner_enabled"
                    @click="toggleTomorrowDinner">
                    <span>Dinner</span>
                    <span class="task-toggle-track" aria-hidden="true"><span class="task-toggle-thumb"></span></span>
                  </button>
                  <label v-if="tomorrowSetup.dinner_enabled" class="field tomorrow-dish-field" :class="{ 'has-error': tomorrowDishError }">
                    <span>Dish</span>
                    <input ref="tomorrowSetupDishInput" v-model="tomorrowSetup.dinner_dish" type="text" maxlength="255"
                      :aria-invalid="Boolean(tomorrowDishError)" :aria-describedby="tomorrowDishError ? 'tomorrow-dish-error' : undefined"
                      @input="tomorrowDishError = ''" />
                    <span v-if="tomorrowDishError" id="tomorrow-dish-error" class="field-error" role="alert">{{ tomorrowDishError }}</span>
                  </label>
                </div>

                <section class="tomorrow-tasks-setting" aria-labelledby="tomorrow-tasks-title">
                  <div class="tomorrow-tasks-heading">
                    <h3 id="tomorrow-tasks-title">Add Tasks</h3>
                    <span>{{ tomorrowManualTaskIds.length + (tomorrowSetup.dinner_enabled ? 2 : 0) || 'None' }}</span>
                  </div>
                  <ul v-if="tomorrowSetup.dinner_enabled || tomorrowSelectedTasks.length" class="tomorrow-selected-tasks">
                    <template v-if="tomorrowSetup.dinner_enabled">
                      <li class="tomorrow-auto-task"><span>Grocery shopping</span><small>Day</small></li>
                      <li class="tomorrow-auto-task"><span>Dinner</span><small>Evening</small></li>
                    </template>
                    <li v-for="task in tomorrowSelectedTasks" :key="task.id">
                      <span>{{ task.title }}</span>
                      <button type="button" :aria-label="`Remove ${task.title} from tomorrow`" @click="removeTaskFromTomorrow(task.id)">×</button>
                    </li>
                  </ul>
                  <button class="tomorrow-add-task" type="button" :aria-expanded="tomorrowTaskPickerOpen" @click="tomorrowTaskPickerOpen = !tomorrowTaskPickerOpen">
                    <span>{{ tomorrowTaskPickerOpen ? 'Close task list' : 'Add task' }}</span>
                    <span aria-hidden="true">{{ tomorrowTaskPickerOpen ? '−' : '+' }}</span>
                  </button>
                  <div v-if="tomorrowTaskPickerOpen" class="tomorrow-task-picker">
                    <button class="project-task-create" type="button" @click="createTaskForTomorrow">
                      <span>New task</span>
                      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </button>
                    <label v-if="tasks.length > 5 || tomorrowTaskSearch" class="project-task-search">
                      <span class="visually-hidden">Search tasks</span>
                      <input v-model="tomorrowTaskSearch" type="search" placeholder="Search tasks" />
                    </label>
                    <p v-if="tasksLoading" class="project-task-empty">Loading tasks…</p>
                    <div v-else-if="tasksError" class="project-task-empty" role="alert">
                      <p>{{ tasksError }}</p>
                      <button type="button" @click="loadTasks">Try again</button>
                    </div>
                    <p v-else-if="tomorrowTaskChoices.length === 0" class="project-task-empty">No tasks to add.</p>
                    <ul v-else class="project-task-choices">
                      <li v-for="task in tomorrowTaskChoices" :key="task.id">
                        <button type="button" :disabled="!task.dependencies_complete" @click="addTaskToTomorrow(task)">
                          <span class="project-task-choice-label">
                            <strong>{{ task.title }}</strong>
                            <small>{{ task.type === 'day' ? 'Day' : 'Evening' }}{{ task.dependencies_complete ? '' : ' · Dependencies first' }}</small>
                          </span>
                          <span class="project-task-choice-action">Add</span>
                        </button>
                      </li>
                    </ul>
                  </div>
                  <p v-if="tomorrowTaskError" class="form-error" role="alert">{{ tomorrowTaskError }}</p>
                </section>
                <p v-if="tomorrowSetupError" class="form-error" role="alert">{{ tomorrowSetupError }}</p>
              </template>
            </div>
            <footer v-if="!tomorrowSetupLoadError" class="options-sheet-footer">
              <button type="submit" :disabled="tomorrowSetupLoading || tomorrowSetupSaving || pendingReviewLoading || Boolean(pendingReviewError)">{{ tomorrowSetupSaving ? 'Saving…' : 'Save setup' }}</button>
            </footer>
          </form>
        </dialog>

        <section v-if="tomorrowScheduledTasks.length" class="tomorrow-planned" aria-labelledby="tomorrow-planned-title">
          <div class="tomorrow-planned-heading">
            <h2 id="tomorrow-planned-title">Tomorrow’s tasks</h2>
            <span>{{ tomorrowScheduledTasks.length }}</span>
          </div>
          <ul>
            <li v-for="item in tomorrowScheduledTasks" :key="item.id">
              <strong>{{ item.task.title }}</strong>
              <span>{{ item.task.type === 'day' ? 'Day' : 'Evening' }}</span>
            </li>
          </ul>
        </section>
        <section v-else class="empty-plan" aria-labelledby="empty-plan-title">
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

      <template v-else-if="activeView === 'today'">
        <header class="today-heading" :class="{ 'is-complete': todayAllDone && !todayTasksLoading && !todayTasksError }">
          <img v-if="todayAllDone && !todayTasksLoading && !todayTasksError" class="today-finished-flower" src="/sunflower.svg?v=2" alt="" aria-hidden="true" />
          <p class="section-kicker">{{ todayLabel }}</p>
          <h1>Today<span class="title-dot">.</span></h1>
          <p v-if="!todayTasksLoading && !todayTasksError && todayScheduledTasks.length" class="today-progress-copy">
            {{ todayAllDone ? 'All done. Enjoy the rest of your day.' : `${todayDoneCount} of ${todayScheduledTasks.length} done` }}
          </p>
        </header>

        <div v-if="todayTasksLoading" class="today-state">Loading today’s tasks…</div>
        <div v-else-if="todayTasksError" class="today-state" role="alert">
          <p>{{ todayTasksError }}</p>
          <button type="button" @click="loadTodayTasks">Try again</button>
        </div>
        <div v-else-if="!todayScheduledTasks.length" class="today-state">Nothing planned for today.</div>
        <template v-else>
          <div class="today-progress-track" role="progressbar" :aria-valuenow="todayDoneCount" :aria-valuemax="todayScheduledTasks.length" aria-valuemin="0" aria-label="Today's tasks done">
            <span :style="{ width: `${todayDoneCount / todayScheduledTasks.length * 100}%` }"></span>
          </div>
          <section v-for="group in todayTaskGroups" :key="group.type" class="today-group" :aria-label="`${group.label} tasks`">
            <div class="today-group-heading"><h2>{{ group.label }}</h2><span>{{ group.items.length }}</span></div>
            <ul class="today-task-list">
              <li v-for="item in group.items" :key="item.id" :class="{ 'is-done': isTodayTaskDone(item) }">
                <div class="today-task-copy">
                  <button v-if="todayLibraryTask(item)" type="button" @click="openTaskDetails(todayLibraryTask(item), 'today')">{{ item.task.title }}</button>
                  <strong v-else>{{ item.task.title }}</strong>
                  <span v-if="isTodayTaskDone(item)" class="today-done-label">Done</span>
                </div>
                <button v-if="item.task.lifecycle_state === 'active'" class="today-finish-button" :class="{ 'is-repeat': isTodayTaskDone(item) }"
                  type="button" :disabled="completionBusy || actionBusy"
                  :aria-label="`${isTodayTaskDone(item) ? 'Finish again' : 'Finish'}: ${item.task.title}`"
                  @click="openQuickCompletion(todayLibraryTask(item) || item.task)">
                  <svg v-if="isTodayTaskDone(item)" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                  <svg v-else viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m4.5 12 5 5L19.5 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
              </li>
            </ul>
          </section>
        </template>
      </template>

      <template v-else-if="activeView === 'tasks'">
        <template v-if="selectedTask">
          <header class="task-detail-heading">
            <div class="task-detail-actions">
              <button class="project-back" type="button" @click="closeTaskDetails">← {{ taskReturnView === 'projects' ? 'Project' : taskReturnView === 'today' ? 'Today' : 'Tasks' }}</button>
              <button v-if="selectedTask.lifecycle_state === 'active'" class="task-edit-button" type="button" @click="openTaskEdit(selectedTask)">Edit</button>
            </div>
            <div class="task-detail-title">
              <h1>{{ selectedTask.title }}</h1>
              <span v-if="selectedTask.lifecycle_state === 'completed'" class="task-completed-badge">Completed</span>
            </div>
          </header>
          <section class="task-detail" aria-label="Task details">
            <p v-if="selectedTask.description" class="task-detail-description">{{ selectedTask.description }}</p>
            <div v-if="selectedTask.special_instructions" class="task-detail-instructions">
              <h2>Special instructions</h2>
              <p>{{ selectedTask.special_instructions }}</p>
            </div>
            <dl class="task-detail-facts">
              <div><dt>Type</dt><dd>{{ selectedTask.type === 'day' ? 'Day' : 'Evening' }}</dd></div>
              <div v-if="selectedTask.project_id"><dt>Project</dt><dd>{{ projects.find((project) => project.id === selectedTask.project_id)?.title || selectedTask.project_title || 'Project' }}</dd></div>
              <div v-if="selectedTask.type === 'evening'"><dt>Energy</dt><dd>{{ selectedTask.energy_cost === 'high' ? 'High' : 'Standard' }}</dd></div>
              <div v-if="selectedTask.type === 'evening'"><dt>Priority</dt><dd>{{ priorityLabel(selectedTask.priority) }}</dd></div>
              <div><dt>Repeat</dt><dd>{{ repeatLabel(selectedTask.recurrence_type, selectedTask.recurrence_rule) }}</dd></div>
              <div v-if="selectedTask.is_multi_session"><dt>Sessions</dt><dd>Multi-session</dd></div>
              <div v-if="selectedTask.advance_notice_days"><dt>Advance notice</dt><dd>{{ selectedTask.advance_notice_days }} {{ selectedTask.advance_notice_days === 1 ? 'day' : 'days' }}</dd></div>
              <div v-if="selectedTask.day_preference"><dt>Day preference</dt><dd>{{ dayPreferenceLabel(selectedTask.day_preference, selectedTask.day_preference_is_hard) }}</dd></div>
              <div v-if="selectedTask.deadline_on"><dt>Deadline</dt><dd>{{ formatDeadline(selectedTask.deadline_on) }} · {{ selectedTask.deadline_is_hard ? 'Hard' : 'Soft' }}</dd></div>
              <div v-if="selectedTask.dependencies?.length"><dt>Dependencies</dt><dd>{{ selectedTask.dependencies.map((dependency) => dependency.title).join(', ') }}</dd></div>
            </dl>
            <div v-if="selectedTask.lifecycle_state === 'active'" class="task-completion-actions">
              <button v-if="selectedTask.recurrence_type !== 'one_time'" type="button"
                :disabled="completionsLoading || Boolean(completionsError) || completionBusy" @click="requestTaskCompletion('occurrence')">
                {{ completionBusy ? 'Saving…' : 'Finish once' }}
              </button>
              <template v-else-if="selectedTask.is_multi_session">
                <button type="button" :disabled="completionsLoading || Boolean(completionsError) || completionBusy"
                  @click="requestTaskCompletion('session')">{{ completionBusy ? 'Saving…' : 'Finish session' }}</button>
                <button class="complete-entire-task" type="button" :disabled="completionsLoading || Boolean(completionsError) || completionBusy"
                  @click="requestTaskCompletion('final')">Complete task</button>
              </template>
              <button v-else type="button" :disabled="completionsLoading || Boolean(completionsError) || completionBusy"
                @click="requestTaskCompletion('final')">{{ completionBusy ? 'Saving…' : 'Mark completed' }}</button>
            </div>
            <p v-if="completionError" class="form-error" role="alert">{{ completionError }}</p>
            <div v-if="completionsError" class="completion-load-error" role="alert">
              <span>{{ completionsError }}</span>
              <button type="button" @click="loadTaskCompletions(selectedTask.id)">Retry</button>
            </div>
            <section v-if="completionCount > 0" class="completion-history" aria-label="Completion history">
              <div class="completion-history-heading"><h2>History</h2><span>{{ completionCount }}</span></div>
              <ol>
                <li v-for="completion in taskCompletions" :key="completion.id">
                  <span>{{ completionLabel(completion.completion_type) }}</span>
                  <time :datetime="completion.completed_at">{{ formatCompletionDate(completion.completed_at) }}</time>
                </li>
              </ol>
            </section>
            <div class="task-detail-controls">
              <button v-if="selectedTask.project_id && selectedTask.lifecycle_state === 'active'" class="task-remove-button" type="button"
                :disabled="removingTaskId !== null" @click="removeTaskFromProject(selectedTask)">
                {{ removingTaskId === selectedTask.id ? 'Removing…' : 'Remove from project' }}
              </button>
              <button class="task-delete-button" type="button" @click="openTaskDelete(selectedTask)">Delete task</button>
            </div>
            <p v-if="removalError" class="form-error" role="alert">{{ removalError }}</p>
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
          <section class="task-list-controls" aria-label="Find and sort tasks">
            <label class="task-list-search">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" stroke-width="1.8" /><path d="m15.5 15.5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
              <span class="visually-hidden">Search task titles and descriptions</span>
              <input v-model="taskSearch" type="search" placeholder="Search tasks" />
            </label>
            <button ref="taskFilterTrigger" class="task-filter-button" :class="{ 'is-active': taskTypeFilter !== 'all' || taskSort !== 'newest' || showCompleted }"
              type="button" aria-label="Filter and sort tasks" aria-haspopup="dialog" :aria-expanded="taskFiltersOpen" @click="openTaskFilters">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M7 12h10m-7 5h4" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
              <span>Filter</span>
              <span v-if="taskTypeFilter !== 'all' || taskSort !== 'newest' || showCompleted" class="task-filter-dot" aria-hidden="true"></span>
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
              <div class="task-filter-section">
                <button class="task-completed-toggle" type="button" role="switch" :aria-checked="showCompleted" @click="showCompleted = !showCompleted">
                  <span>Show completed</span>
                  <span class="task-toggle-track" aria-hidden="true"><span class="task-toggle-thumb"></span></span>
                </button>
              </div>
            </div>
          </dialog>
          <section class="task-list task-list-main" aria-label="Saved tasks">
            <p v-if="tasksLoading" class="list-message">Loading tasks…</p>
            <div v-else-if="tasksError" class="list-message" role="alert">
              <p>{{ tasksError }}</p>
              <button type="button" @click="loadTasks">Try again</button>
            </div>
            <p v-else-if="tasks.length === 0" class="list-message">{{ showCompleted ? 'No tasks saved yet.' : 'No active tasks.' }}</p>
            <div v-else-if="visibleTasks.length === 0" class="list-message">
              <p>{{ overdueOnly && taskTypeFilter === 'all' && !taskSearch.trim() ? 'No overdue tasks.' : 'No matching tasks.' }}</p>
              <button type="button" @click="resetTaskListView">Reset view</button>
            </div>
            <ul v-else>
              <li v-for="task in visibleTasks" :key="task.id" class="task-list-item" :class="{ 'has-finish-action': task.lifecycle_state === 'active' }">
                <button class="task-row" :class="{ 'has-hard-deadline': task.lifecycle_state === 'active' && task.deadline_on && task.deadline_is_hard, 'is-overdue': isOverdue(task), 'is-completed': task.lifecycle_state === 'completed' }"
                  type="button" @click="openTaskDetails(task)">
                  <span class="task-row-copy">
                    <strong>{{ task.title }}</strong>
                    <span v-if="task.description" class="task-row-description">
                      <span class="task-row-label">Description</span>
                      <span class="task-row-description-text">{{ task.description }}</span>
                    </span>
                    <span class="task-row-facts">
                      <span v-if="task.lifecycle_state === 'completed'" class="task-completed-badge">Completed</span>
                      <span class="task-row-type" :class="task.type === 'day' ? 'is-day' : 'is-evening'">{{ task.type === 'day' ? 'Day' : 'Evening' }}</span>
                      <span v-if="task.project_id" class="task-row-fact">
                        <span class="task-row-label">Project</span>
                        <span class="task-row-fact-value">{{ projects.find((project) => project.id === task.project_id)?.title || task.project_title || `#${task.project_id}` }}</span>
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
                </button>
                <button v-if="task.lifecycle_state === 'active'" class="task-row-finish" type="button"
                  :aria-label="`Finish task: ${task.title}`" :title="task.is_multi_session ? 'Finish session or complete task' : task.recurrence_type !== 'one_time' ? 'Finish once' : 'Mark completed'"
                  :disabled="completionBusy || actionBusy" @click="openQuickCompletion(task)">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m4.5 12 5 5L19.5 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
                <button class="task-row-delete" type="button" :aria-label="`Delete task: ${task.title}`" @click="openTaskDelete(task)">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2m3 0-.7 12H6.7L6 7m4 3v6m4-6v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
              </li>
            </ul>
          </section>
        </template>
      </template>

      <template v-else-if="activeView === 'capture'">
        <header class="capture-heading">
          <button class="project-back" type="button" @click="cancelCapture">← {{ captureForTomorrow ? 'Set up tomorrow' : editingTaskId !== null ? 'Task' : entryKind === 'project' ? 'Projects' : 'Tasks' }}</button>
          <h1>{{ editingTaskId !== null ? 'Edit task' : editingProjectId !== null ? 'Edit project' : `New ${entryKind}` }}</h1>
        </header>

        <section class="task-panel" :aria-label="editingTaskId !== null ? 'Edit task' : editingProjectId !== null ? 'Edit project' : entryKind === 'project' ? 'Add a project' : 'Add a task'">
          <form class="task-form" novalidate @submit.prevent="saveTask">
            <div v-if="editingTaskId === null && editingProjectId === null && !captureForTomorrow" class="capture-kind" role="group" aria-label="Add">
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
              {{ isSaving ? 'Saving…' : editingTaskId !== null || editingProjectId !== null ? 'Save changes' : entryKind === 'project' ? 'Save project' : 'Save task' }}
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

                <details v-if="entryKind === 'task'" ref="specialInstructionsDetail" class="form-detail" @toggle="closeOtherDetails">
                  <summary>
                    <span>Special instructions</span>
                    <span class="detail-value">{{ taskForm.special_instructions.trim() ? 'Added' : 'Optional' }}</span>
                  </summary>
                  <div class="detail-content">
                    <label class="field">
                      <span class="visually-hidden">Special instructions</span>
                      <textarea v-model="taskForm.special_instructions" rows="3" maxlength="5000"></textarea>
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

      <template v-else-if="activeView === 'calendar'">
        <PlannerCalendar />
      </template>

      <template v-else>
        <header class="projects-heading">
          <button v-if="selectedProject" class="project-back" type="button" @click="selectedProjectId = null">← Projects</button>
          <h1>{{ selectedProject?.title || 'Projects' }}</h1>
          <div v-if="selectedProject" class="project-detail-actions">
            <button class="project-detail-edit" type="button" @click="openProjectEdit(selectedProject)">Edit</button>
            <button class="project-detail-delete" type="button" @click="openProjectDelete(selectedProject)">Delete</button>
          </div>
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
            <p v-if="removalError" class="form-error" role="alert">{{ removalError }}</p>
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
                <div class="project-step-footer">
                  <p class="task-meta">
                    <span :class="task.type === 'day' ? 'tag-day' : 'tag-evening'">{{ task.type === 'day' ? 'Day' : 'Evening' }}</span>
                    <span v-if="task.is_multi_session" class="tag-multi-session">Multi-session</span>
                    <span v-if="task.deadline_on" :class="task.deadline_is_hard ? 'tag-deadline-hard' : 'tag-deadline-soft'">{{ formatDeadline(task.deadline_on) }}</span>
                  </p>
                  <div class="project-step-actions">
                    <button class="project-step-finish" type="button" :disabled="completionBusy || actionBusy"
                      :aria-label="`Finish task: ${task.title}`" @click="openQuickCompletion(task)">Finish</button>
                    <button class="project-step-remove" type="button" :disabled="removingTaskId !== null"
                      :aria-label="`Remove ${task.title} from project`" @click="removeTaskFromProject(task)">
                      {{ removingTaskId === task.id ? 'Removing…' : 'Remove' }}
                    </button>
                    <button class="project-step-delete" type="button" :aria-label="`Delete task: ${task.title}`" @click="openTaskDelete(task)">Delete</button>
                  </div>
                </div>
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
            <div v-for="project in projects" :key="project.id" class="project-list-item">
              <button class="project-card" type="button" @click="selectedProjectId = project.id">
                <span class="project-card-title">{{ project.title }}</span>
                <span class="project-card-meta">
                  {{ project.steps_count ?? 0 }} {{ project.steps_count === 1 ? 'task' : 'tasks' }}
                  <span v-if="project.deadline_on"> · Due {{ formatDeadline(project.deadline_on) }}</span>
                </span>
              </button>
              <button class="project-row-delete" type="button" :aria-label="`Delete project: ${project.title}`" @click="openProjectDelete(project)">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2m3 0-.7 12H6.7L6 7m4 3v6m4-6v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </button>
            </div>
          </div>
        </template>
      </template>
    </main>

    <dialog ref="actionDialog" class="options-sheet action-sheet" aria-labelledby="action-sheet-title" @click.self="closeActionDialog" @cancel="actionBusy && $event.preventDefault()">
      <header class="options-sheet-header">
        <h2 id="action-sheet-title">{{ pendingAction?.kind === 'quick-completion' ? 'Finish task' : pendingAction?.kind === 'repeat-completion' ? 'Finish again today?' : pendingAction?.kind === 'delete-task' ? 'Delete task?' : pendingAction?.kind === 'empty-project' ? 'Delete empty project?' : 'Delete project?' }}</h2>
        <p v-if="pendingAction?.kind === 'quick-completion'" class="action-task-subtitle">{{ pendingAction.task.title }}</p>
      </header>
      <div class="options-sheet-body action-sheet-body">
        <p v-if="pendingAction?.kind === 'repeat-completion'">“{{ pendingAction.task.title }}” already has {{ pendingAction.todayCount }} {{ pendingAction.todayCount === 1 ? 'completion' : 'completions' }} today. Save another?</p>
        <p v-else-if="pendingAction?.kind === 'delete-task'">Delete “{{ pendingAction.task.title }}” permanently?</p>
        <p v-else-if="pendingAction?.kind === 'empty-project'">“{{ pendingAction.project.title }}” has no tasks left. Delete it too?</p>
        <p v-else-if="pendingAction?.kind === 'delete-empty-project'">Delete “{{ pendingAction.project.title }}” permanently?</p>
        <p v-else-if="pendingAction?.kind === 'delete-project'">What should happen to the {{ pendingAction.project.steps_count ?? 0 }} {{ pendingAction.project.steps_count === 1 ? 'task' : 'tasks' }} in “{{ pendingAction.project.title }}”?</p>
        <p v-if="actionError" class="form-error" role="alert">{{ actionError }}</p>
        <div class="action-sheet-buttons">
          <template v-if="pendingAction?.kind === 'quick-completion'">
            <template v-if="pendingAction.task.is_multi_session">
              <button class="action-preserve" type="button" :disabled="actionBusy" @click="requestTaskCompletion('session', pendingAction.task)">Finish session</button>
              <button class="action-preserve action-secondary" type="button" :disabled="actionBusy" @click="requestTaskCompletion('final', pendingAction.task)">Complete task</button>
            </template>
            <button v-else class="action-preserve" type="button" :disabled="actionBusy"
              @click="requestTaskCompletion(pendingAction.task.recurrence_type !== 'one_time' ? 'occurrence' : 'final', pendingAction.task)">
              {{ pendingAction.task.recurrence_type !== 'one_time' ? 'Finish once' : 'Mark completed' }}
            </button>
          </template>
          <template v-else-if="pendingAction?.kind === 'repeat-completion'">
            <button class="action-preserve" type="button" :disabled="actionBusy" @click="submitTaskCompletion(pendingAction.completionType, pendingAction.task)">
              {{ actionBusy ? 'Saving…' : pendingAction.completionType === 'final' ? 'Complete task anyway' : 'Save another completion' }}
            </button>
          </template>
          <template v-else-if="pendingAction?.kind === 'delete-task'">
            <button class="action-destructive" type="button" :disabled="actionBusy" @click="deleteTask">{{ actionBusy ? 'Deleting…' : 'Delete task' }}</button>
          </template>
          <template v-else-if="pendingAction?.kind === 'delete-project'">
            <button class="action-preserve" type="button" :disabled="actionBusy" @click="deleteProject('detach')">Keep tasks, delete project</button>
            <button class="action-destructive" type="button" :disabled="actionBusy" @click="deleteProject('delete')">Delete project and tasks</button>
          </template>
          <template v-else-if="pendingAction?.kind === 'empty-project' || pendingAction?.kind === 'delete-empty-project'">
            <button class="action-destructive" type="button" :disabled="actionBusy" @click="deleteProject('detach')">{{ actionBusy ? 'Deleting…' : 'Delete project' }}</button>
          </template>
          <button class="action-cancel" type="button" :disabled="actionBusy" @click="closeActionDialog">{{ pendingAction?.kind === 'empty-project' ? 'Keep project' : 'Cancel' }}</button>
        </div>
      </div>
    </dialog>

    <Transition name="completion-toast" mode="out-in">
      <div v-if="completionToast" :key="completionToast.id" class="completion-celebration" role="status" aria-live="polite">
        <span class="completion-celebration-flower" aria-hidden="true"><img src="/sunflower.svg?v=2" alt="" /></span>
        <strong>{{ completionToast.message }}</strong>
      </div>
    </Transition>

    <nav class="main-nav" aria-label="Main navigation">
      <button type="button" :aria-pressed="activeView === 'today' || (activeView === 'tasks' && Boolean(selectedTask) && taskReturnView === 'today')" @click="showToday">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M4 5.5h16v14H4zM8 3v5m8-5v5M4 10h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          <circle cx="12" cy="14.5" r="2" fill="currentColor" />
        </svg>
        <span>Today</span>
      </button>
      <button type="button" :aria-pressed="activeView === 'tomorrow'" @click="showTomorrow">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8" />
          <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4m0-14.2-1.4 1.4M6.3 17.7l-1.4 1.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        </svg>
        <span>Tomorrow</span>
      </button>
      <button type="button" :aria-pressed="activeView === 'tasks' && !(selectedTask && taskReturnView === 'today')" @click="showTasks">
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
      <button type="button" :aria-pressed="activeView === 'calendar'" @click="activeView = 'calendar'">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.8" />
          <path d="M7 3v4m10-4v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        </svg>
        <span>Calendar</span>
      </button>
    </nav>
  </div>
</template>
