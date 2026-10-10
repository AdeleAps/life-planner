<script setup>
import { nextTick, onMounted, reactive, ref, watch } from 'vue'

const tomorrow = new Date()
tomorrow.setDate(tomorrow.getDate() + 1)

const tomorrowLabel = new Intl.DateTimeFormat(undefined, {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
}).format(tomorrow)

const activeView = ref('tomorrow')
const serverAvailable = ref(true)
const isCheckingServer = ref(false)
const tasks = ref([])
const tasksLoading = ref(false)
const tasksError = ref('')
const saveError = ref('')
const saveMessage = ref('')
const isSaving = ref(false)
const clientRequestId = ref(crypto.randomUUID())
const taskTitleInput = ref(null)
const taskTypeDetail = ref(null)
const energyDetail = ref(null)
const contextDetail = ref(null)
const taskForm = reactive({
  title: '',
  description: '',
  type: 'evening',
  energy_cost: 'standard',
})

watch(() => taskForm.type, (type) => {
  taskForm.energy_cost = type === 'evening' ? 'standard' : null
})

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

function showTasks() {
  activeView.value = 'tasks'
  loadTasks()
}

async function openTaskForm() {
  showTasks()
  await nextTick()
  taskTitleInput.value?.focus()
}

function closeOtherDetails(event) {
  if (!event.target.open) return

  for (const detail of [taskTypeDetail.value, energyDetail.value, contextDetail.value]) {
    if (detail && detail !== event.target) detail.open = false
  }
}

function finishChoice(field) {
  const detail = field === 'type' ? taskTypeDetail.value : energyDetail.value
  if (!detail) return

  detail.open = false
  detail.querySelector('summary')?.focus({ preventScroll: true })
}

function closeAllDetails() {
  for (const detail of [taskTypeDetail.value, energyDetail.value, contextDetail.value]) {
    if (detail) detail.open = false
  }
}

async function saveTask() {
  if (isSaving.value) return

  isSaving.value = true
  saveError.value = ''
  saveMessage.value = ''

  try {
    const taskInput = {
      client_request_id: clientRequestId.value,
      title: taskForm.title.trim(),
      description: taskForm.description.trim() || null,
      type: taskForm.type,
    }

    if (taskForm.type === 'evening') {
      taskInput.energy_cost = taskForm.energy_cost
    }

    const response = await fetch('/api/v1/tasks', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(taskInput),
    })
    const result = await response.json()

    if (!response.ok) {
      const firstError = Object.values(result.errors || {})[0]?.[0]
      throw new Error(firstError || 'Task could not be saved.')
    }

    tasks.value = [result.data, ...tasks.value.filter((task) => task.id !== result.data.id)]
    taskForm.title = ''
    taskForm.description = ''
    taskForm.type = 'evening'
    taskForm.energy_cost = 'standard'
    clientRequestId.value = crypto.randomUUID()
    saveMessage.value = 'Task saved.'
    closeAllDetails()
  } catch (error) {
    saveError.value = error.message || 'Task could not be saved.'
  } finally {
    isSaving.value = false
  }
}

onMounted(checkServer)
</script>

<template>
  <div class="app-shell">
    <header class="app-header">
      <div class="brand-lockup">
        <img class="brand-flower" src="/sunflower.svg?v=2" alt="" />
        <span class="wordmark">Planner</span>
      </div>
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

        <button class="add-task-shortcut" type="button" @click="openTaskForm">
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

      <template v-else>
        <header class="tasks-heading">
          <h1>Tasks</h1>
          <span v-if="!tasksLoading && !tasksError" class="tasks-count">{{ tasks.length }} saved</span>
        </header>

        <section class="task-panel" aria-label="Add a task">
          <form class="task-form" @submit.prevent="saveTask">
            <label class="field">
              <span>What needs doing?</span>
              <input ref="taskTitleInput" v-model="taskForm.title" type="text" maxlength="255" required />
            </label>

            <div class="form-details">
              <details ref="taskTypeDetail" class="form-detail" @toggle="closeOtherDetails">
                <summary>
                  <span>Task type</span>
                  <span class="detail-value">{{ taskForm.type === 'day' ? 'Day' : 'Evening' }}</span>
                </summary>
                <div class="detail-content">
                  <div class="choice-grid type-choices" role="radiogroup" aria-label="Task type">
                    <label class="choice-option" :class="{ selected: taskForm.type === 'evening' }">
                      <input v-model="taskForm.type" type="radio" name="task-type" value="evening" @change="finishChoice('type')" />
                      <span>Evening</span>
                      <small>Substantial</small>
                    </label>
                    <label class="choice-option" :class="{ selected: taskForm.type === 'day' }">
                      <input v-model="taskForm.type" type="radio" name="task-type" value="day" @change="finishChoice('type')" />
                      <span>Day</span>
                      <small>Quick item</small>
                    </label>
                  </div>
                </div>
              </details>

              <details v-if="taskForm.type === 'evening'" ref="energyDetail" class="form-detail" @toggle="closeOtherDetails">
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

              <details ref="contextDetail" class="form-detail" @toggle="closeOtherDetails">
                <summary>
                  <span>Context</span>
                  <span class="detail-value">{{ taskForm.description.trim() ? 'Added' : 'Optional' }}</span>
                </summary>
                <div class="detail-content">
                  <label class="field">
                    <span class="visually-hidden">Context</span>
                    <textarea v-model="taskForm.description" rows="3" maxlength="5000"></textarea>
                  </label>
                </div>
              </details>
            </div>

            <p v-if="saveError" class="form-error" role="alert">{{ saveError }}</p>
            <p v-if="saveMessage" class="form-success" role="status">{{ saveMessage }}</p>
            <button class="primary-button" type="submit" :disabled="isSaving">
              {{ isSaving ? 'Saving…' : 'Save task' }}
            </button>
          </form>
        </section>

        <section class="task-list" aria-labelledby="saved-tasks-title">
          <div class="list-heading">
            <h2 id="saved-tasks-title">Saved tasks</h2>
          </div>
          <p v-if="tasksLoading" class="list-message">Loading tasks…</p>
          <div v-else-if="tasksError" class="list-message" role="alert">
            <p>{{ tasksError }}</p>
            <button type="button" @click="loadTasks">Try again</button>
          </div>
          <p v-else-if="tasks.length === 0" class="list-message">No tasks saved yet.</p>
          <ul v-else>
            <li v-for="task in tasks" :key="task.id" class="task-card">
              <div>
                <h3>{{ task.title }}</h3>
                <p v-if="task.description" class="task-description">{{ task.description }}</p>
              </div>
              <p class="task-meta">
                <span :class="task.type === 'day' ? 'tag-day' : 'tag-evening'">{{ task.type === 'day' ? 'Day' : 'Evening' }}</span>
                <span v-if="task.type === 'evening' && task.energy_cost" class="tag-energy">{{ task.energy_cost === 'high' ? 'High' : 'Standard' }} energy</span>
              </p>
            </li>
          </ul>
        </section>
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
    </nav>
  </div>
</template>
