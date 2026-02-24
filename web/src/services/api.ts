const apiBase = (import.meta.env.VITE_API_BASE || '/api').replace(/\/$/, '')

export class ApiError extends Error {
  status: number
  payload: any

  constructor(message: string, status: number, payload: any) {
    super(message)
    this.status = status
    this.payload = payload
  }
}

function toUrl(path: string): string {
  if (/^https?:\/\//.test(path)) {
    return path
  }

  return `${apiBase}${path.startsWith('/') ? path : `/${path}`}`
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(toUrl(path), {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(init?.headers || {})
    },
    ...init
  })

  const text = await response.text()
  let payload: any = {}
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      throw new ApiError('Invalid JSON response from API.', response.status, { raw: text })
    }
  }

  if (!response.ok || payload.ok === false) {
    throw new ApiError(payload.error || 'API request failed.', response.status, payload)
  }

  return payload as T
}

export const api = {
  get<T>(path: string): Promise<T> {
    return request<T>(path, { method: 'GET' })
  },

  post<T>(path: string, body: Record<string, unknown>): Promise<T> {
    return request<T>(path, {
      method: 'POST',
      body: JSON.stringify(body)
    })
  }
}
