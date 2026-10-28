import axios from 'axios'
import { toast } from './toast'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = window.localStorage.getItem('findcasa_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => {
    const method = response.config.method?.toLowerCase()
    if (['post', 'put', 'patch', 'delete'].includes(method) && response.data?.message) toast(response.data.message, 'success')
    return response
  },
  (error) => {
    if (error.response?.status === 401) window.localStorage.removeItem('findcasa_token')
    const message = error.response?.data?.message
    if (message && error.response?.status !== 401) toast(message, 'error')
    return Promise.reject(error)
  },
)

export default api
