import { ChevronsUpDown, LogOut, Menu, Plus, X } from 'lucide-react'
import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router'
import { useAuth } from '../../lib/auth'
import { useOrganisation } from '../../lib/organisation'
import { Logo } from '../Logo'
import { Badge, cx } from '../ui'
import { navigation } from './navigation'

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { can } = useOrganisation()

  return (
    <nav aria-label="Primary" className="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-4">
      {navigation.map((section) => {
        const items = section.items.filter((item) => !item.permission || can(item.permission))
        if (items.length === 0) return null

        return (
          <div key={section.label}>
            <p className="px-2 pb-2 text-[10px] font-semibold tracking-[0.2em] text-ink-600 uppercase">{section.label}</p>
            <ul className="space-y-0.5">
              {items.map((item) => {
                const Icon = item.icon
                return (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={item.to === '/'}
                      onClick={onNavigate}
                      className={({ isActive }) =>
                        cx(
                          'group flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors',
                          isActive
                            ? 'bg-graphite-700 text-ink-100 shadow-[inset_2px_0_0_0_var(--color-crimson-500)]'
                            : 'text-ink-500 hover:bg-graphite-750 hover:text-ink-100',
                        )
                      }
                    >
                      <Icon className="size-4 shrink-0" aria-hidden />
                      <span className="flex-1 truncate">{item.label}</span>
                      {item.plannedPhase ? <span className="font-mono text-[10px] text-ink-600">P{item.plannedPhase}</span> : null}
                    </NavLink>
                  </li>
                )
              })}
            </ul>
          </div>
        )
      })}
    </nav>
  )
}

function OrganisationSwitcher() {
  const { active, memberships, select } = useOrganisation()
  const navigate = useNavigate()

  return (
    <div className="relative flex min-w-0 items-center gap-2">
      <label htmlFor="org-switcher" className="sr-only">
        Organisation
      </label>
      <div className="relative min-w-0">
        <select
          id="org-switcher"
          value={active?.organisation.id ?? ''}
          onChange={(event) => {
            if (event.target.value === '__new') navigate('/organisations/new')
            else select(event.target.value)
          }}
          className="h-9 max-w-[16rem] appearance-none truncate rounded-md border border-graphite-600 bg-graphite-800 py-0 pr-8 pl-3 text-sm text-ink-100 focus:border-crimson-500 focus:outline-none"
        >
          {memberships.map((m) => (
            <option key={m.organisation.id} value={m.organisation.id}>
              {m.organisation.name}
            </option>
          ))}
          <option value="__new">+ New organisation…</option>
        </select>
        <ChevronsUpDown className="pointer-events-none absolute top-1/2 right-2.5 size-3.5 -translate-y-1/2 text-ink-500" aria-hidden />
      </div>
      {active ? <Badge>{active.role.name}</Badge> : null}
    </div>
  )
}

/** Demonstration tenants are always labelled; their data is synthetic. */
function DemoBanner() {
  const { active } = useOrganisation()
  if (!active?.organisation.is_demo) return null
  return (
    <div role="note" className="border-b border-signal-warn/30 bg-signal-warn/10 px-4 py-1.5 text-center text-xs text-signal-warn">
      Demonstration data — this organisation contains synthetic accounts and events for evaluation only.
    </div>
  )
}

export function AppShell() {
  const { principal, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)

  const handleLogout = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <div className="flex h-full">
      {/* Desktop sidebar */}
      <aside className="hidden w-60 shrink-0 flex-col border-r border-graphite-700 bg-obsidian-900 lg:flex">
        <div className="flex h-14 items-center border-b border-graphite-700 px-4">
          <Logo />
        </div>
        <Sidebar />
        <div className="border-t border-graphite-700 px-4 py-3 text-[10px] tracking-wide text-ink-600">KRATOS · Phase 3 build</div>
      </aside>

      {/* Mobile drawer */}
      {mobileOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <div className="absolute inset-0 bg-black/60" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 flex w-64 flex-col border-r border-graphite-700 bg-obsidian-900 animate-fade-in">
            <div className="flex h-14 items-center justify-between border-b border-graphite-700 px-4">
              <Logo />
              <button type="button" onClick={() => setMobileOpen(false)} className="text-ink-500 hover:text-ink-100" aria-label="Close navigation">
                <X className="size-5" />
              </button>
            </div>
            <Sidebar onNavigate={() => setMobileOpen(false)} />
          </aside>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 shrink-0 items-center gap-3 border-b border-graphite-700 bg-obsidian-900/80 px-4 backdrop-blur">
          <button type="button" className="text-ink-500 hover:text-ink-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Open navigation">
            <Menu className="size-5" />
          </button>
          <OrganisationSwitcher />
          <div className="ml-auto flex items-center gap-3">
            <button
              type="button"
              onClick={() => navigate('/organisations/new')}
              className="hidden items-center gap-1 text-xs text-ink-500 hover:text-ink-100 sm:inline-flex"
            >
              <Plus className="size-3.5" aria-hidden /> Organisation
            </button>
            <div className="hidden text-right sm:block">
              <div className="text-sm leading-tight text-ink-100">{principal?.user.name}</div>
              <div className="text-[11px] leading-tight text-ink-500">{principal?.user.email}</div>
            </div>
            <button
              type="button"
              onClick={handleLogout}
              className="inline-flex size-9 items-center justify-center rounded-md border border-graphite-600 text-ink-500 transition-colors hover:border-crimson-600 hover:text-crimson-300"
              aria-label="Sign out"
              title="Sign out"
            >
              <LogOut className="size-4" />
            </button>
          </div>
        </header>
        <DemoBanner />
        <main className="kr-grid-bg flex-1 overflow-y-auto">
          <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
