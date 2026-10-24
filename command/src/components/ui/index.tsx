import { AlertTriangle, CheckCircle2, Info, Loader2, XCircle } from 'lucide-react'
import { forwardRef, useId, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react'

export function cx(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

// ------------------------------------------------------------------ Button

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

const buttonStyles: Record<ButtonVariant, string> = {
  primary: 'bg-crimson-500 text-white hover:bg-crimson-400 disabled:bg-crimson-900 disabled:text-ink-500',
  secondary: 'bg-graphite-700 text-ink-100 border border-graphite-600 hover:bg-graphite-600 disabled:text-ink-600',
  ghost: 'text-ink-300 hover:bg-graphite-750 hover:text-ink-100 disabled:text-ink-600',
  danger: 'bg-transparent text-crimson-300 border border-crimson-600/60 hover:bg-crimson-900/50 disabled:text-ink-600',
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: 'sm' | 'md'
  loading?: boolean
  icon?: ReactNode
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading = false, icon, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cx(
        'inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors duration-150 disabled:cursor-not-allowed',
        size === 'sm' ? 'h-8 px-3 text-xs' : 'h-10 px-4 text-sm',
        buttonStyles[variant],
        className,
      )}
      {...rest}
    >
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : icon}
      {children}
    </button>
  )
})

// ------------------------------------------------------------------- Field

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
  hint?: string
}

export const Field = forwardRef<HTMLInputElement, FieldProps>(function Field({ label, error, hint, id, className, ...rest }, ref) {
  const generated = useId()
  const inputId = id ?? generated
  const describedBy = error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined

  return (
    <div className={cx('space-y-1.5', className)}>
      <label htmlFor={inputId} className="block text-xs font-medium tracking-wide text-ink-300 uppercase">
        {label}
      </label>
      <input
        ref={ref}
        id={inputId}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        className={cx(
          'block h-10 w-full rounded-md border bg-obsidian-900 px-3 text-sm text-ink-100 placeholder:text-ink-600',
          'transition-colors focus:border-crimson-500 focus:outline-none focus:ring-1 focus:ring-crimson-500',
          error ? 'border-crimson-500' : 'border-graphite-600',
        )}
        {...rest}
      />
      {error ? (
        <p id={`${inputId}-error`} className="text-xs text-crimson-300">
          {error}
        </p>
      ) : hint ? (
        <p id={`${inputId}-hint`} className="text-xs text-ink-500">
          {hint}
        </p>
      ) : null}
    </div>
  )
})

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string
}

export function Select({ label, className, id, children, ...rest }: SelectProps) {
  const generated = useId()
  const selectId = id ?? generated
  return (
    <div className={cx('space-y-1.5', className)}>
      {label ? (
        <label htmlFor={selectId} className="block text-xs font-medium tracking-wide text-ink-300 uppercase">
          {label}
        </label>
      ) : null}
      <select
        id={selectId}
        className="block h-9 w-full rounded-md border border-graphite-600 bg-obsidian-900 px-2.5 text-sm text-ink-100 focus:border-crimson-500 focus:outline-none focus:ring-1 focus:ring-crimson-500"
        {...rest}
      >
        {children}
      </select>
    </div>
  )
}

// -------------------------------------------------------------------- Card

export function Card({ title, actions, children, className }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={cx('rounded-lg border border-graphite-700 bg-graphite-800/80 shadow-[0_1px_0_0_rgb(255_255_255/0.03)_inset]', className)}>
      {title || actions ? (
        <header className="flex items-center justify-between gap-4 border-b border-graphite-700 px-5 py-3.5">
          {title ? <h2 className="text-sm font-semibold tracking-wide text-ink-100">{title}</h2> : <span />}
          {actions}
        </header>
      ) : null}
      <div className="p-5">{children}</div>
    </section>
  )
}

// ------------------------------------------------------------------- Badge

type Tone = 'neutral' | 'crimson' | 'ok' | 'warn' | 'info'

const toneStyles: Record<Tone, string> = {
  neutral: 'bg-graphite-700 text-ink-300 ring-graphite-600',
  crimson: 'bg-crimson-900/60 text-crimson-300 ring-crimson-600/40',
  ok: 'bg-signal-ok/10 text-signal-ok ring-signal-ok/30',
  warn: 'bg-signal-warn/10 text-signal-warn ring-signal-warn/30',
  info: 'bg-signal-info/10 text-signal-info ring-signal-info/30',
}

export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span className={cx('inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium tracking-wide ring-1 ring-inset', toneStyles[tone])}>
      {children}
    </span>
  )
}

// ------------------------------------------------------------------- Alert

const alertIcons = { error: XCircle, success: CheckCircle2, warning: AlertTriangle, info: Info }
const alertStyles = {
  error: 'border-crimson-600/50 bg-crimson-900/30 text-crimson-300',
  success: 'border-signal-ok/40 bg-signal-ok/10 text-signal-ok',
  warning: 'border-signal-warn/40 bg-signal-warn/10 text-signal-warn',
  info: 'border-signal-info/40 bg-signal-info/10 text-ink-300',
}

export function Alert({ kind = 'info', children }: { kind?: keyof typeof alertIcons; children: ReactNode }) {
  const Icon = alertIcons[kind]
  return (
    <div role={kind === 'error' ? 'alert' : 'status'} className={cx('flex gap-2.5 rounded-md border px-3.5 py-3 text-sm', alertStyles[kind])}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
      <div className="min-w-0">{children}</div>
    </div>
  )
}

// ----------------------------------------------------------------- Helpers

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center gap-2 text-sm text-ink-500">
      <Loader2 className="size-4 animate-spin" aria-hidden />
      <span>{label}…</span>
    </div>
  )
}

export function EmptyState({ icon, title, children }: { icon?: ReactNode; title: string; children?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 px-6 py-10 text-center">
      {icon ? <div className="mb-1 text-ink-600">{icon}</div> : null}
      <p className="text-sm font-medium text-ink-300">{title}</p>
      {children ? <div className="max-w-md text-sm text-ink-500">{children}</div> : null}
    </div>
  )
}

export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight text-ink-100">{title}</h1>
        {description ? <p className="mt-1 text-sm text-ink-500">{description}</p> : null}
      </div>
      {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
    </div>
  )
}

export function Mono({ children }: { children: ReactNode }) {
  return <span className="font-mono text-xs text-ink-300">{children}</span>
}
