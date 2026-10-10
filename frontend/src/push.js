export function supportsReminders() {
  return window.isSecureContext
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window
}

export async function registerPlannerWorker() {
  if (!supportsReminders()) return null
  return navigator.serviceWorker.register('/sw.js')
}

export async function getReminderConfiguration() {
  const response = await fetch('/api/v1/push/configuration', {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  })

  if (!response.ok) throw new Error('Reminders are unavailable.')
  return response.json()
}

export async function existingReminderSubscription() {
  const registration = await navigator.serviceWorker.ready
  return registration.pushManager.getSubscription()
}

export async function subscribeToReminders(publicKey) {
  const registration = await navigator.serviceWorker.ready
  let subscription = await registration.pushManager.getSubscription()

  if (!subscription) {
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: decodeBase64Url(publicKey),
    })
  }

  await saveSubscription(subscription)
  return subscription
}

export async function saveSubscription(subscription) {
  const response = await fetch('/api/v1/push/subscriptions', {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(subscription.toJSON()),
  })

  if (!response.ok) throw new Error('Could not enable reminders.')
}

export async function unsubscribeFromReminders(subscription) {
  const response = await fetch('/api/v1/push/subscriptions', {
    method: 'DELETE',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(subscription.toJSON()),
  })

  if (!response.ok) throw new Error('Could not turn off reminders.')
  await subscription.unsubscribe()
}

function decodeBase64Url(value) {
  const normalized = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=')
  return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0))
}
