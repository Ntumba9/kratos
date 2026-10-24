import type { APIRequestContext } from '@playwright/test'
import { createHash, createHmac } from 'node:crypto'

const BASE = process.env.KRATOS_BASE_URL ?? 'http://localhost:8080'

/** Independent TypeScript implementation of the v1 signature (ADR-0006). */
export function sign(secret: string, path: string, timestamp: string, body: string): string {
  const canonical = `POST\n${path}\n${timestamp}\n${createHash('sha256').update(body).digest('hex')}`
  return 'v1=' + createHmac('sha256', secret).update(canonical).digest('hex')
}

export async function sendSigned(request: APIRequestContext, keyId: string, secret: string, payload: unknown) {
  const path = '/api/v1/ingest/events'
  const body = JSON.stringify(payload)
  const timestamp = String(Math.floor(Date.now() / 1000))
  return request.post(`${BASE}${path}`, {
    data: body,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Kratos-Key': keyId,
      'X-Kratos-Timestamp': timestamp,
      'X-Kratos-Signature': sign(secret, path, timestamp, body),
    },
  })
}

