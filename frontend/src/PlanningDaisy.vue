<template>
  <svg class="planning-daisy" viewBox="0 0 100 100" aria-hidden="true">
    <g class="planning-daisy-bloom">
      <ellipse v-for="angle in petalAngles" :key="angle" cx="50" cy="25" rx="7.5" ry="17" :transform="`rotate(${angle} 50 50)`" fill="#fffef9" stroke="#6f9684" stroke-width="2" />
      <circle cx="50" cy="50" r="13" fill="#ffd451" stroke="#aa8b3a" stroke-width="1.5" />
      <circle cx="50" cy="50" r="9" fill="#f4bd32" />
    </g>
  </svg>
</template>

<script setup>
const petalAngles = Array.from({ length: 12 }, (_, index) => index * 30)
</script>

<style scoped>
.planning-daisy {
  display: block;
  width: 64px;
  height: 64px;
  margin-bottom: 12px;
}

.planning-daisy-bloom {
  transform-origin: 50% 50%;
  animation: daisy-spin 4s linear infinite;
}

@keyframes daisy-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .planning-daisy-bloom { animation: none; }
}
</style>
