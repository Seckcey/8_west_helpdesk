/**
 * The Queue — home. One calm, dense list; keyboard navigable end to end.
 *
 * Keys: j/k or arrows move · Enter/o open · s status · p priority ·
 *       a assign · e start timer · 1-4 filter
 */

import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useStore } from "../lib/store";
import { useRequiredSession } from "../lib/session";
import { useKeyboard } from "../lib/shortcuts";
import { TicketRow, STATUS_ORDER, STATUS_META, PRIORITY_ORDER, PRIORITY_META } from "../components/ui";
import type { TicketStatus } from "../lib/types";

type Filter = "all" | TicketStatus;
const FILTERS: Array<{ id: Filter; label: string }> = [
  { id: "all", label: "All" },
  { id: "open", label: "Open" },
  { id: "in_progress", label: "In Progress" },
  { id: "waiting", label: "Waiting" },
  { id: "resolved", label: "Resolved" },
];

export function QueueScreen() {
  const { tickets, updateTicket, startTimer } = useStore();
  const { user } = useRequiredSession();
  const navigate = useNavigate();
  const [filter, setFilter] = useState<Filter>("all");
  const [selected, setSelected] = useState(0);

  const visible = useMemo(() => {
    const list = tickets
      .filter((t) => filter === "all" || t.status === filter)
      .sort((a, b) => {
        // Stable under edits: unresolved first, urgent→low, then soonest SLA.
        // (updatedAt is deliberately NOT a sort key — a row must never jump
        // out from under the tech's keyboard selection.)
        const ra = a.status === "resolved" ? 1 : 0;
        const rb = b.status === "resolved" ? 1 : 0;
        if (ra !== rb) return ra - rb;
        const pa = PRIORITY_ORDER.indexOf(a.priority);
        const pb = PRIORITY_ORDER.indexOf(b.priority);
        if (pa !== pb) return pb - pa;
        return a.slaDueAt.localeCompare(b.slaDueAt);
      });
    return list;
  }, [tickets, filter]);

  const clamp = (i: number) => Math.max(0, Math.min(i, visible.length - 1));
  const current = visible[selected];

  const open = (i: number) => {
    const t = visible[i];
    if (t) navigate(`/tickets/${t.number}`);
  };

  const cycle = <T,>(order: T[], value: T, dir = 1): T =>
    order[(order.indexOf(value) + dir + order.length) % order.length];

  useKeyboard({
    j: () => setSelected((i) => clamp(i + 1)),
    k: () => setSelected((i) => clamp(i - 1)),
    arrowdown: () => setSelected((i) => clamp(i + 1)),
    arrowup: () => setSelected((i) => clamp(i - 1)),
    enter: () => open(selected),
    o: () => open(selected),
    s: () => {
      if (!current) return;
      const next = cycle(STATUS_ORDER, current.status);
      updateTicket(current.id, { status: next }, `Status → ${STATUS_META[next].label}`);
    },
    p: () => {
      if (!current) return;
      const next = cycle(PRIORITY_ORDER, current.priority);
      updateTicket(current.id, { priority: next }, `Priority → ${PRIORITY_META[next].label}`);
    },
    "shift+p": () => {
      if (!current) return;
      const next = cycle(PRIORITY_ORDER, current.priority, -1);
      updateTicket(current.id, { priority: next }, `Priority → ${PRIORITY_META[next].label}`);
    },
    a: () => {
      if (!current) return;
      const mine = current.assigneeId === user.id;
      updateTicket(
        current.id,
        { assigneeId: mine ? null : user.id },
        mine ? "Unassigned" : `Assigned to ${user.name.split(" ")[0]}`,
      );
    },
    e: () => current && startTimer(current.id),
    "1": () => setFilter("all"),
    "2": () => setFilter("open"),
    "3": () => setFilter("in_progress"),
    "4": () => setFilter("waiting"),
    "5": () => setFilter("resolved"),
  });

  const counts = useMemo(() => {
    const c: Record<string, number> = { all: tickets.length };
    for (const t of tickets) c[t.status] = (c[t.status] ?? 0) + 1;
    return c;
  }, [tickets]);

  return (
    <div className="mx-auto max-w-6xl px-5 py-5">
      {/* header */}
      <div className="mb-4 flex items-end justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-ink">Queue</h1>
          <p className="mt-0.5 text-[12.5px] text-muted">
            {counts.open ?? 0} open · {(counts.in_progress ?? 0) + (counts.waiting ?? 0)} active · {counts.resolved ?? 0} resolved
          </p>
        </div>
        <div className="flex items-center gap-1">
          {FILTERS.map((f, i) => (
            <button
              key={f.id}
              onClick={() => { setFilter(f.id); setSelected(0); }}
              className={`rounded-full px-3 py-1 text-[12px] transition-colors ${
                filter === f.id
                  ? "bg-blue-500/20 text-cyan-300"
                  : "text-muted hover:text-ink"
              }`}
            >
              {f.label}
              <span className="ml-1.5 text-muted/60">{counts[f.id] ?? 0}</span>
              <kbd className="kbd ml-1.5 hidden md:inline-flex">{i + 1}</kbd>
            </button>
          ))}
        </div>
      </div>

      {/* list */}
      <div className="card overflow-hidden">
        <div className="grid grid-cols-[22px_64px_1fr_auto_auto_110px_52px] items-center gap-3 border-b border-line bg-white/[0.02] px-4 py-2 text-[10.5px] font-semibold uppercase tracking-[0.12em] text-muted/80">
          <span>Pri</span><span>#</span><span>Ticket</span>
          <span>Status</span><span>Tech</span><span>SLA</span>
          <span className="text-right">Age</span>
        </div>
        {visible.length === 0 ? (
          <div className="flex flex-col items-center gap-2 px-4 py-16 text-center">
            <img src="/brand/safeharbor-mark.svg" alt="" className="h-14 w-14 opacity-60" />
            <p className="text-[13px] text-muted">Nothing here. The harbor is calm.</p>
          </div>
        ) : (
          visible.map((t, i) => (
            <TicketRow
              key={t.id}
              ticket={t}
              selected={i === selected}
              onOpen={() => open(i)}
            />
          ))
        )}
      </div>

      <p className="mt-3 flex items-center gap-3 px-1 text-[11.5px] text-muted/80">
        <span><kbd className="kbd">j</kbd>/<kbd className="kbd">k</kbd> move</span>
        <span><kbd className="kbd">↵</kbd> open</span>
        <span><kbd className="kbd">s</kbd> status</span>
        <span><kbd className="kbd">p</kbd> priority</span>
        <span><kbd className="kbd">a</kbd> assign to me</span>
        <span><kbd className="kbd">e</kbd> start timer</span>
        <span className="ml-auto">⌘K for everything else</span>
      </p>
    </div>
  );
}
