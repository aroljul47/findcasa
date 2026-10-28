const listeners = new Set()

export function toast(message, type = 'success') {
  if (!message) return
  listeners.forEach((listener) => listener({ id: `${Date.now()}-${Math.random()}`, message, type }))
}

export function subscribeToasts(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}
