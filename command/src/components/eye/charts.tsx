import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { OverviewStats, Severity } from '../../lib/types'
import { SEVERITIES } from '../../lib/types'
import { severityColor } from './badges'

/*
 * Chart conventions (dataviz method): thin marks, one y-axis, recessive grid,
 * legend whenever there are >= 2 series, hover tooltip on every chart, and a
 * table view so nothing depends on reading colour.
 */

const axisTick = { fill: 'var(--color-ink-500)', fontSize: 11 }
const gridStroke = 'rgb(255 255 255 / 0.06)'

/** Legend labels use ink text; the swatch beside them carries identity. */
const legendText = (value: string) => <span style={{ color: 'var(--color-ink-300)' }}>{value}</span>

const hourFmt = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' })
const fmtTime = (iso: string) => hourFmt.format(new Date(iso))

function TooltipBox({ title, rows }: { title: string; rows: Array<{ label: string; value: number; color: string }> }) {
  return (
    <div className="rounded-md border border-graphite-600 bg-obsidian-900/95 px-3 py-2 text-xs shadow-xl">
      <div className="mb-1.5 font-medium text-ink-100">{title}</div>
      {rows.map((row) => (
        <div key={row.label} className="flex items-center justify-between gap-6 py-0.5">
          <span className="flex items-center gap-1.5 text-ink-300 capitalize">
            <span className="size-2 rounded-sm" style={{ backgroundColor: row.color }} aria-hidden />
            {row.label}
          </span>
          <span className="font-mono text-ink-100 tabular-nums">{row.value.toLocaleString()}</span>
        </div>
      ))}
    </div>
  )
}

function TableToggle({ showTable, onToggle }: { showTable: boolean; onToggle: () => void }) {
  return (
    <button type="button" onClick={onToggle} className="text-xs text-ink-500 hover:text-ink-100" aria-pressed={showTable}>
      {showTable ? 'Show chart' : 'Show table'}
    </button>
  )
}

// --------------------------------------------------------- severity timeline

/** Hourly events by severity, last 24 h (stacked bars, ordinal colour). */
export function SeverityTimeline({ data }: { data: OverviewStats['timeline'] }) {
  const [showTable, setShowTable] = useState(false)
  const total = data.reduce((sum, h) => sum + SEVERITIES.reduce((s, sev) => s + h[sev], 0), 0)

  return (
    <div>
      <div className="mb-2 flex justify-end">
        <TableToggle showTable={showTable} onToggle={() => setShowTable((v) => !v)} />
      </div>
      {showTable ? (
        <div className="max-h-72 overflow-auto">
          <table className="w-full text-right text-xs">
            <thead className="sticky top-0 bg-graphite-800 text-ink-500">
              <tr>
                <th className="py-1 text-left font-medium">Hour</th>
                {SEVERITIES.map((s) => (
                  <th key={s} className="py-1 font-medium capitalize">
                    {s}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="font-mono text-ink-300">
              {data.map((h) => (
                <tr key={h.hour} className="border-t border-graphite-700">
                  <td className="py-1 text-left">{fmtTime(h.hour)}</td>
                  {SEVERITIES.map((s) => (
                    <td key={s} className="py-1">
                      {h[s]}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="h-64" role="img" aria-label={`Events per hour by severity over the last 24 hours, ${total} in total`}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: -16 }} barCategoryGap={2}>
              <CartesianGrid stroke={gridStroke} vertical={false} />
              <XAxis dataKey="hour" tickFormatter={fmtTime} tick={axisTick} axisLine={false} tickLine={false} minTickGap={24} />
              <YAxis allowDecimals={false} tick={axisTick} axisLine={false} tickLine={false} width={44} />
              <Tooltip
                cursor={{ fill: 'rgb(255 255 255 / 0.04)' }}
                content={({ active, payload, label }) =>
                  active && payload?.length ? (
                    <TooltipBox
                      title={fmtTime(String(label))}
                      rows={[...SEVERITIES].reverse().map((s) => ({ label: s, value: Number(payload[0]?.payload?.[s] ?? 0), color: severityColor[s] }))}
                    />
                  ) : null
                }
              />
              <Legend
                iconType="square"
                iconSize={8}
                wrapperStyle={{ fontSize: 11 }}
                formatter={legendText}
                // Ordinal order (most severe first), not alphabetical.
                itemSorter={(item) => -SEVERITIES.indexOf(String(item.dataKey) as Severity)}
              />
              {SEVERITIES.map((s, i) => (
                <Bar
                  key={s}
                  dataKey={s}
                  name={s.charAt(0).toUpperCase() + s.slice(1)}
                  stackId="sev"
                  fill={severityColor[s]}
                  // 2px surface gap between stacked segments
                  stroke="var(--color-graphite-800)"
                  strokeWidth={1}
                  radius={i === SEVERITIES.length - 1 ? [3, 3, 0, 0] : 0}
                  isAnimationActive={false}
                />
              ))}
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  )
}

// ------------------------------------------------------------- throughput

/** Events received vs processed per minute, last hour (two lines, one axis). */
export function ThroughputChart({ data }: { data: OverviewStats['throughput']['per_minute'] }) {
  const [showTable, setShowTable] = useState(false)

  return (
    <div>
      <div className="mb-2 flex justify-end">
        <TableToggle showTable={showTable} onToggle={() => setShowTable((v) => !v)} />
      </div>
      {showTable ? (
        <div className="max-h-56 overflow-auto">
          <table className="w-full text-right text-xs">
            <thead className="sticky top-0 bg-graphite-800 text-ink-500">
              <tr>
                <th className="py-1 text-left font-medium">Minute</th>
                <th className="py-1 font-medium">Received</th>
                <th className="py-1 font-medium">Processed</th>
              </tr>
            </thead>
            <tbody className="font-mono text-ink-300">
              {[...data].reverse().map((m) => (
                <tr key={m.minute} className="border-t border-graphite-700">
                  <td className="py-1 text-left">{fmtTime(m.minute)}</td>
                  <td className="py-1">{m.received}</td>
                  <td className="py-1">{m.processed}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <>
        <div className="h-48" role="img" aria-label="Events received and processed per minute over the last hour">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ top: 4, right: 8, bottom: 0, left: -16 }}>
              <CartesianGrid stroke={gridStroke} vertical={false} />
              <XAxis dataKey="minute" tickFormatter={fmtTime} tick={axisTick} axisLine={false} tickLine={false} minTickGap={32} />
              <YAxis allowDecimals={false} tick={axisTick} axisLine={false} tickLine={false} width={44} />
              <Tooltip
                cursor={{ stroke: 'var(--color-ink-600)', strokeWidth: 1 }}
                content={({ active, payload, label }) =>
                  active && payload?.length ? (
                    <TooltipBox
                      title={fmtTime(String(label))}
                      rows={[
                        { label: 'received', value: Number(payload[0]?.payload?.received ?? 0), color: 'var(--color-series-1)' },
                        { label: 'processed', value: Number(payload[0]?.payload?.processed ?? 0), color: 'var(--color-series-2)' },
                      ]}
                    />
                  ) : null
                }
              />
              <Legend iconType="plainline" iconSize={12} wrapperStyle={{ fontSize: 11 }} formatter={legendText} itemSorter={(item) => (item.dataKey === 'received' ? 0 : 1)} />
              <Line type="monotone" dataKey="received" name="Received" stroke="var(--color-series-1)" strokeWidth={2} dot={false} activeDot={{ r: 4, strokeWidth: 2, stroke: 'var(--color-graphite-800)' }} isAnimationActive={false} />
              {/* Surface-coloured halo keeps the dashed line legible where the two overlap. */}
              <Line type="monotone" dataKey="processed" legendType="none" tooltipType="none" stroke="var(--color-graphite-800)" strokeWidth={5} dot={false} activeDot={false} isAnimationActive={false} />
              <Line type="monotone" dataKey="processed" name="Processed" stroke="var(--color-series-2)" strokeWidth={2} dot={false} strokeDasharray="5 3" activeDot={{ r: 4, strokeWidth: 2, stroke: 'var(--color-graphite-800)' }} isAnimationActive={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
        <p className="mt-1 text-[11px] text-ink-600">When the lines coincide, processing is keeping pace with ingestion.</p>
        </>
      )}
    </div>
  )
}

// --------------------------------------------------- severity distribution

/** Last-24h counts per severity as labelled horizontal bars (no chart library needed). */
export function SeverityDistribution({ counts }: { counts: Record<Severity, number> }) {
  const max = Math.max(1, ...SEVERITIES.map((s) => counts[s]))

  return (
    <ul className="space-y-2.5" aria-label="Events in the last 24 hours by severity">
      {[...SEVERITIES].reverse().map((s) => (
        <li key={s} className="grid grid-cols-[5rem_1fr_3.5rem] items-center gap-3 text-xs" title={`${s}: ${counts[s]}`}>
          <span className="text-ink-300 capitalize">{s}</span>
          <span className="h-2 rounded-r-sm bg-graphite-700">
            <span className="block h-2 rounded-r-sm" style={{ width: `${(counts[s] / max) * 100}%`, backgroundColor: severityColor[s] }} />
          </span>
          <span className="text-right font-mono text-ink-100 tabular-nums">{counts[s].toLocaleString()}</span>
        </li>
      ))}
    </ul>
  )
}

/** Single series: every bar uses slot 1; the title names it, so no legend. */
export function TopEventTypes({ data }: { data: OverviewStats['top_event_types'] }) {
  const max = Math.max(1, ...data.map((d) => d.count))

  return (
    <ul className="space-y-2.5" aria-label="Most frequent event types in the last 24 hours">
      {data.map((d) => (
        <li key={d.event_type} className="grid grid-cols-[minmax(0,10rem)_1fr_3.5rem] items-center gap-3 text-xs" title={`${d.event_type}: ${d.count}`}>
          <span className="truncate font-mono text-ink-300">{d.event_type}</span>
          <span className="h-2 rounded-r-sm bg-graphite-700">
            <span className="block h-2 rounded-r-sm bg-series-1" style={{ width: `${(d.count / max) * 100}%` }} />
          </span>
          <span className="text-right font-mono text-ink-100 tabular-nums">{d.count.toLocaleString()}</span>
        </li>
      ))}
    </ul>
  )
}


// ------------------------------------------------------------ intelligence

/** Hourly analysed events flagged suspicious/malicious vs anomaly-model hits (24 h). */
export function AnomalyTrendChart({ data }: { data: Array<{ hour: string; anomalous: number; flagged: number; analysed: number }> }) {
  const [showTable, setShowTable] = useState(false)

  return (
    <div>
      <div className="mb-2 flex justify-end">
        <TableToggle showTable={showTable} onToggle={() => setShowTable((v) => !v)} />
      </div>
      {showTable ? (
        <div className="max-h-56 overflow-auto">
          <table className="w-full text-right text-xs">
            <thead className="sticky top-0 bg-graphite-800 text-ink-500">
              <tr>
                <th className="py-1 text-left font-medium">Hour</th>
                <th className="py-1 font-medium">Analysed</th>
                <th className="py-1 font-medium">Flagged</th>
                <th className="py-1 font-medium">Anomalous</th>
              </tr>
            </thead>
            <tbody className="font-mono text-ink-300">
              {[...data].reverse().map((h) => (
                <tr key={h.hour} className="border-t border-graphite-700">
                  <td className="py-1 text-left">{fmtTime(h.hour)}</td>
                  <td className="py-1">{h.analysed}</td>
                  <td className="py-1">{h.flagged}</td>
                  <td className="py-1">{h.anomalous}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="h-52" role="img" aria-label="Events flagged suspicious or malicious, and anomaly-model hits, per hour over the last 24 hours">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ top: 4, right: 8, bottom: 0, left: -16 }}>
              <CartesianGrid stroke={gridStroke} vertical={false} />
              <XAxis dataKey="hour" tickFormatter={fmtTime} tick={axisTick} axisLine={false} tickLine={false} minTickGap={32} />
              <YAxis allowDecimals={false} tick={axisTick} axisLine={false} tickLine={false} width={44} />
              <Tooltip
                cursor={{ stroke: 'var(--color-ink-600)', strokeWidth: 1 }}
                content={({ active, payload, label }) =>
                  active && payload?.length ? (
                    <TooltipBox
                      title={fmtTime(String(label))}
                      rows={[
                        { label: 'flagged', value: Number(payload[0]?.payload?.flagged ?? 0), color: 'var(--color-series-1)' },
                        { label: 'anomalous', value: Number(payload[0]?.payload?.anomalous ?? 0), color: 'var(--color-series-2)' },
                      ]}
                    />
                  ) : null
                }
              />
              <Legend iconType="plainline" iconSize={12} wrapperStyle={{ fontSize: 11 }} formatter={legendText} itemSorter={(item) => (item.dataKey === 'flagged' ? 0 : 1)} />
              <Line type="monotone" dataKey="flagged" name="Suspicious or malicious" stroke="var(--color-series-1)" strokeWidth={2} dot={false} isAnimationActive={false} />
              <Line type="monotone" dataKey="anomalous" name="Anomaly model ≥ p99" stroke="var(--color-series-2)" strokeWidth={2} strokeDasharray="5 3" dot={false} isAnimationActive={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  )
}

const verdictBand = (from: number) => (from >= 70 ? 'malicious' : from >= 40 ? 'suspicious' : 'benign')
const verdictColor = { benign: 'var(--color-graphite-500)', suspicious: 'var(--color-signal-warn)', malicious: 'var(--color-crimson-400)' } as const

/** Risk scores in 10-point buckets; bars carry the verdict band (status colour + text legend). */
export function RiskHistogram({ data }: { data: Array<{ from: number; to: number; count: number }> }) {
  const max = Math.max(1, ...data.map((d) => d.count))

  return (
    <div>
      <div className="flex h-40 items-end gap-0.5" role="img" aria-label="Distribution of risk scores in the last 24 hours">
        {data.map((d) => (
          <div key={d.from} className="flex h-full flex-1 flex-col justify-end" title={`Risk ${d.from}–${d.to}: ${d.count} events (${verdictBand(d.from)})`}>
            <span className="mb-1 text-center font-mono text-[10px] text-ink-500">{d.count || ''}</span>
            <span className="block rounded-t-sm" style={{ height: `${(d.count / max) * 100}%`, minHeight: d.count ? 2 : 0, backgroundColor: verdictColor[verdictBand(d.from)] }} />
          </div>
        ))}
      </div>
      <div className="mt-1 flex gap-0.5 font-mono text-[10px] text-ink-600">
        {data.map((d) => (
          <span key={d.from} className="flex-1 text-center">
            {d.from}
          </span>
        ))}
      </div>
      <ul className="mt-3 flex flex-wrap gap-4 text-[11px] text-ink-300" aria-label="Legend">
        {(['benign', 'suspicious', 'malicious'] as const).map((v) => (
          <li key={v} className="flex items-center gap-1.5 capitalize">
            <span className="size-2 rounded-sm" style={{ backgroundColor: verdictColor[v] }} aria-hidden />
            {v} {v === 'benign' ? '< 40' : v === 'suspicious' ? '40–69' : '≥ 70'}
          </li>
        ))}
      </ul>
    </div>
  )
}
