import { createApp } from 'vue'
import App from './App.vue'
import './style.css'
import { registerPlannerWorker } from './push.js'

createApp(App).mount('#app')

if (import.meta.env.PROD) registerPlannerWorker().catch(() => {})
