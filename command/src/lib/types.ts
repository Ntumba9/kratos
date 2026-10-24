// Shapes returned by the KRATOS API (see docs/api/openapi.yaml).

export interface ApiEnvelope<T> {
  data: T
  meta?: { pagination?: Pagination } & Record<string, unknown>
}

export interface Pagination {
  page: number
  per_page: number
  total: number
  last_page: number
}

export interface Paginated<T> {
  data: T[]
  meta: { pagination: Pagination }
}

export interface User {
  id: string
  name: string
  email: string
  email_verified: boolean
  email_verified_at: string | null
  last_login_at: string | null
  created_at: string
}

export interface MembershipSummary {
  id: string
  organisation: { id: string; name: string; slug: string; is_demo?: boolean }
  role: { slug: RoleSlug; name: string }
  permissions: string[]
}

export interface CurrentUser {
  user: User
  platform_role: { slug: string; name: string; permissions: string[] } | null
  memberships: MembershipSummary[]
}

export type RoleSlug = 'platform_admin' | 'org_admin' | 'analyst' | 'viewer'

export interface Role {
  slug: RoleSlug
  name: string
  scope: 'platform' | 'organisation'
  description: string | null
  permissions: string[]
}

export interface Organisation {
  id: string
  name: string
  slug: string
  event_retention_days: number
  settings: Record<string, unknown>
  member_count?: number
  created_at: string
  updated_at: string
}

export interface Membership {
  id: string
  user: { id: string; name: string; email: string }
  role: { slug: RoleSlug; name: string }
  created_at: string
}

export interface AuditLog {
  id: string
  action: string
  actor: { id: string; name: string; email: string } | null
  target: { type: string; id: string } | null
  ip_address: string | null
  user_agent: string | null
  request_id: string | null
  metadata: Record<string, unknown>
  created_at: string
}

export interface SessionInfo {
  id: string
  ip_address: string | null
  user_agent: string | null
  last_active_at: string
  current: boolean
}

export interface AccessToken {
  id: string
  name: string
  abilities: string[]
  last_used_at: string | null
  expires_at: string
  created_at: string
}

export interface Health {
  status: 'ok' | 'degraded'
  checks: Record<string, { status: 'up' | 'down'; latency_ms: number | null }>
  checked_at: string
}

// ---------------------------------------------------------------- Phase 2

export type Severity = 'info' | 'low' | 'medium' | 'high' | 'critical'

export const SEVERITIES: Severity[] = ['info', 'low', 'medium', 'high', 'critical']

export type ProcessingStatus = 'received' | 'queued' | 'processing' | 'processed' | 'failed' | 'dead_lettered'

export interface SecurityEvent {
  id: string
  event_type: string
  source: string
  severity: Severity
  occurred_at: string
  received_at: string
  source_ip: string | null
  asset: { id: string; name: string; criticality: string } | null
  processing_status: ProcessingStatus
  risk_score: number | null
  verdict: Verdict | null
  threat_category: string | null
  metadata: Record<string, unknown>
  // detail view only
  idempotency_key?: string | null
  fingerprint?: string
  credential?: { id: string; name: string; key_id: string } | null
  analysis?: {
    analysed_at: string | null
    summary: string | null
    anomaly_percentile: number | null
    model_version?: string | null
    detections?: DetectionResult[]
  }
  processing?: {
    attempts: number
    last_error: string | null
    started_at: string | null
    processed_at: string | null
    published_at?: string | null
    publish_attempts?: number | null
  }
}

export type AssetType = 'server' | 'application' | 'database' | 'network_device' | 'endpoint' | 'cloud_service'

export interface Asset {
  id: string
  name: string
  asset_type: AssetType
  hostname: string | null
  ip_address: string | null
  environment: 'production' | 'staging' | 'development'
  criticality: 'low' | 'medium' | 'high' | 'critical'
  tags: string[]
  description: string | null
  last_seen_at: string | null
  events_last_24h?: number
  retired_at: string | null
  created_at: string
}

export interface IngestionCredential {
  id: string
  name: string
  key_id: string
  scopes: string[]
  status: 'active' | 'expired' | 'revoked'
  last_used_at: string | null
  last_used_ip: string | null
  expires_at: string | null
  revoked_at: string | null
  created_by?: { id: string; name: string } | null
  created_at: string
}

export interface OverviewStats {
  generated_at: string
  assets: { total: number; by_criticality: Record<string, number>; seen_last_24h: number }
  events: {
    total: number
    last_24h: number
    last_24h_by_severity: Record<Severity, number>
    high_or_critical_last_24h: number
    by_status: Record<ProcessingStatus, number>
  }
  throughput: {
    per_minute: Array<{ minute: string; received: number; processed: number }>
    received_last_minute: number
    processed_last_minute: number
    average_processing_ms: number | null
  }
  timeline: Array<{ hour: string } & Record<Severity, number>>
  top_event_types: Array<{ event_type: string; count: number }>
  incidents: null
}

// ---------------------------------------------------------------- Phase 3

export type Verdict = 'benign' | 'suspicious' | 'malicious'

export interface DetectionResult {
  id: string
  detector: 'rule' | 'anomaly_model' | 'statistical'
  rule_key: string | null
  severity: Severity | null
  category: string | null
  score: number
  explanation: string
  details: Record<string, unknown>
  created_at: string
  event?: { id: string; event_type: string; severity: Severity; source_ip: string | null; occurred_at: string; risk_score: number | null; verdict: Verdict | null } | null
}

export interface DetectionRule {
  key: string
  name: string
  description: string
  category: string
  severity: Severity
  enabled: boolean
  parameters: Record<string, number>
  defaults: Record<string, number>
  bounds: Record<string, { min: number; max: number }>
  customised: boolean
}

export interface IntelligenceStats {
  generated_at: string
  analysed_last_24h: number
  awaiting_analysis: number
  verdicts: Record<Verdict, number>
  categories: Array<{ category: string; count: number }>
  risk_histogram: Array<{ from: number; to: number; count: number }>
  rule_hits: Array<{ rule_key: string; count: number; max_score: number }>
  anomaly_trend: Array<{ hour: string; anomalous: number; flagged: number; analysed: number }>
}

export interface Rates {
  precision: number
  recall: number
  f1: number
  false_positive_rate: number
}

export interface ModelCard {
  name: string
  version: string
  algorithm: string
  artifact_sha256: string
  trained_at: string | null
  training_dataset: { synthetic?: boolean; seed?: number; train_normal?: number; test_normal?: number; test_per_attack?: number }
  metrics: {
    test_size?: { normal: number; attack: number }
    anomaly_model?: { roc_auc: number; pr_auc: number; at_threshold: Rates; detection_rate_by_scenario: Record<string, number> }
    rules_only?: Rates & { detection_rate_by_scenario: Record<string, number> }
    risk_score?: {
      roc_auc: number
      pr_auc: number
      suspicious_or_worse: Rates & { detection_rate_by_scenario: Record<string, number> }
      malicious: Rates & { detection_rate_by_scenario: Record<string, number> }
    }
  }
  limitations: string[]
  synthetic_evaluation: boolean
  last_seen_at: string | null
}
