<script setup>
import { computed, reactive, ref, watch } from 'vue'

const props = defineProps({
  date: { type: String, required: true },
  refreshKey: { type: Number, default: 0 },
})

const periods = ref({ week: null, month: null })
const loading = ref(true)
const error = ref('')
const dialog = ref(null)
const draft = reactive({ week: '', month: '' })
const saving = ref(false)
const saveError = ref('')
const activeFocuses = computed(() => ['month', 'week'].filter((kind) => periods.value[kind]?.focus))

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await fetch(`/api/v1/focuses?date=${props.date}`, {
      headers: { Accept: 'application/json' }, cache: 'no-store',
    })
    if (!response.ok) throw new Error('Could not load your focus.')
    periods.value = await response.json()
  } catch (cause) {
    error.value = cause.message || 'Could not load your focus.'
  } finally {
    loading.value = false
  }
}

function edit() {
  draft.week = periods.value.week?.focus || ''
  draft.month = periods.value.month?.focus || ''
  saveError.value = ''
  dialog.value?.showModal()
}

async function save() {
  if (saving.value) return
  saving.value = true
  saveError.value = ''

  try {
    for (const kind of ['week', 'month']) {
      const period = periods.value[kind]
      const response = await fetch(`/api/v1/focuses/${kind}/${period.starts_on}`, {
        method: 'PUT',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ focus: draft[kind].trim() || null }),
      })
      if (!response.ok) throw new Error('Could not save your focus.')
    }

    dialog.value?.close()
    await load()
  } catch (cause) {
    saveError.value = cause.message || 'Could not save your focus.'
  } finally {
    saving.value = false
  }
}

watch(() => [props.date, props.refreshKey], load, { immediate: true })
</script>

<template>
  <section class="focus-panel" :class="{ 'has-focus': activeFocuses.length }" aria-label="Planning focus">
    <p v-if="error" class="focus-error" role="alert">{{ error }} <button type="button" @click="load">Retry</button></p>
    <template v-else-if="!loading">
      <div v-if="activeFocuses.length" class="focus-card">
        <div class="focus-card-items" :class="{ 'has-pair': activeFocuses.length > 1 }">
          <div v-for="kind in activeFocuses" :key="kind" class="focus-card-item" :class="{ 'is-month': kind === 'month' }">
            <span>{{ kind === 'week' ? 'Weekly focus' : 'Monthly focus' }}</span>
            <p>{{ periods[kind].focus }}</p>
          </div>
        </div>
        <button class="focus-card-edit" type="button" @click="edit">Edit</button>
      </div>
      <button v-else class="focus-set-button" type="button" @click="edit"><span aria-hidden="true">+</span> Set a focus</button>
    </template>

    <dialog ref="dialog" class="focus-dialog" aria-labelledby="focus-dialog-title" @click.self="dialog?.close()">
      <form @submit.prevent="save">
        <header><h2 id="focus-dialog-title">Your focus</h2><button type="button" aria-label="Close" @click="dialog?.close()">×</button></header>
        <div class="focus-fields">
          <div v-for="kind in ['week', 'month']" :key="kind" class="focus-field">
            <div class="focus-field-heading"><label :for="`focus-${kind}`">{{ kind === 'week' ? 'Weekly focus' : 'Monthly focus' }}</label><button v-if="draft[kind]" type="button" @click="draft[kind] = ''">Clear</button></div>
            <textarea :id="`focus-${kind}`" v-model="draft[kind]" rows="2" maxlength="500"></textarea>
          </div>
          <p v-if="saveError" class="focus-error" role="alert">{{ saveError }}</p>
        </div>
        <footer><button type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save focus' }}</button></footer>
      </form>
    </dialog>
  </section>
</template>

<style scoped>
.focus-panel { display: flex; justify-content: flex-end; margin: 0 4px 12px; }
.focus-panel.has-focus { display: block; margin: 0 0 12px; }
.focus-set-button { display: inline-flex; align-items: center; gap: 6px; padding: 5px 2px; border: 0; background: transparent; color: var(--green-deep); font-size: 13px; font-weight: 750; cursor: pointer; }
.focus-set-button span { font-size: 19px; font-weight: 400; line-height: 1; }
.focus-card { display: flex; align-items: center; gap: 18px; width: 100%; min-height: 64px; padding: 11px 19px; border: 1px solid #e6e8d9; border-left: 6px solid var(--sun); border-radius: 18px; background: linear-gradient(100deg, #fff8dc 0%, #fff 38%); box-shadow: 0 8px 22px #12392d14; }
.focus-card-items { display: grid; flex: 1; min-width: 0; gap: 18px; }
.focus-card-items.has-pair { grid-template-columns: minmax(0, 1.25fr) minmax(0, .75fr); }
.focus-card-item { min-width: 0; }
.focus-card-item span { color: var(--green-deep); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.focus-card-item p { display: -webkit-box; overflow: hidden; margin: 2px 0 0; color: var(--ink); font-size: 16px; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.focus-card-item.is-month p { font-size: 18px; font-weight: 800; }
.focus-card-items.has-pair .focus-card-item:not(.is-month) p { color: #4f695f; font-size: 14px; font-weight: 650; }
.focus-card-edit { flex: none; padding: 5px 0; border: 0; background: transparent; color: var(--green-deep); font-size: 12px; font-weight: 800; cursor: pointer; }
.focus-error { margin: 0; color: var(--error); font-size: 13px; }
.focus-error button { border: 0; background: transparent; color: inherit; font-weight: 800; text-decoration: underline; cursor: pointer; }
.focus-dialog[open] { position: fixed; z-index: 30; top: auto; right: 0; bottom: 0; left: 0; width: min(100%, 640px); max-width: none; max-height: min(90dvh, 700px); margin: 0 auto; padding: 0; border: 1px solid var(--line); border-radius: 25px 25px 0 0; background: white; color: var(--ink); box-shadow: 0 -18px 60px #12392d30; overflow: auto; }
.focus-dialog::backdrop { background: #102c2580; backdrop-filter: blur(4px); }
.focus-dialog header { display: flex; align-items: center; justify-content: space-between; padding: 22px 22px 8px; }
.focus-dialog h2 { margin: 0; font-size: 26px; letter-spacing: -.05em; }
.focus-dialog header button { width: 37px; height: 37px; border: 0; border-radius: 12px; background: #eef5f1; color: var(--ink); font-size: 28px; cursor: pointer; }
.focus-fields { display: grid; gap: 18px; padding: 20px 22px 24px; }
.focus-field { display: grid; gap: 8px; }
.focus-field-heading { display: flex; align-items: center; justify-content: space-between; }
.focus-field-heading label { font-size: 14px; font-weight: 800; }
.focus-field-heading button { border: 0; background: transparent; color: var(--green-deep); font-size: 13px; font-weight: 800; cursor: pointer; }
.focus-field textarea { width: 100%; resize: vertical; min-height: 76px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 13px; outline-color: var(--green); background: #f8fbf9; color: var(--ink); }
.focus-dialog footer { position: sticky; bottom: 0; padding: 16px 22px max(16px, env(safe-area-inset-bottom)); border-top: 1px solid var(--line); background: white; }
.focus-dialog footer button { width: 100%; padding: 16px; border: 0; border-radius: 14px; background: var(--sun); color: var(--ink); font-weight: 800; cursor: pointer; }
</style>
