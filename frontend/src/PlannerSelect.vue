<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'

const props = defineProps({
  modelValue: { required: true },
  options: { type: Array, required: true },
  label: { type: String, required: true },
  invalid: { type: Boolean, default: false },
  describedBy: { type: String, default: undefined },
  grid: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])
const root = ref(null)
const trigger = ref(null)
const menu = ref(null)
const optionRefs = ref([])
const isOpen = ref(false)
const opensAbove = ref(false)
const menuMaxHeight = ref(220)
const listId = useId()
const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue))

function focusOption(index) {
  const option = optionRefs.value[index]
  option?.focus({ preventScroll: true })
  if (!option || !menu.value) return

  if (option.offsetTop < menu.value.scrollTop) {
    menu.value.scrollTop = option.offsetTop
  } else if (option.offsetTop + option.offsetHeight > menu.value.scrollTop + menu.value.clientHeight) {
    menu.value.scrollTop = option.offsetTop + option.offsetHeight - menu.value.clientHeight
  }
}

async function openMenu() {
  opensAbove.value = false
  menuMaxHeight.value = 220
  isOpen.value = true
  await nextTick()
  const viewport = root.value?.closest('.options-sheet-body') || document.documentElement
  const viewportBounds = viewport.getBoundingClientRect()
  const triggerBounds = trigger.value.getBoundingClientRect()
  const spaceBelow = Math.max(0, viewportBounds.bottom - triggerBounds.bottom - 8)
  const spaceAbove = Math.max(0, triggerBounds.top - viewportBounds.top - 8)
  const menuHeight = Math.min(menu.value.scrollHeight, 220)
  opensAbove.value = spaceBelow < menuHeight && spaceAbove > spaceBelow
  menuMaxHeight.value = Math.max(60, Math.min(220, opensAbove.value ? spaceAbove : spaceBelow))
  await nextTick()
  const selectedIndex = props.options.findIndex((option) => option.value === props.modelValue)
  focusOption(Math.max(selectedIndex, 0))
}

function closeMenu(restoreFocus = false) {
  isOpen.value = false
  if (restoreFocus) nextTick(() => trigger.value?.focus({ preventScroll: true }))
}

function toggleMenu() {
  if (isOpen.value) closeMenu(true)
  else openMenu()
}

function selectOption(option) {
  emit('update:modelValue', option.value)
  emit('change', option.value)
  closeMenu(true)
}

function onTriggerKeydown(event) {
  if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
    event.preventDefault()
    openMenu()
  }
}

function onOptionKeydown(event, index) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeMenu(true)
    return
  }
  if (event.key === 'Tab') {
    closeMenu()
    return
  }

  const step = props.grid ? 7 : 1
  const movement = {
    ArrowDown: step,
    ArrowUp: -step,
    ArrowRight: props.grid ? 1 : 0,
    ArrowLeft: props.grid ? -1 : 0,
  }[event.key]

  if (movement || event.key === 'Home' || event.key === 'End') {
    event.preventDefault()
    const nextIndex = event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? props.options.length - 1
        : Math.min(Math.max(index + movement, 0), props.options.length - 1)
    focusOption(nextIndex)
  }
}

function onPointerDown(event) {
  if (isOpen.value && !root.value?.contains(event.target)) closeMenu()
}

function onFocusIn(event) {
  if (isOpen.value && !root.value?.contains(event.target)) closeMenu()
}

onMounted(() => {
  document.addEventListener('pointerdown', onPointerDown)
  document.addEventListener('focusin', onFocusIn)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onPointerDown)
  document.removeEventListener('focusin', onFocusIn)
})

defineExpose({ focus: () => trigger.value?.focus({ preventScroll: true }) })
</script>

<template>
  <div ref="root" class="planner-select" :class="{ 'is-open': isOpen, 'is-invalid': invalid }">
    <button ref="trigger" class="planner-select-trigger" type="button" :aria-label="`${label}: ${selectedOption?.label || 'Choose'}`"
      aria-haspopup="listbox" :aria-expanded="isOpen" :aria-controls="isOpen ? listId : undefined"
      :aria-invalid="invalid" :aria-describedby="describedBy" @click="toggleMenu" @keydown="onTriggerKeydown">
      <span>{{ selectedOption?.label || 'Choose' }}</span>
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>
    <div v-if="isOpen" :id="listId" ref="menu" class="planner-select-menu" :class="{ 'is-grid': grid, 'is-above': opensAbove }"
      :style="{ maxHeight: `${menuMaxHeight}px` }" role="listbox" :aria-label="label">
      <button v-for="(option, index) in options" :key="option.value" :ref="(element) => { optionRefs[index] = element }"
        class="planner-select-option" :class="{ selected: option.value === modelValue }" type="button" role="option"
        :aria-selected="option.value === modelValue" @click="selectOption(option)" @keydown="onOptionKeydown($event, index)">
        <span>{{ option.label }}</span>
        <svg v-if="!grid && option.value === modelValue" viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <path d="m4 10 4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </div>
  </div>
</template>
