/**
 * Clients — every account at a glance. Keyboard: j/k move, Enter open.
 */

import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { CLIENTS } from "../lib/data";
import { useStore } from "../lib/store";
import { useKeyboard } from "../lib/shortcuts";

export function ClientsScreen() {
  const { tickets } = useStore();
  const navigate = useNavigate();
  const [selected, setSelected] = useState(0);

  useKeyboard({
    j: () => setSelected((i) => Math.min(i + 1, CLIENTS.length - 1)),
    k: () => setSelected((i) => Math.max(i - 1, 0)),
    arrowdown: () => setSelected((i) => Math.min(i + 1, CLIENTS.length - 1)),
    arrowup: () => setSelected((i) => Math.max(i - 1, 0)),
    enter: () => navigate(`/clients/${CLIENTS[selected].id}`),
    o: () => navigate(`/clients/${CLIENTS[selected].id}`),
  });

  return (
    <div className="mx-auto max-w-5xl px-5 py-5">
      <div className="mb-4">
        <h1 className="text-xl font-semibold tracking-tight text-ink">Clients</h1>
        <p className="mt-0.5 text-[12.5px] text-muted">{CLIENTS.length} accounts under your light</p>
      </div>

      <div className="card overflow-hidden">
        {CLIENTS.map((c, i) => {
          const open = tickets.filter((t) => t.clientId === c.id && t.status !== "resolved").length;
          return (
            <button
              key={c.id}
              onClick={() => navigate(`/clients/${c.id}`)}
              onMouseMove={() => setSelected(i)}
              className={`flex w-full items-center gap-4 border-b border-line/60 px-4 py-3.5 text-left transition-colors last:border-0 ${
                i === selected ? "bg-blue-500/10 ring-1 ring-inset ring-blue-500/40" : "hover:bg-white/[0.03]"
              }`}
            >
              <span
                className={`h-2 w-2 rounded-full ${c.health === "good" ? "bg-mint-300" : "bg-gold-400"}`}
                title={c.health === "good" ? "Healthy" : "Needs attention"}
              />
              <span className="min-w-0 flex-1">
                <span className="block text-[13.5px] text-ink">{c.name}</span>
                <span className="block text-xs text-muted">{c.domain}</span>
              </span>
              <span className={`rounded-full px-2.5 py-0.5 text-[11px] ${
                c.slaTier === "premium" ? "bg-gold-400/15 text-gold-400" : "bg-white/5 text-muted"
              }`}>
                {c.slaTier}
              </span>
              <span className="w-20 text-right text-[12.5px] tabular-nums text-muted">
                {open} open
              </span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
