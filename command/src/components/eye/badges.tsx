import { AlertOctagon, CheckCircle2, Clock, Loader2, RotateCcw, Skull } from 'lucide-react'
import type { ProcessingStatus, Severity } from '../../lib/types'
import { cx } from '../ui'

/** Severity colours: validated one-hue ordinal ramp (see index.css). */
export const severityColor: Record<Severity, string> = {
  info: 'var(--color-sev-info)',
  low: 'var(--color-sev-low)',
  medium: 'var(--color-sev-medium)',
  high: 'var(--color-sev-high)',
  critical: 'var(--color-sev-critical)',
}

/** Text label always accompanies the colour swatch: never colour alone. */
export function SeverityBadge({ severity }: { severity: Severity }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded bg-graphite-700 px-1.5 py-0.5 text-[11px] font-medium tracking-wide text-ink-100 capitalize">
      <span className="size-2 rounded-sm" style={{ backgroundColor: severityColor[severity] }} aria-hidden />
      {severity}
    </span>
  )
}

const statusMeta: Record<ProcessingStatus, { label: string; icon: typeof Clock; className: string; spin?: boolean }> = {
  received: { label: 'Received', icon: Clock, className: 'text-ink-300' },
  queued: { label: 'Queued', icon: Clock, className: 'text-ink-300' },
  processing: { label: 'Processing', icon: Loader2, className: 'text-signal-info', spin: true },
  processed: { label: 'Processed', icon: CheckCircle2, className: 'text-signal-ok' },
  failed: { label: 'Retrying', icon: RotateCcw, className: 'text-signal-warn' },
  dead_lettered: { label: 'Dead-lettered', icon: Skull, className: 'text-crimson-300' },
}

export function StatusBadge({ status }: { status: ProcessingStatus }) {
  const meta = statusMeta[status] ?? { label: status, icon: AlertOctagon, className: 'text-ink-300' }
  const Icon = meta.icon
  return (
    <span className={cx('inline-flex items-center gap-1 text-xs', meta.className)}>
      <Icon className={cx('size-3.5', meta.spin && 'animate-spin')} aria-hidden />
      {meta.label}
    </span>
  )
}

export function CriticalityLabel({ value }: { value: string }) {
  const tone = value === 'critical' ? 'text-crimson-300' : value === 'high' ? 'text-signal-warn' : 'text-ink-300'
  return <span className={cx('text-xs font-medium capitalize', tone)}>{value}</span>
}

const verdictStyle: Record<'benign' | 'suspicious' | 'malicious', string> = {
  benign: 'text-ink-500',
  suspicious: 'text-signal-warn',
  malicious: 'text-crimson-300',
}

/** Risk score with its verdict in words (never colour alone). */
export function RiskBadge({ risk, verdict }: { risk: number | null; verdict: 'benign' | 'suspicious' | 'malicious' | null }) {
  if (risk === null || verdict === null) return <span className="text-xs text-ink-600">not analysed</span>
  return (
    <span className={cx('inline-flex items-baseline gap-1.5 text-xs', verdictStyle[verdict])}>
      <span className="font-mono text-sm font-semibold tabular-nums">{Math.round(risk)}</span>
      <span>{verdict}</span>
    </span>
  )
}
