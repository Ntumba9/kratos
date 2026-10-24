import type { ReactNode } from 'react'
import { Logo } from '../Logo'

export function AuthLayout({ title, subtitle, children, footer }: { title: string; subtitle?: string; children: ReactNode; footer?: ReactNode }) {
  return (
    <div className="kr-grid-bg relative flex min-h-full items-center justify-center px-4 py-12">
      <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgb(200_16_46/0.10),transparent_60%)]" />
      <div className="relative w-full max-w-sm animate-fade-in">
        <div className="mb-8 flex flex-col items-center gap-3 text-center">
          <Logo />
          <p className="text-[11px] tracking-[0.22em] text-ink-500 uppercase">Built to detect. Engineered to defend.</p>
        </div>
        <div className="rounded-lg border border-graphite-700 bg-graphite-800/90 p-6 shadow-2xl shadow-black/40 backdrop-blur">
          <h1 className="text-lg font-semibold text-ink-100">{title}</h1>
          {subtitle ? <p className="mt-1 text-sm text-ink-500">{subtitle}</p> : null}
          <div className="mt-6">{children}</div>
        </div>
        {footer ? <div className="mt-5 text-center text-sm text-ink-500">{footer}</div> : null}
      </div>
    </div>
  )
}
