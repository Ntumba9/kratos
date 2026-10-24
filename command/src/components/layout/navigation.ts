import { Activity, Boxes, BrainCircuit, Building2, FileClock, KeyRound, LayoutDashboard, type LucideIcon, ShieldAlert, ShieldCheck, Users } from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Hide unless the active membership has this permission (UI hint only). */
  permission?: string
  /** Delivery phase for modules not built yet; rendered as disabled with a label. */
  plannedPhase?: number
}

export interface NavSection {
  label: string
  items: NavItem[]
}

export const navigation: NavSection[] = [
  {
    label: 'Operations',
    items: [
      { to: '/', label: 'Overview', icon: LayoutDashboard },
      { to: '/events', label: 'Security Events', icon: Activity, permission: 'events.view' },
      { to: '/incidents', label: 'Incidents', icon: ShieldAlert, permission: 'incidents.view', plannedPhase: 4 },
      { to: '/intelligence', label: 'Threat Intelligence', icon: BrainCircuit, permission: 'detections.view' },
      { to: '/infrastructure', label: 'Infrastructure', icon: Boxes, permission: 'assets.view' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { to: '/admin/organisation', label: 'Organisation', icon: Building2, permission: 'organisation.view' },
      { to: '/admin/members', label: 'Members', icon: Users, permission: 'members.view' },
      { to: '/admin/ingestion-keys', label: 'Ingestion Keys', icon: KeyRound, permission: 'api_credentials.manage' },
      { to: '/admin/audit-log', label: 'Audit Log', icon: FileClock, permission: 'audit_logs.view' },
    ],
  },
  {
    label: 'Account',
    items: [{ to: '/account/security', label: 'Security', icon: ShieldCheck }],
  },
]
