import { describe, expect, it, vi } from 'vitest'
import { api, ApiError, readCookie, toQuery } from './api'

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })
}

describe('api client', () => {
  it('fetches the CSRF cookie before the first state-changing request and echoes it', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      if (String(input) === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=token%3Dvalue'
        return new Response(null, { status: 204 })
      }
      return jsonResponse(200, { data: { ok: true } })
    })

    await api.post('/organisations', { name: 'Acme' })

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(String(fetchMock.mock.calls[0]![0])).toBe('/sanctum/csrf-cookie')
    const [url, init] = fetchMock.mock.calls[1]!
    expect(url).toBe('/api/v1/organisations')
    expect(init?.credentials).toBe('same-origin')
    expect((init?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('token=value')
  })

  it('does not send the CSRF header on safe requests', async () => {
    document.cookie = 'XSRF-TOKEN=abc'
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(200, { data: [] }))

    await api.get('/organisations')

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect((fetchMock.mock.calls[0]![1]?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('maps the error envelope to ApiError with field details', async () => {
    document.cookie = 'XSRF-TOKEN=abc'
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse(422, {
        error: { code: 'validation_failed', message: 'The request payload is invalid.', details: { email: ['The email field is required.'] } },
        request_id: 'req-123',
      }),
    )

    const error = await api.post('/auth/login', {}).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    const apiError = error as ApiError
    expect(apiError.status).toBe(422)
    expect(apiError.code).toBe('validation_failed')
    expect(apiError.field('email')).toBe('The email field is required.')
    expect(apiError.requestId).toBe('req-123')
  })

  it('refreshes the CSRF token once on 419 and retries', async () => {
    document.cookie = 'XSRF-TOKEN=stale'
    let apiCalls = 0
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      if (String(input) === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=fresh'
        return new Response(null, { status: 204 })
      }
      apiCalls += 1
      return apiCalls === 1 ? jsonResponse(419, { error: { code: 'csrf_token_mismatch', message: 'x' } }) : new Response(null, { status: 204 })
    })

    await expect(api.post('/auth/logout')).resolves.toBeUndefined()
    expect(apiCalls).toBe(2)
    expect((fetchMock.mock.calls.at(-1)![1]?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('fresh')
  })

  it('builds query strings without empty values', () => {
    expect(toQuery({ page: 2, 'filter[action]': 'auth.', sort: undefined, empty: '' })).toBe('?page=2&filter%5Baction%5D=auth.')
    expect(toQuery({})).toBe('')
  })

  it('reads URL-encoded cookies', () => {
    document.cookie = 'XSRF-TOKEN=a%2Bb'
    expect(readCookie('XSRF-TOKEN')).toBe('a+b')
    expect(readCookie('missing')).toBeNull()
  })
})
