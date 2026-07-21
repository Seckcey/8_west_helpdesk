/**
 * Phase 0 app store: tickets, timer, time entries, toasts.
 *
 * Everything is optimistic by design (the "8 West Standard": update locally,
 * sync in the background). There is no backend yet — state lives in memory
 * with the timer persisted to localStorage — but the update paths are the
 * same ones the real API will sit behind.
 */

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import type { ReactNode } from "react";
import { TICKETS, TIME_ENTRIES } from "./data";
import type { Message, Ticket, TimeEntry } from "./types";

// ---------- SLA ----------
export type SlaInfo = { state: "healthy" | "at_risk" | "breached" | "met"; label: string };

export function slaInfo(ticket: Ticket, nowMs = Date.now()): SlaInfo {
  if (ticket.status === "resolved") return { state: "met", label: "met" };
  const diffMs = new Date(ticket.slaDueAt).getTime() - nowMs;
  const absMin = Math.round(Math.abs(diffMs) / 60000);
  const label =
    absMin >= 60 ? `${Math.floor(absMin / 60)}h ${absMin % 60}m` : `${absMin}m`;
  if (diffMs < 0) return { state: "breached", label: `${label} over` };
  if (diffMs < 2 * 3_600_000) return { state: "at_risk", label: `${label} left` };
  return { state: "healthy", label: `${label} left` };
}

export function relTime(iso: string): string {
  const mins = Math.max(1, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
  if (mins < 60) return `${mins}m`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h`;
  return `${Math.floor(hours / 24)}d`;
}

// ---------- toasts ----------
interface Toast {
  id: number;
  text: string;
}

// ---------- store ----------
interface Store {
  tickets: Ticket[];
  updateTicket: (id: string, patch: Partial<Ticket>, toast?: string) => void;
  addMessage: (ticketId: string, msg: Omit<Message, "id" | "at">) => void;

  entries: TimeEntry[];
  addEntry: (entry: Omit<TimeEntry, "id" | "at">) => void;

  timer: { ticketId: string; startedAt: number } | null;
  startTimer: (ticketId: string) => void;
  stopTimer: (note?: string) => void;
  elapsed: number; // seconds while running

  toasts: Toast[];
  notify: (text: string) => void;
}

const StoreContext = createContext<Store | null>(null);
const TIMER_KEY = "safeharbor.timer.v1";
let toastSeq = 1;
let idSeq = 1;

export function StoreProvider({ children }: { children: ReactNode }) {
  const [tickets, setTickets] = useState<Ticket[]>(TICKETS);
  const [entries, setEntries] = useState<TimeEntry[]>(TIME_ENTRIES);
  const [toasts, setToasts] = useState<Toast[]>([]);
  const [timer, setTimer] = useState<Store["timer"]>(() => {
    try {
      const raw = localStorage.getItem(TIMER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  });
  const [elapsed, setElapsed] = useState(0);
  const interval = useRef<number | null>(null);

  useEffect(() => {
    if (timer) {
      localStorage.setItem(TIMER_KEY, JSON.stringify(timer));
      const tick = () => setElapsed(Math.floor((Date.now() - timer.startedAt) / 1000));
      tick();
      interval.current = window.setInterval(tick, 1000);
      return () => {
        if (interval.current) window.clearInterval(interval.current);
      };
    }
    localStorage.removeItem(TIMER_KEY);
    setElapsed(0);
  }, [timer]);

  const notify = useCallback((text: string) => {
    const id = toastSeq++;
    setToasts((t) => [...t, { id, text }]);
    window.setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 2600);
  }, []);

  const updateTicket = useCallback(
    (id: string, patch: Partial<Ticket>, toast?: string) => {
      setTickets((ts) =>
        ts.map((t) =>
          t.id === id ? { ...t, ...patch, updatedAt: new Date().toISOString() } : t,
        ),
      );
      if (toast) notify(toast);
    },
    [notify],
  );

  const addMessage = useCallback(
    (ticketId: string, msg: Omit<Message, "id" | "at">) => {
      const full: Message = {
        ...msg,
        id: `local_${idSeq++}`,
        at: new Date().toISOString(),
      };
      setTickets((ts) =>
        ts.map((t) =>
          t.id === ticketId
            ? { ...t, thread: [...t.thread, full], updatedAt: full.at }
            : t,
        ),
      );
    },
    [],
  );

  const addEntry = useCallback((entry: Omit<TimeEntry, "id" | "at">) => {
    setEntries((es) => [
      { ...entry, id: `te_local_${idSeq++}`, at: new Date().toISOString() },
      ...es,
    ]);
  }, []);

  const startTimer = useCallback(
    (ticketId: string) => {
      const next = { ticketId, startedAt: Date.now() };
      setTimer(next);
      // persist synchronously — a navigation one tick later must not lose it
      localStorage.setItem(TIMER_KEY, JSON.stringify(next));
      const t = tickets.find((x) => x.id === ticketId);
      notify(t ? `Timer started · #${t.number}` : "Timer started");
    },
    [tickets, notify],
  );

  const stopTimer = useCallback(
    (note?: string) => {
      if (!timer) return;
      const minutes = Math.max(1, Math.round((Date.now() - timer.startedAt) / 60000));
      const t = tickets.find((x) => x.id === timer.ticketId);
      setEntries((es) => [
        {
          id: `te_local_${idSeq++}`,
          ticketId: timer.ticketId,
          userId: "usr_frankie",
          minutes,
          note: note ?? (t ? `Work on #${t.number}` : "Timer entry"),
          at: new Date().toISOString(),
          billable: true,
        },
        ...es,
      ]);
      setTimer(null);
      localStorage.removeItem(TIMER_KEY);
      notify(`Time logged · ${minutes}m${t ? ` on #${t.number}` : ""}`);
    },
    [timer, tickets, notify],
  );

  const value = useMemo<Store>(
    () => ({
      tickets, updateTicket, addMessage,
      entries, addEntry,
      timer, startTimer, stopTimer, elapsed,
      toasts, notify,
    }),
    [tickets, updateTicket, addMessage, entries, addEntry, timer, startTimer, stopTimer, elapsed, toasts, notify],
  );

  return <StoreContext.Provider value={value}>{children}</StoreContext.Provider>;
}

export function useStore(): Store {
  const store = useContext(StoreContext);
  if (!store) throw new Error("Safeharbor: store not mounted");
  return store;
}
