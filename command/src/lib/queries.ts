import { api, toQuery } from './api'
import type { ApiEnvelope, Asset, DetectionResult, DetectionRule, IntelligenceStats, ModelCard, AuditLog, Health, IngestionCredential, Membership, Organisation, OverviewStats, Paginated, Role, SecurityEvent } from './types'

/** Health is read even when degraded (503), so it bypasses the error path. */
export async function fetchHealth(): Promise<Health> {
  const response = await fetch('/api/v1/health', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
  const body = (await response.json().catch(() => null)) as ApiEnvelope<Health> | null
  if (!body?.data) throw new Error(`Health endpoint returned ${response.status}`)
  return body.data
}

export const queryKeys = {
  health: ['health'] as const,
  roles: ['roles'] as const,
  organisation: (id: string) => ['organisations', id] as const,
  // per_page is part of the key: the overview (1 row) and members page (25 rows)
  // request different shapes of the same resource and must not share cache entries.
  members: (id: string, page: number, perPage: number) => ['organisations', id, 'members', { page, perPage }] as const,
  auditLogs: (id: string, params: Record<string, string | number | undefined>) => ['organisations', id, 'audit-logs', params] as const,
  sessions: ['auth', 'sessions'] as const,
  tokens: ['auth', 'tokens'] as const,
}

export const fetchOrganisation = async (id: string) => (await api.get<ApiEnvelope<Organisation>>(`/organisations/${id}`)).data

export const fetchMembers = (id: string, page = 1, perPage = 25) =>
  api.get<Paginated<Membership>>(`/organisations/${id}/members${toQuery({ page, per_page: perPage })}`)

export const fetchAuditLogs = (id: string, params: Record<string, string | number | undefined>) => {
  const { action, page, per_page, sort } = params
  return api.get<Paginated<AuditLog>>(
    `/organisations/${id}/audit-logs${toQuery({ 'filter[action]': action, page, per_page, sort })}`,
  )
}

export const fetchRoles = async () => (await api.get<ApiEnvelope<Role[]>>('/roles')).data

// ---------------------------------------------------------------- Phase 2

export type EventFilters = {
  severity?: string
  event_type?: string
  status?: string
  asset_id?: string
  from?: string
  to?: string
  q?: string
  verdict?: string
  sort?: string
  page?: number
}

export const phase2Keys = {
  stats: (id: string) => ['organisations', id, 'stats'] as const,
  events: (id: string, filters: EventFilters) => ['organisations', id, 'events', filters] as const,
  event: (id: string, eventId: string) => ['organisations', id, 'events', 'detail', eventId] as const,
  assets: (id: string, params: Record<string, string | number | undefined>) => ['organisations', id, 'assets', params] as const,
  credentials: (id: string) => ['organisations', id, 'api-credentials'] as const,
}

export const fetchOverviewStats = async (id: string) => (await api.get<ApiEnvelope<OverviewStats>>(`/organisations/${id}/stats/overview`)).data

export const fetchEvents = (id: string, f: EventFilters) =>
  api.get<Paginated<SecurityEvent>>(
    `/organisations/${id}/events${toQuery({
      'filter[severity]': f.severity,
      'filter[event_type]': f.event_type,
      'filter[status]': f.status,
      'filter[asset_id]': f.asset_id,
      'filter[from]': f.from,
      'filter[to]': f.to,
      'filter[q]': f.q,
      'filter[verdict]': f.verdict,
      sort: f.sort,
      page: f.page,
      per_page: 25,
    })}`,
  )

export const fetchEvent = async (id: string, eventId: string) =>
  (await api.get<ApiEnvelope<SecurityEvent>>(`/organisations/${id}/events/${eventId}`)).data

export const fetchAssets = (id: string, params: Record<string, string | number | undefined>) =>
  api.get<Paginated<Asset>>(
    `/organisations/${id}/assets${toQuery({
      'filter[q]': params.q,
      'filter[criticality]': params.criticality,
      'filter[asset_type]': params.asset_type,
      'filter[include_retired]': params.include_retired,
      sort: params.sort,
      page: params.page,
      per_page: params.per_page ?? 50,
    })}`,
  )

export const fetchCredentials = (id: string) => api.get<Paginated<IngestionCredential>>(`/organisations/${id}/api-credentials?per_page=100`)

// ---------------------------------------------------------------- Phase 3

export const phase3Keys = {
  intelligence: (id: string) => ['organisations', id, 'intelligence'] as const,
  rules: (id: string) => ['organisations', id, 'detection-rules'] as const,
  detections: (id: string, params: Record<string, string | number | undefined>) => ['organisations', id, 'detections', params] as const,
  model: ['intelligence', 'model'] as const,
}

export const fetchIntelligenceStats = async (id: string) =>
  (await api.get<ApiEnvelope<IntelligenceStats>>(`/organisations/${id}/stats/intelligence`)).data

export const fetchDetectionRules = async (id: string) => (await api.get<ApiEnvelope<DetectionRule[]>>(`/organisations/${id}/detection-rules`)).data

export const fetchDetections = (id: string, params: Record<string, string | number | undefined>) =>
  api.get<Paginated<DetectionResult>>(
    `/organisations/${id}/detections${toQuery({ 'filter[detector]': params.detector, 'filter[rule_key]': params.rule_key, sort: params.sort ?? '-created_at', page: params.page, per_page: params.per_page ?? 10 })}`,
  )

export const fetchModelCard = async () => (await api.get<ApiEnvelope<ModelCard>>('/intelligence/model')).data
