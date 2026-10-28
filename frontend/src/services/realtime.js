import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

let echo
let activeToken

export function getEcho() {
  const token = window.localStorage.getItem('findcasa_token')
  if (!token) return null
  if (echo && token === activeToken) return echo
  echo?.disconnect()
  activeToken = token
  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'
  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'findcasa-local-key',
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${import.meta.env.VITE_BACKEND_URL || 'http://127.0.0.1:8000'}/api/broadcasting/auth`,
    auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  })
  return echo
}

export function disconnectRealtime() {
  echo?.disconnect()
  echo = undefined
  activeToken = undefined
}
