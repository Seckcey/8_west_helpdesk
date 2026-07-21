/**
 * App chrome: sidebar (brand + nav + suite), top bar (search trigger,
 * timer, user), toast stack. The five key screens render in <Outlet/>.
 */

import { NavLink, Outlet, useNavigate } from "react-router-dom";
import { useRequiredSession } from "../lib/session";
import { useStore } from "../lib/store";
import { Avatar } from "./ui";
import { CommandPalette } from "./CommandPalette";
import { useState } from "react";
import { useKeyboard } from "../lib/shortcuts";

function NavItem({ to, label, kbd, icon }: { to: string; label: string; kbd?: string; icon: JSX.Element }) {
  return (
    <NavLink
      to={to}
      end={to === "/"}
      className={({ isActive }) =>
        `group flex items-center gap-3 rounded-sm px-3 py-2 text-[13px] transition-colors duration-100 ${
          isActive
            ? "bg-blue-500/15 text-cyan-300"
            : "text-muted hover:bg-white/[0.04] hover:text-ink"
        }`
      }
    >
      <span className="opacity-80">{icon}</span>
      <span className="flex-1">{label}</span>
      {kbd && <kbd className="kbd opacity-0 transition-opacity group-hover:opacity-100">{kbd}</kbd>}
    </NavLink>
  );
}

const ICONS = {
  queue: (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M2 4h12M2 8h12M2 12h7" strokeLinecap="round" />
    </svg>
  ),
  time: (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
      <circle cx="8" cy="8" r="6" /><path d="M8 4.5V8l2.5 2" strokeLinecap="round" />
    </svg>
  ),
  clients: (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
      <circle cx="5.5" cy="6" r="2.5" /><circle cx="11" cy="7" r="2" />
      <path d="M1.5 13.5c.6-2.3 2.2-3.5 4-3.5s3.4 1.2 4 3.5M9.5 12.6c.7-1.4 1.9-2.1 3-2.1 1.3 0 2.4.9 2.9 2.6" strokeLinecap="round" />
    </svg>
  ),
};

function formatElapsed(totalSeconds: number) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

function TimerWidget() {
  const { timer, elapsed, tickets } = useStore();
  const navigate = useNavigate();
  const ticket = timer ? tickets.find((t) => t.id === timer.ticketId) : null;
  if (!timer) {
    return (
      <button
        onClick={() => navigate("/time")}
        className="rounded-full border border-line px-3 py-1.5 text-xs text-muted transition-colors hover:text-ink"
        title="Time tracking (E on a ticket starts the timer)"
      >
        No timer running
      </button>
    );
  }
  return (
    <button
      onClick={() => navigate("/time")}
      className="flex items-center gap-2 rounded-full border border-gold-400/40 bg-gold-400/10 px-3 py-1.5 text-xs text-gold-400"
      title={ticket ? `Timer on #${ticket.number} — go to Time` : "Timer running — go to Time"}
    >
      <span className="pulse-soft h-1.5 w-1.5 rounded-full bg-gold-400" />
      <span className="tabular-nums">{formatElapsed(elapsed)}</span>
      {ticket && <span className="text-gold-400/70">#{ticket.number}</span>}
    </button>
  );
}

function Toasts() {
  const { toasts } = useStore();
  return (
    <div className="pointer-events-none fixed bottom-4 right-4 z-50 flex flex-col items-end gap-2">
      {toasts.map((t) => (
        <div key={t.id} className="row-in card border-cyan-300/30 bg-navy-800 px-3.5 py-2 text-[13px] text-ink shadow-card">
          {t.text}
        </div>
      ))}
    </div>
  );
}

export function AppShell() {
  const { user, tenant, signOut } = useRequiredSession();
  const [paletteOpen, setPaletteOpen] = useState(false);
  useKeyboard({ "mod+k": () => setPaletteOpen((v) => !v) });

  return (
    <div className="flex h-full bg-navy-950">
      {/* sidebar */}
      <aside className="flex w-60 flex-col border-r border-line bg-navy-900/60">
        <div className="flex items-center gap-2.5 px-4 pb-5 pt-4">
          <img src="/brand/favicon.svg" alt="Safeharbor" className="h-8 w-8" />
          <div className="leading-tight">
            <div className="text-[15px] font-semibold tracking-tight text-ink">Safeharbor</div>
            <div className="text-[10.5px] text-muted">by 8 West IT, LLC</div>
          </div>
        </div>

        <nav className="flex flex-col gap-0.5 px-2">
          <NavItem to="/" label="Queue" kbd="G Q" icon={ICONS.queue} />
          <NavItem to="/time" label="Time" kbd="G T" icon={ICONS.time} />
          <NavItem to="/clients" label="Clients" kbd="G C" icon={ICONS.clients} />
        </nav>

        <div className="mt-6 px-4 pb-1 text-[10.5px] font-semibold uppercase tracking-[0.14em] text-muted/70">
          8 West Suite
        </div>
        <div className="flex flex-col gap-0.5 px-2">
          {["Milepost", "Coastmark"].map((name) => (
            <div
              key={name}
              className="flex cursor-not-allowed items-center gap-3 rounded-sm px-3 py-2 text-[13px] text-muted/50"
              title={`${name} integration arrives in Phase 2`}
            >
              <span className="h-3.5 w-3.5 rounded-sm border border-line" />
              <span className="flex-1">{name}</span>
              <span className="text-[10px]">P2</span>
            </div>
          ))}
        </div>

        <div className="mt-auto border-t border-line p-3">
          <div className="flex items-center gap-2.5">
            <Avatar user={user} size={30} />
            <div className="min-w-0 flex-1 leading-tight">
              <div className="truncate text-[13px] text-ink">{user.name}</div>
              <div className="truncate text-[10.5px] text-muted">{tenant.name}</div>
            </div>
            <button
              onClick={signOut}
              className="rounded-sm px-2 py-1 text-[11px] text-muted transition-colors hover:text-ink"
              title="Sign out"
            >
              Sign out
            </button>
          </div>
        </div>
      </aside>

      {/* main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center gap-3 border-b border-line bg-navy-900/40 px-5 py-3">
          <button
            onClick={() => setPaletteOpen(true)}
            className="flex w-72 items-center gap-2.5 rounded-sm border border-line bg-white/[0.03] px-3 py-1.5 text-[13px] text-muted transition-colors hover:border-blue-500/40 hover:text-ink"
          >
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.6">
              <circle cx="7" cy="7" r="4.5" /><path d="m10.5 10.5 3 3" strokeLinecap="round" />
            </svg>
            <span className="flex-1 text-left">Search or command…</span>
            <kbd className="kbd">⌘K</kbd>
          </button>
          <div className="flex-1" />
          <TimerWidget />
          <Avatar user={user} size={30} />
        </header>

        <main className="min-h-0 flex-1 overflow-y-auto">
          <Outlet />
        </main>
      </div>

      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
      <Toasts />
    </div>
  );
}
