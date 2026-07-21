/**
 * ⌘K command palette — the universal entry point (8 West Standard #2).
 * Fuzzy-find tickets, clients, and actions; keyboard-first, mouse-friendly.
 */

import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useStore } from "../lib/store";
import { useSession } from "../lib/session";
import { clientById } from "../lib/data";
import { PriorityGlyph, STATUS_META } from "./ui";

interface Item {
  id: string;
  group: "Actions" | "Tickets" | "Clients";
  title: string;
  hint?: string;
  kbd?: string;
  icon?: JSX.Element;
  run: () => void;
}

function fuzzy(query: string, text: string): number {
  /** Subsequence match score; -1 = no match. Smaller is better. */
  const q = query.toLowerCase();
  const t = text.toLowerCase();
  if (!q) return 0;
  let ti = 0;
  let score = 0;
  for (const ch of q) {
    const found = t.indexOf(ch, ti);
    if (found === -1) return -1;
    score += found - ti;
    ti = found + 1;
  }
  return score + (t.startsWith(q) ? -10 : 0);
}

export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate();
  const { tickets } = useStore();
  const { signOut } = useSession();
  const [query, setQuery] = useState("");
  const [index, setIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (open) {
      setQuery("");
      setIndex(0);
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  const items = useMemo<Item[]>(() => {
    const go = (path: string) => () => {
      navigate(path);
      onClose();
    };
    const actions: Item[] = [
      { id: "a-queue", group: "Actions", title: "Go to Queue", hint: "g then q", run: go("/") },
      { id: "a-time", group: "Actions", title: "Go to Time", hint: "g then t", run: go("/time") },
      { id: "a-clients", group: "Actions", title: "Go to Clients", hint: "g then c", run: go("/clients") },
      {
        id: "a-signout", group: "Actions", title: "Sign out",
        run: () => { signOut(); onClose(); },
      },
    ];
    const ticketItems: Item[] = tickets.map((t) => ({
      id: `t-${t.id}`,
      group: "Tickets",
      title: `#${t.number} ${t.subject}`,
      hint: clientById(t.clientId)?.name,
      icon: <PriorityGlyph priority={t.priority} />,
      run: go(`/tickets/${t.number}`),
    }));
    const clientItems: Item[] = Array.from(new Set(tickets.map((t) => t.clientId)))
      .map((cid) => clientById(cid))
      .filter((c): c is NonNullable<typeof c> => Boolean(c))
      .map((c) => ({
        id: `c-${c.id}`,
        group: "Clients",
        title: c.name,
        hint: c.domain,
        run: go(`/clients/${c.id}`),
      }));

    const all = [...actions, ...ticketItems, ...clientItems];
    if (!query.trim()) return all;
    return all
      .map((item) => ({ item, score: fuzzy(query, `${item.title} ${item.hint ?? ""}`) }))
      .filter((x) => x.score >= 0)
      .sort((a, b) => a.score - b.score)
      .map((x) => x.item);
  }, [query, tickets, signOut, navigate, onClose]);

  useEffect(() => setIndex(0), [items.length]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") { e.preventDefault(); onClose(); }
      else if (e.key === "ArrowDown") { e.preventDefault(); setIndex((i) => Math.min(i + 1, items.length - 1)); }
      else if (e.key === "ArrowUp") { e.preventDefault(); setIndex((i) => Math.max(i - 1, 0)); }
      else if (e.key === "Enter") { e.preventDefault(); items[index]?.run(); }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, items, index, onClose]);

  useEffect(() => {
    listRef.current
      ?.querySelector(`[data-index="${index}"]`)
      ?.scrollIntoView({ block: "nearest" });
  }, [index]);

  if (!open) return null;

  let lastGroup = "";
  return (
    <div className="fixed inset-0 z-40 flex items-start justify-center pt-[14vh]" onClick={onClose}>
      <div className="absolute inset-0 bg-navy-950/70 backdrop-blur-sm" />
      <div
        className="card relative w-[580px] max-w-[92vw] overflow-hidden border-line bg-navy-800 shadow-card"
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-label="Command palette"
      >
        <div className="flex items-center gap-2.5 border-b border-line px-4 py-3">
          <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.6" className="text-cyan-300">
            <circle cx="7" cy="7" r="4.5" /><path d="m10.5 10.5 3 3" strokeLinecap="round" />
          </svg>
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search tickets, clients, actions…"
            className="w-full bg-transparent text-[14px] text-ink placeholder:text-muted/70 focus:outline-none"
          />
          <kbd className="kbd">esc</kbd>
        </div>

        <div ref={listRef} className="max-h-[46vh] overflow-y-auto py-1.5">
          {items.length === 0 && (
            <div className="px-4 py-8 text-center text-[13px] text-muted">
              Nothing matches “{query}”. The harbor is calm.
            </div>
          )}
          {items.map((item, i) => {
            const header = item.group !== lastGroup ? item.group : null;
            lastGroup = item.group;
            return (
              <div key={item.id}>
                {header && (
                  <div className="px-4 pb-1 pt-2.5 text-[10.5px] font-semibold uppercase tracking-[0.14em] text-muted/70">
                    {header}
                  </div>
                )}
                <button
                  data-index={i}
                  onClick={item.run}
                  onMouseMove={() => setIndex(i)}
                  className={`flex w-full items-center gap-3 px-4 py-2 text-left text-[13px] ${
                    i === index ? "bg-blue-500/15 text-ink" : "text-muted-strong"
                  }`}
                >
                  {item.icon ?? (
                    <span className={`h-1.5 w-1.5 rounded-full ${
                      item.group === "Actions" ? "bg-cyan-300" : item.group === "Clients" ? "bg-gold-400" : STATUS_META.open.dot
                    }`} />
                  )}
                  <span className="min-w-0 flex-1 truncate">{item.title}</span>
                  {item.hint && <span className="truncate text-xs text-muted/70">{item.hint}</span>}
                </button>
              </div>
            );
          })}
        </div>

        <div className="flex items-center gap-3 border-t border-line px-4 py-2 text-[11px] text-muted/80">
          <span className="flex items-center gap-1"><kbd className="kbd">↑↓</kbd> navigate</span>
          <span className="flex items-center gap-1"><kbd className="kbd">↵</kbd> open</span>
          <span className="ml-auto">Queue keys: <kbd className="kbd">j</kbd><kbd className="kbd">k</kbd> move · <kbd className="kbd">s</kbd> status · <kbd className="kbd">p</kbd> priority · <kbd className="kbd">a</kbd> assign · <kbd className="kbd">e</kbd> timer</span>
        </div>
      </div>
    </div>
  );
}
