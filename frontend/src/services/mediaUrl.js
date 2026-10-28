const apiUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const backendUrl = import.meta.env.VITE_BACKEND_URL || apiUrl.replace(/\/api\/?$/, '')

export function mediaUrl(path) {
  if (!path) return null
  return path.startsWith('http') ? path : `${backendUrl}${path.startsWith('/') ? path : `/${path}`}`
}
