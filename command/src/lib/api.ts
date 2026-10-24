/**
 * Minimal same-origin client for the KRATOS API.
 *
 * Authentication is the Sanctum session cookie (HttpOnly, never readable
 * here). State-changing requests echo the XSRF-TOKEN cookie in the
 * X-XSRF-TOKEN header, which Laravel verifies (double-submit CSRF defence).
 */

const API_BASE = '/api/v1'
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details: Record<string, string[]> | undefined
  readonly requestId: string | null

  constructor(status: number, code: string, message: string, details?: Record<string, string[]>, requestId: string | null = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
    this.requestId = requestId
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.details?.[name]?.[0]
  }
}

export function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

async function send(method: Method, path: string, body?: unknown): Promise<Response> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  const xsrf = readCookie('XSRF-TOKEN')
  if (!SAFE_METHODS.has(method) && xsrf) headers['X-XSRF-TOKEN'] = xsrf

  return fetch(`${API_BASE}${path}`, {
    method,
    credentials: 'same-origin',
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  })
}

export async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
  if (!SAFE_METHODS.has(method)) await ensureCsrfCookie()

  let response = await send(method, path, body)

  // CSRF token rotated (e.g. after login/logout in another tab): refresh once.
  if (response.status === 419) {
    await ensureCsrfCookie(true)
    response = await send(method, path, body)
  }

  if (response.status === 204) return undefined as T

  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const error = (payload as { error?: { code?: string; message?: string; details?: Record<string, string[]> }; request_id?: string } | null) ?? {}
    throw new ApiError(
      response.status,
      error.error?.code ?? 'http_error',
      error.error?.message ?? `Request failed with status ${response.status}`,
      error.error?.details,
      error.request_id ?? response.headers.get('X-Request-Id'),
    )
  }

  return payload as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body ?? {}),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body ?? {}),
  delete: <T = void>(path: string) => request<T>('DELETE', path),
}

export function toQuery(params: Record<string, string | number | undefined | null>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value))
  }
  const qs = search.toString()
  return qs ? `?${qs}` : ''
}
