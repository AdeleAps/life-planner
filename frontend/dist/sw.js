self.addEventListener('push', (event) => {
  let message = {}

  try {
    message = event.data?.json() || {}
  } catch {
    message = {}
  }

  event.waitUntil(self.registration.showNotification(message.title || 'Planner', {
    body: message.body || 'Open Planner to review your day.',
    icon: '/sunflower.svg',
    badge: '/sunflower.svg',
    data: { url: message.url || '/?setup=tomorrow' },
    tag: 'planner-evening-review',
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const destination = new URL(event.notification.data?.url || '/?setup=tomorrow', self.location.origin)
  if (destination.origin !== self.location.origin) return

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const existing = windows.find((client) => new URL(client.url).origin === self.location.origin)

    if (existing) {
      const opened = await existing.navigate(destination.href)
      await (opened || existing).focus()
      return
    }

    await self.clients.openWindow(destination.href)
  })())
})
