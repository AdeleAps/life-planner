<script setup>
const tomorrow = new Date()
tomorrow.setDate(tomorrow.getDate() + 1)

const tomorrowLabel = new Intl.DateTimeFormat(undefined, {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
}).format(tomorrow)
</script>

<template>
  <div class="app-shell">
    <header class="app-header">
      <span class="wordmark">Planner</span>
    </header>

    <main class="main-content">
      <div class="page-heading">
        <p class="eyebrow">Your day ahead</p>
        <h1>Tomorrow</h1>
        <p class="date">{{ tomorrowLabel }}</p>
      </div>

      <section class="empty-plan" aria-labelledby="empty-plan-title">
        <div class="sun-mark" aria-hidden="true"></div>
        <h2 id="empty-plan-title">A fresh page</h2>
        <p>Your plan for tomorrow will appear here.</p>
      </section>
    </main>
  </div>
</template>
