/**
 * Keyboard model (Phase 0): one global listener, mnemonic single keys.
 *
 * Rules (the "8 West Standard"):
 *  - Single-letter shortcuts never fire while typing in a field.
 *  - Ctrl/Cmd+K and Escape always work.
 *  - Every shortcut is discoverable in the command palette.
 */

import { useEffect } from "react";

export type KeyMap = Record<string, (e: KeyboardEvent) => void>;

function isTyping(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  if (!el) return false;
  const tag = el.tagName;
  return (
    tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable
  );
}

/**
 * Bind keys. Entries: "k" plain key, "mod+k" Ctrl/Cmd, "shift+/" etc.
 * Plain single keys are ignored while typing; "mod+*" and "escape" are not.
 */
export function useKeyboard(map: KeyMap, active = true) {
  useEffect(() => {
    if (!active) return;
    const onKey = (e: KeyboardEvent) => {
      const parts: string[] = [];
      if (e.ctrlKey || e.metaKey) parts.push("mod");
      if (e.shiftKey) parts.push("shift");
      const key = e.key.toLowerCase();
      parts.push(key);
      const combo = parts.join("+");
      const plain = key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey;

      const handler = map[combo] ?? (plain ? map[key] : undefined);
      if (!handler) return;
      if (plain && isTyping(e.target)) return;
      e.preventDefault();
      handler(e);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, ...Object.keys(map), ...Object.values(map)]);
}
