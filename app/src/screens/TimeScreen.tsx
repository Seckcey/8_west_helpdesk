/**
 * Time — the weapon against Friday-afternoon batch entry. The timer is
 * always one click away; the app suggests entries from actual work.
 */

import { Link } from "react-router-dom";
import { useStore } from "../lib/store";
import { SectionLabel } from "../components/ui";
import { useState } from "react";

function formatElapsed(totalSeconds: number) {
  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = totalSeconds % 60;
  return h > 0
    ? `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`
    : `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

export function TimeScreen() {
  const { timer, elapsed, tickets, stopTimer, entries, addEntry } = useStore();
  const ticket = timer ? tickets.find((t) => t.id === timer.ticketId) : null;

  // Phase 0: two canned "suggested from your activity" entries.
  const suggestions = [
    { id: "sg1", ticketId: "tck_1037", minutes: 18, reason: "Ticket open 18m, no timer ran" },
    { id: "sg2", ticketId: "tck_1042", minutes: 25, reason: "3 replies sent while untimed" },
  ];
  const [dismissed, setDismissed] = useState<string[]>([]);

  const todayTotal = entries.reduce((sum, e) => sum + e.minutes, 0);

  return (
    <div className="mx-auto max-w-4xl px-5 py-5">
      <div className="mb-4">
        <h1 className="text-xl font-semibold tracking-tight text-ink">Time</h1>
        <p className="mt-0.5 text-[12.5px] text-muted">
          {todayTotal}m logged today · {entries.filter((e) => e.billable).length} billable entries
        </p>
      </div>

      {/* running timer */}
      <div className={`card mb-5 flex items-center gap-4 px-5 py-4 ${timer ? "border-gold-400/40" : ""}`}>
        {timer ? (
          <>
            <span className="pulse-soft h-2.5 w-2.5 rounded-full bg-gold-400" />
            <div className="flex-1">
              <div className="text-[13.5px] text-ink">
                Timing{" "}
                {ticket ? (
                  <Link to={`/tickets/${ticket.number}`} className="text-blue-400 hover:text-cyan-300">
                    #{ticket.number} {ticket.subject}
                  </Link>
                ) : "…"}
              </div>
              <div className="text-[11.5px] text-muted">Started from the ticket view — one click, as promised</div>
            </div>
            <span className="text-2xl font-semibold tabular-nums text-gold-400">{formatElapsed(elapsed)}</span>
            <button
              onClick={() => stopTimer()}
              className="rounded-sm bg-gold-400 px-4 py-1.5 text-[13px] font-medium text-navy-950 transition-colors hover:brightness-110"
            >
              Stop & log
            </button>
          </>
        ) : (
          <>
            <span className="h-2.5 w-2.5 rounded-full bg-muted/40" />
            <div className="flex-1 text-[13px] text-muted">
              No timer running. Press <kbd className="kbd">E</kbd> on any ticket in the queue to start one — or open a ticket and hit Start timer.
            </div>
          </>
        )}
      </div>

      {/* suggested entries */}
      {suggestions.filter((s) => !dismissed.includes(s.id)).length > 0 && (
        <div className="mb-5">
          <SectionLabel>Suggested from your activity</SectionLabel>
          <div className="card divide-y divide-line/60">
            {suggestions
              .filter((s) => !dismissed.includes(s.id))
              .map((s) => {
                const t = tickets.find((x) => x.id === s.ticketId);
                if (!t) return null;
                return (
                  <div key={s.id} className="flex items-center gap-3 px-4 py-3">
                    <span className="h-1.5 w-1.5 rounded-full bg-cyan-300" />
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-[13px] text-ink">
                        {s.minutes}m on <Link to={`/tickets/${t.number}`} className="text-blue-400 hover:text-cyan-300">#{t.number} {t.subject}</Link>
                      </div>
                      <div className="text-[11.5px] text-muted">{s.reason}</div>
                    </div>
                    <button
                      onClick={() => {
                        addEntry({ ticketId: s.ticketId, userId: "usr_frankie", minutes: s.minutes, note: `Suggested entry · #${t.number}`, billable: true });
                        setDismissed((d) => [...d, s.id]);
                      }}
                      className="rounded-sm bg-blue-500/20 px-3 py-1 text-[12px] text-cyan-300 transition-colors hover:bg-blue-500/30"
                    >
                      Log it
                    </button>
                    <button
                      onClick={() => setDismissed((d) => [...d, s.id])}
                      className="rounded-sm px-2 py-1 text-[12px] text-muted transition-colors hover:text-ink"
                    >
                      Dismiss
                    </button>
                  </div>
                );
              })}
          </div>
        </div>
      )}

      {/* entries */}
      <div>
        <SectionLabel>Today's entries</SectionLabel>
        <div className="card overflow-hidden">
          {entries.length === 0 ? (
            <div className="px-4 py-10 text-center text-[13px] text-muted">
              Nothing logged yet. Start a timer — future-you says thanks.
            </div>
          ) : (
            entries.map((e) => {
              const t = tickets.find((x) => x.id === e.ticketId);
              return (
                <div key={e.id} className="flex items-center gap-3 border-b border-line/60 px-4 py-2.5 last:border-0">
                  <span className="w-14 text-right text-[13px] font-medium tabular-nums text-ink">{e.minutes}m</span>
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-[13px] text-muted-strong">{e.note}</div>
                    {t && (
                      <Link to={`/tickets/${t.number}`} className="text-[11.5px] text-blue-400 hover:text-cyan-300">
                        #{t.number}
                      </Link>
                    )}
                  </div>
                  <span className={`rounded-full px-2 py-0.5 text-[10.5px] ${e.billable ? "bg-mint-300/10 text-mint-300" : "bg-white/5 text-muted"}`}>
                    {e.billable ? "billable" : "internal"}
                  </span>
                </div>
              );
            })
          )}
        </div>
        <p className="mt-3 px-1 text-[11.5px] text-muted/80">
          Phase 2: approved entries flow straight into the client's Coastmark invoice. No exports. No reconciliation.
        </p>
      </div>
    </div>
  );
}
