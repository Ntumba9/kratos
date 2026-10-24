import { cx } from './ui'

/**
 * Original KRATOS mark: a hexagonal bulwark (defence) enclosing a stylised
 * "K" whose arms converge on a crimson detection point.
 */
export function LogoMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" fill="none" aria-hidden className={cx('shrink-0', className)}>
      <path d="M32 3 57 17.5v29L32 61 7 46.5v-29L32 3Z" className="fill-obsidian-900 stroke-crimson-500" strokeWidth="3" />
      <path d="M22 18v28M22 32l14-14M22 32l14 14" className="stroke-ink-100" strokeWidth="4.5" strokeLinecap="square" />
      <path d="M40 32h6" className="stroke-crimson-500" strokeWidth="4.5" strokeLinecap="square" />
    </svg>
  )
}

export function Logo({ compact = false }: { compact?: boolean }) {
  return (
    <div className="flex items-center gap-2.5">
      <LogoMark className="size-8" />
      {compact ? null : (
        <div className="leading-none">
          <div className="text-[15px] font-semibold tracking-[0.32em] text-ink-100">KRATOS</div>
          <div className="mt-1 text-[10px] tracking-[0.18em] text-ink-500 uppercase">Command</div>
        </div>
      )}
    </div>
  )
}
