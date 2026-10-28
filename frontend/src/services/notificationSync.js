const EVENT_NAME = 'findcasa:notification-sync'

export function syncNotificationRead(id) {
  window.dispatchEvent(new CustomEvent(EVENT_NAME, { detail: { id } }))
}

export function syncAllNotificationsRead() {
  window.dispatchEvent(new CustomEvent(EVENT_NAME, { detail: { all: true } }))
}

export function onNotificationSync(listener) {
  const handler = (event) => listener(event.detail || {})
  window.addEventListener(EVENT_NAME, handler)
  return () => window.removeEventListener(EVENT_NAME, handler)
}
