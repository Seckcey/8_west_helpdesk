/**
 * Safeharbor component library (Phase 0) — the small atoms every screen
 * shares. Dark-mode-first; colors come from tokens via Tailwind utilities.
 */

import type { Priority, TicketStatus, User } from "../lib/types";
import { relTime, slaInfo } from "../lib/store";
import type { Ticket } from "../lib/types";
import { clientById, userById } from "../lib/data";

// ---------- status ----------
export const STATUS_META: Record<
  TicketStatus,
  { label: string; dot: string; text: string }
> = {
  open: { label: "Open", dot: "bg-blue-500", text: "text-blue-400" },
  in_progress: { label: "In Progress", dot: "bg-cyan-300", text: "text-cyan-300" },
  waiting: { label: "Waiting", dot: "bg-gold-400", text: "text-gold-400" },
  resolved: { label: "Resolved", dot: "bg-mint-300", text: "text-mint-300" },
};

export const STATUS_ORDER: TicketStatus[] = ["open", "in_progress", "waiting", "resolved"];

export function StatusChip({ status }: { status: TicketStatus }) {
  const meta = STATUS_META[status];
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full border border-line bg-white/5 px-2.5 py-1 text-xs">
      <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} />
      <span className={meta.text}>{meta.label}</span>
    </span>
  );
}

// ---------- priority ----------
export const PRIORITY_ORDER: Priority[] = ["low", "normal", "high", "urgent"];
export const PRIORITY_META: Record<Priority, { label: string; bars: number; color: string }> = {
  low: { label: "Low", bars: 1, color: "bg-muted/60" },
  normal: { label: "Normal", bars: 2, color: "bg-blue-400" },
  high: { label: "High", bars: 3, color: "bg-gold-400" },
  urgent: { label: "Urgent", bars: 4, color: "bg-rose-400" },
};

export function PriorityGlyph({ priority, withLabel = false }: { priority: Priority; withLabel?: boolean }) {
  const meta = PRIORITY_META[priority];
  return (
    <span className="inline-flex items-center gap-1.5" title={`Priority: ${meta.label}`}>
      <span className="flex items-end gap-[2px]" aria-hidden>
        {[0, 1, 2, 3].map((i) => (
          <span
            key={i}
            className={`w-[3px] rounded-sm ${i < meta.bars ? meta.color : "bg-white/15"}`}
            style={{ height: 5 + i * 3 }}
          />
        ))}
      </span>
      {withLabel && <span className="text-xs text-muted">{meta.label}</span>}
    </span>
  );
}

// ---------- SLA lamp ----------
const SLA_COLOR = {
  healthy: "text-mint-300",
  at_risk: "text-gold-400",
  breached: "text-rose-400",
  met: "text-mint-300/70",
} as const;

export function SlaLamp({ ticket }: { ticket: Ticket }) {
  const info = slaInfo(ticket);
  return (
    <span className={`inline-flex items-center gap-1.5 text-xs tabular-nums ${SLA_COLOR[info.state]}`}>
      <span
        className={`h-1.5 w-1.5 rounded-full bg-current ${
          info.state === "at_risk" || info.state === "breached" ? "pulse-soft" : ""
        }`}
      />
      {info.state === "met" ? "SLA met" : info.label}
    </span>
  );
}

// ---------- avatar ----------
export function Avatar({ user, size = 26 }: { user: User | undefined; size?: number }) {
  if (!user) {
    return (
      <span
        className="inline-flex items-center justify-center rounded-full border border-dashed border-line text-[10px] text-muted"
        style={{ width: size, height: size }}
        title="Unassigned"
      >
        —
      </span>
    );
  }
  return (
    <span
      className="inline-flex items-center justify-center rounded-full text-[10px] font-semibold text-navy-950"
      style={{ width: size, height: size, background: user.color }}
      title={user.name}
    >
      {user.initials}
    </span>
  );
}

// ---------- ticket row ----------
export function TicketRow({
  ticket,
  selected,
  onOpen,
}: {
  ticket: Ticket;
  selected: boolean;
  onOpen: () => void;
}) {
  const client = clientById(ticket.clientId);
  const assignee = userById(ticket.assigneeId);
  return (
    <button
      onClick={onOpen}
      data-selected={selected}
      className={`row-in grid w-full grid-cols-[22px_64px_1fr_auto_auto_110px_52px] items-center gap-3 border-b border-line/60 px-4 py-3 text-left transition-colors duration-100 ${
        selected ? "bg-blue-500/10 ring-1 ring-inset ring-blue-500/40" : "hover:bg-white/[0.03]"
      }`}
    >
      <PriorityGlyph priority={ticket.priority} />
      <span className="text-xs tabular-nums text-muted">#{ticket.number}</span>
      <span className="min-w-0">
        <span className="block truncate text-[13.5px] text-ink">{ticket.subject}</span>
        <span className="block truncate text-xs text-muted">
          {client?.name} · {ticket.channel}
        </span>
      </span>
      <StatusChip status={ticket.status} />
      <Avatar user={assignee} />
      <SlaLamp ticket={ticket} />
      <span className="text-right text-xs tabular-nums text-muted" title={`Opened ${new Date(ticket.createdAt).toLocaleString()}`}>{relTime(ticket.createdAt)}</span>
    </button>
  );
}

// ---------- section heading ----------
export function SectionLabel({ children }: { children: string }) {
  return (
    <div className="px-1 pb-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-muted">
      {children}
    </div>
  );
}
