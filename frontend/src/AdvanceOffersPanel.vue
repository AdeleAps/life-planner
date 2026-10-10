<script setup>
defineProps({
  offers: { type: Array, required: true },
  error: { type: String, default: '' },
  busyId: { type: Number, default: null },
})

const emit = defineEmits(['accept', 'decline', 'retry'])

function dateLabel(date) {
  return new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
    .format(new Date(`${date}T12:00:00`))
}

function noticeConflict(offer) {
  if (!offer.deadline_on || !offer.advance_notice_days) return false
  const today = new Date()
  const earliest = new Date(today.getFullYear(), today.getMonth(), today.getDate() + offer.advance_notice_days, 12)
  const earliestDate = `${earliest.getFullYear()}-${String(earliest.getMonth() + 1).padStart(2, '0')}-${String(earliest.getDate()).padStart(2, '0')}`
  return earliestDate > offer.deadline_on
}
</script>

<template>
  <section v-if="offers.length || error" class="advance-offers" aria-label="Future task plans">
    <div class="advance-offers-heading"><h3>Plan ahead</h3></div>
    <p v-if="error" class="form-error" role="alert">{{ error }} <button type="button" @click="emit('retry')">Try again</button></p>
    <div v-for="offer in offers" :key="offer.id" class="advance-offer">
      <strong>{{ offer.title }}</strong>
      <span class="advance-offer-kind">{{ offer.recurrence_type === 'one_time' ? 'One-time task' : 'Recurring task' }}</span>
      <span v-if="offer.proposed_on" class="advance-offer-date">{{ dateLabel(offer.proposed_on) }}</span>
      <p v-if="noticeConflict(offer)" class="advance-offer-conflict">
        {{ offer.advance_notice_days }} days’ notice cannot fit before the {{ dateLabel(offer.deadline_on) }} deadline.
        <template v-if="offer.proposed_on"> This date gives {{ offer.advance_notice_days - offer.notice_shortfall_days }} days’ notice.</template>
      </p>
      <p v-if="offer.research_result?.summary">{{ offer.research_result.summary }}</p>
      <div v-if="offer.research_result?.sources?.length" class="planning-research-sources">
        <a v-for="source in offer.research_result.sources" :key="source.url" :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.title }}</a>
      </div>
      <div v-if="offer.status === 'offered'" class="advance-offer-actions">
        <button type="button" :disabled="busyId !== null" @click="emit('accept', offer)">
          {{ busyId === offer.id ? 'Adding…' : 'Add to calendar' }}
        </button>
        <button type="button" :disabled="busyId !== null" @click="emit('decline', offer)">Dismiss suggestion</button>
      </div>
    </div>
  </section>
</template>
