/**
 * Ticket detail — conversation center, context right. A tech should be
 * able to resolve a ticket without ever leaving this view.
 *
 * Keys: s status · p priority · a assign to me · e timer · r reply · esc back
 */

import { useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useStore, relTime, slaInfo } from "../lib/store";
import { useRequiredSession } from "../lib/session";
import { useKeyboard } from "../lib/shortcuts";
import { clientById, userById, USERS } from "../lib/data";
import {
  Avatar, PriorityGlyph, SectionLabel, StatusChip,
  STATUS_META, STATUS_ORDER, PRIORITY_META, PRIORITY_ORDER,
} from "../components/ui";
import type { Message, Ticket } from "../lib/types";

function Bubble({ msg }: { msg: Message }) {
  if (msg.kind === "system") {
    return (
      <div className="py-1 text-center text-[11.5px] italic text-muted/80">
        {msg.body} · {relTime(msg.at)}
      </div>
    );
  }
  if (msg.kind === "note") {
    return (
      <div className="rounded-md border-l-2 border-gold-400 bg-gold-400/[0.07] px-3.5 py-2.5">
        <div className="mb-1 flex items-center gap-2 text-[11.5px]">
          <span className="font-medium text-gold-400">{msg.authorName}</span>
          <span className="text-muted/70">internal note · {relTime(msg.at)}</span>
        </div>
        <p className="text-[13px] leading-relaxed text-muted-strong">{msg.body}</p>
      </div>
    );
  }
  const isTech = msg.kind === "tech";
  return (
    <div className={`card max-w-[85%] px-3.5 py-2.5 ${isTech ? "ml-auto border-blue-500/25 bg-blue-500/[0.07]" : ""}`}>
      <div className="mb-1 flex items-center gap-2 text-[11.5px]">
        <span className={`font-medium ${isTech ? "text-cyan-300" : "text-ink"}`}>{msg.authorName}</span>
        <span className="text-muted/70">{isTech ? "tech" : "client"} · {relTime(msg.at)}</span>
      </div>
      <p className="text-[13px] leading-relaxed text-muted-strong">{msg.body}</p>
    </div>
  );
}

function CycleButton({
  label, value, onCycle, kbd, children,
}: {
  label: string; value: string; onCycle: () => void; kbd: string; children: JSX.Element;
}) {
  return (
    <button
      onClick={onCycle}
      className="group flex w-full items-center justify-between rounded-sm border border-line bg-white/[0.02] px-3 py-2 text-left transition-colors hover:border-blue-500/40"
      title={`${label}: ${value} — click or press ${kbd} to change`}
    >
      <span>
        <span className="block text-[10.5px] uppercase tracking-[0.12em] text-muted/70">{label}</span>
        <span className="mt-0.5 block">{children}</span>
      </span>
      <kbd className="kbd opacity-0 transition-opacity group-hover:opacity-100">{kbd}</kbd>
    </button>
  );
}

export function TicketScreen() {
  const { number } = useParams();
  const navigate = useNavigate();
  const { user } = useRequiredSession();
  const { tickets, updateTicket, addMessage, timer, startTimer, stopTimer } = useStore();
  const ticket: Ticket | undefined = tickets.find((t) => t.number === Number(number));
  const [draft, setDraft] = useState("");
  const replyRef = useRef<HTMLTextAreaElement>(null);

  const cycle = <T,>(order: T[], value: T): T =>
    order[(order.indexOf(value) + 1) % order.length];

  useKeyboard(
    {
      escape: () => navigate("/"),
      s: () => {
        if (!ticket) return;
        const next = cycle(STATUS_ORDER, ticket.status);
        updateTicket(ticket.id, { status: next }, `Status → ${STATUS_META[next].label}`);
      },
      p: () => {
        if (!ticket) return;
        const next = cycle(PRIORITY_ORDER, ticket.priority);
        updateTicket(ticket.id, { priority: next }, `Priority → ${PRIORITY_META[next].label}`);
      },
      a: () => {
        if (!ticket) return;
        const mine = ticket.assigneeId === user.id;
        updateTicket(ticket.id, { assigneeId: mine ? null : user.id }, mine ? "Unassigned" : "Assigned to you");
      },
      e: () => ticket && (timer?.ticketId === ticket.id ? stopTimer() : startTimer(ticket.id)),
      r: () => replyRef.current?.focus(),
    },
    Boolean(ticket),
  );

  if (!ticket) {
    return (
      <div className="mx-auto max-w-3xl px-5 py-16 text-center">
        <p className="text-muted">Ticket #{number} drifted out of the harbor.</p>
        <Link to="/" className="mt-2 inline-block text-blue-400 hover:text-cyan-300">← Back to queue</Link>
      </div>
    );
  }

  const client = clientById(ticket.clientId);
  const assignee = userById(ticket.assigneeId);
  const sla = slaInfo(ticket);
  const timerHere = timer?.ticketId === ticket.id;

  const send = () => {
    const body = draft.trim();
    if (!body) return;
    addMessage(ticket.id, { authorName: user.name, kind: "tech", body });
    if (ticket.status === "open") updateTicket(ticket.id, { status: "in_progress" });
    setDraft("");
  };

  return (
    <div className="mx-auto max-w-6xl px-5 py-5">
      {/* header */}
      <div className="mb-4">
        <Link to="/" className="text-[12px] text-muted transition-colors hover:text-ink">← Queue</Link>
        <div className="mt-1.5 flex flex-wrap items-center gap-3">
          <span className="text-sm tabular-nums text-muted">#{ticket.number}</span>
          <h1 className="text-lg font-semibold tracking-tight text-ink">{ticket.subject}</h1>
          <StatusChip status={ticket.status} />
          <PriorityGlyph priority={ticket.priority} withLabel />
        </div>
        <p className="mt-1 text-[12.5px] text-muted">
          <Link to={`/clients/${client?.id}`} className="text-blue-400 hover:text-cyan-300">{client?.name}</Link>
          {" · "}opened {relTime(ticket.createdAt)} ago via {ticket.channel}
        </p>
      </div>

      <div className="grid grid-cols-[1fr_290px] gap-5">
        {/* conversation */}
        <div className="flex min-w-0 flex-col gap-3">
          {ticket.thread.length === 0 && (
            <div className="card px-4 py-8 text-center text-[13px] text-muted">
              No replies yet. <span className="text-cyan-300">Press R</span> to answer first.
            </div>
          )}
          {ticket.thread.map((msg) => <Bubble key={msg.id} msg={msg} />)}

          {/* reply box */}
          <div className="card mt-1 border-line p-3">
            <textarea
              ref={replyRef}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if ((e.metaKey || e.ctrlKey) && e.key === "Enter") send();
              }}
              placeholder="Reply to client…  (⌘Enter to send)"
              rows={3}
              className="w-full resize-none bg-transparent text-[13.5px] leading-relaxed text-ink placeholder:text-muted/60 focus:outline-none"
            />
            <div className="mt-1 flex items-center justify-between">
              <span className="text-[11px] text-muted/70">
                Replying moves Open → In Progress automatically
              </span>
              <button
                onClick={send}
                disabled={!draft.trim()}
                className="rounded-sm bg-blue-500 px-4 py-1.5 text-[13px] font-medium text-white transition-colors hover:bg-blue-400 disabled:opacity-40"
              >
                Send
              </button>
            </div>
          </div>
        </div>

        {/* right rail */}
        <div className="flex min-w-0 flex-col gap-3">
          <div>
            <SectionLabel>Details</SectionLabel>
            <div className="flex flex-col gap-2">
              <CycleButton
                label="Status" value={STATUS_META[ticket.status].label} kbd="S"
                onCycle={() => {
                  const next = cycle(STATUS_ORDER, ticket.status);
                  updateTicket(ticket.id, { status: next }, `Status → ${STATUS_META[next].label}`);
                }}
              >
                <StatusChip status={ticket.status} />
              </CycleButton>
              <CycleButton
                label="Priority" value={PRIORITY_META[ticket.priority].label} kbd="P"
                onCycle={() => {
                  const next = cycle(PRIORITY_ORDER, ticket.priority);
                  updateTicket(ticket.id, { priority: next }, `Priority → ${PRIORITY_META[next].label}`);
                }}
              >
                <PriorityGlyph priority={ticket.priority} withLabel />
              </CycleButton>
              <CycleButton
                label="Assigned to" value={assignee?.name ?? "nobody"} kbd="A"
                onCycle={() => {
                  const mine = ticket.assigneeId === user.id;
                  updateTicket(ticket.id, { assigneeId: mine ? null : user.id }, mine ? "Unassigned" : "Assigned to you");
                }}
              >
                <span className="flex items-center gap-2">
                  <Avatar user={assignee} size={22} />
                  <span className="text-[13px] text-ink">{assignee?.name ?? "Unassigned"}</span>
                </span>
              </CycleButton>
            </div>
          </div>

          <div>
            <SectionLabel>SLA</SectionLabel>
            <div className="card px-3.5 py-2.5 text-[13px]">
              <span className={
                sla.state === "breached" ? "text-rose-400" :
                sla.state === "at_risk" ? "text-gold-400" : "text-mint-300"
              }>
                {sla.state === "met" ? "Resolved within target" : sla.state === "breached" ? `Breached ${sla.label}` : `Response due · ${sla.label}`}
              </span>
              <span className="mt-0.5 block text-[11.5px] text-muted">{client?.slaTier} plan · business hours 8a–6p</span>
            </div>
          </div>

          <div>
            <SectionLabel>Time</SectionLabel>
            <button
              onClick={() => (timerHere ? stopTimer() : startTimer(ticket.id))}
              className={`flex w-full items-center justify-center gap-2 rounded-sm border px-3 py-2 text-[13px] transition-colors ${
                timerHere
                  ? "border-gold-400/50 bg-gold-400/10 text-gold-400"
                  : "border-line bg-white/[0.03] text-ink hover:border-blue-500/40"
              }`}
            >
              {timerHere ? "■ Stop & log time  (E)" : "▶ Start timer  (E)"}
            </button>
          </div>

          <div>
            <SectionLabel>Suite</SectionLabel>
            <div className="card px-3.5 py-3 text-[12px] leading-relaxed text-muted">
              <p className="flex items-center gap-2">
                <span className="h-1.5 w-1.5 rounded-full bg-blue-500" />
                Milepost device context — arrives in Phase 2
              </p>
              <p className="mt-1.5 flex items-center gap-2">
                <span className="h-1.5 w-1.5 rounded-full bg-blue-500" />
                Coastmark invoice handoff — arrives in Phase 2
              </p>
            </div>
          </div>

          <div>
            <SectionLabel>Team</SectionLabel>
            <div className="flex gap-1.5 px-1">
              {USERS.map((u) => <Avatar key={u.id} user={u} size={24} />)}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
