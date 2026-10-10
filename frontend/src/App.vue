<script setup>
import { onMounted, reactive, ref } from 'vue'

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
const taskForm = reactive({
  title: '',
  description: '',
  type: 'evening',
  energy_cost: '',
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

async function saveTask() {
  if (isSaving.value) return

  if (!taskForm.energy_cost) {
    saveError.value = 'Choose the energy needed before saving.'
    saveMessage.value = ''
    return
  }

  isSaving.value = true
  saveError.value = ''
  saveMessage.value = ''

  try {
    const response = await fetch('/api/v1/tasks', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        client_request_id: clientRequestId.value,
        title: taskForm.title.trim(),
        description: taskForm.description.trim() || null,
        type: taskForm.type,
        energy_cost: taskForm.energy_cost,
      }),
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
    taskForm.energy_cost = ''
    clientRequestId.value = crypto.randomUUID()
    saveMessage.value = 'Task saved.'
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
        <img class="brand-flower" src="/sunflower.svg" alt="" />
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
          <img class="hero-flower" src="/sunflower.svg" alt="" aria-hidden="true" />
        </section>

        <section class="empty-plan" aria-labelledby="empty-plan-title">
          <div class="empty-plan-mark" aria-hidden="true">
            <img src="/sunflower.svg" alt="" />
          </div>
          <div>
            <p class="section-kicker">No plan yet</p>
            <h2 id="empty-plan-title">Tomorrow is open.</h2>
            <p>Your plan for tomorrow will appear here.</p>
          </div>
        </section>
      </template>

      <template v-else>
        <section class="hero hero-tasks" aria-labelledby="tasks-title">
          <div class="hero-content">
            <p class="hero-kicker">Your task library</p>
            <h1 id="tasks-title">Tasks<span class="title-dot">.</span></h1>
            <p class="hero-date">Save it now. Schedule it later.</p>
          </div>
          <span v-if="!tasksLoading && !tasksError" class="hero-count">{{ tasks.length }} saved</span>
        </section>

        <section class="task-panel" aria-labelledby="add-task-title">
          <div class="panel-heading">
            <p class="section-kicker">Quick capture</p>
            <h2 id="add-task-title">Add a task</h2>
          </div>
          <form class="task-form" @submit.prevent="saveTask">
            <label class="field">
              <span>What needs doing?</span>
              <input v-model="taskForm.title" type="text" maxlength="255" required placeholder="e.g. Sort my paperwork" />
            </label>

            <label class="field">
              <span>Context <small>optional</small></span>
              <textarea v-model="taskForm.description" rows="3" maxlength="5000" placeholder="Anything you want to remember about it"></textarea>
            </label>

            <div class="choice-groups">
              <fieldset class="choice-group">
                <legend>Task type</legend>
                <div class="choice-grid type-choices">
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

              <fieldset class="choice-group">
                <legend>Energy needed</legend>
                <div class="choice-grid energy-choices">
                  <label class="choice-option energy-option" :class="{ selected: taskForm.energy_cost === 'low' }">
                    <input v-model="taskForm.energy_cost" type="radio" name="energy" value="low" />
                    <span>Low</span>
                  </label>
                  <label class="choice-option energy-option" :class="{ selected: taskForm.energy_cost === 'medium' }">
                    <input v-model="taskForm.energy_cost" type="radio" name="energy" value="medium" />
                    <span>Medium</span>
                  </label>
                  <label class="choice-option energy-option" :class="{ selected: taskForm.energy_cost === 'high' }">
                    <input v-model="taskForm.energy_cost" type="radio" name="energy" value="high" />
                    <span>High</span>
                  </label>
                </div>
              </fieldset>
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
            <div>
              <p class="section-kicker">Your library</p>
              <h2 id="saved-tasks-title">Saved tasks</h2>
            </div>
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
                <span class="tag-energy">{{ task.energy_cost }} energy</span>
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
