/**
 * Client — the "answer the phone smart" screen: open tickets, contacts,
 * agreement, and (in Phase 2) Milepost devices + Coastmark invoices.
 */

import { Link, useNavigate, useParams } from "react-router-dom";
import { clientById } from "../lib/data";
import { useStore } from "../lib/store";
import { SectionLabel, TicketRow } from "../components/ui";

export function ClientScreen() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { tickets } = useStore();
  const client = id ? clientById(id) : undefined;

  if (!client) {
    return (
      <div className="mx-auto max-w-3xl px-5 py-16 text-center">
        <p className="text-muted">Unknown client.</p>
        <Link to="/clients" className="mt-2 inline-block text-blue-400 hover:text-cyan-300">← All clients</Link>
      </div>
    );
  }

  const clientTickets = tickets.filter((t) => t.clientId === client.id);
  const open = clientTickets.filter((t) => t.status !== "resolved");

  return (
    <div className="mx-auto max-w-6xl px-5 py-5">
      <Link to="/clients" className="text-[12px] text-muted transition-colors hover:text-ink">← Clients</Link>

      {/* header */}
      <div className="mt-1.5 flex flex-wrap items-center gap-3">
        <span className={`h-2.5 w-2.5 rounded-full ${client.health === "good" ? "bg-mint-300" : "bg-gold-400 pulse-soft"}`} />
        <h1 className="text-xl font-semibold tracking-tight text-ink">{client.name}</h1>
        <span className={`rounded-full px-2.5 py-0.5 text-[11px] ${
          client.slaTier === "premium" ? "bg-gold-400/15 text-gold-400" : "bg-white/5 text-muted"
        }`}>
          {client.slaTier} SLA
        </span>
        <span className="text-[12.5px] text-muted">{client.domain}</span>
      </div>

      {/* stats */}
      <div className="mt-4 grid grid-cols-4 gap-3">
        {[
          { label: "Open tickets", value: String(open.length), tone: open.length > 2 ? "text-gold-400" : "text-ink" },
          { label: "Avg first response", value: "22m", tone: "text-mint-300" },
          { label: "Devices", value: "Phase 2", tone: "text-muted", hint: "via Milepost" },
          { label: "Balance", value: "Phase 2", tone: "text-muted", hint: "via Coastmark" },
        ].map((s) => (
          <div key={s.label} className="card px-4 py-3">
            <div className="text-[10.5px] uppercase tracking-[0.12em] text-muted/70">{s.label}</div>
            <div className={`mt-1 text-lg font-semibold tabular-nums ${s.tone}`}>{s.value}</div>
            {s.hint && <div className="text-[10.5px] text-muted/60">{s.hint}</div>}
          </div>
        ))}
      </div>

      <div className="mt-5 grid grid-cols-[1fr_290px] gap-5">
        {/* tickets */}
        <div>
          <SectionLabel>Tickets</SectionLabel>
          <div className="card overflow-hidden">
            {clientTickets.length === 0 ? (
              <div className="px-4 py-10 text-center text-[13px] text-muted">
                No tickets yet for {client.name}. Smooth sailing.
              </div>
            ) : (
              clientTickets.map((t) => (
                <TicketRow key={t.id} ticket={t} selected={false} onOpen={() => navigate(`/tickets/${t.number}`)} />
              ))
            )}
          </div>
        </div>

        {/* contacts + suite */}
        <div className="flex flex-col gap-4">
          <div>
            <SectionLabel>Contacts</SectionLabel>
            <div className="card divide-y divide-line/60">
              {client.contacts.map((c) => (
                <div key={c.id} className="px-3.5 py-2.5">
                  <div className="text-[13px] text-ink">{c.name}</div>
                  <div className="text-[11.5px] text-muted">{c.email}</div>
                </div>
              ))}
            </div>
          </div>
          <div>
            <SectionLabel>Suite</SectionLabel>
            <div className="card px-3.5 py-3 text-[12px] leading-relaxed text-muted">
              <p className="flex items-center gap-2">
                <span className="h-1.5 w-1.5 rounded-full bg-blue-500" />
                Device list arrives with Milepost (Phase 2)
              </p>
              <p className="mt-1.5 flex items-center gap-2">
                <span className="h-1.5 w-1.5 rounded-full bg-blue-500" />
                Invoices & agreement arrive with Coastmark (Phase 2)
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
