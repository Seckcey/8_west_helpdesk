/**
 * Phase 0 auth stub.
 *
 * Real authentication arrives with the 8 West ID SSO contract
 * (docs/suite-sso-contract.md). For the prototype: any email signs in,
 * the session persists in localStorage, and the tenant is always the
 * seeded 8 West IT tenant — the multi-tenant seam (Tenant on every
 * record) is already in the data model.
 */

import { createContext, useCallback, useContext, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { TENANT, USERS } from "./data";
import type { Tenant, User } from "./types";

const KEY = "safeharbor.session.v1";

interface Session {
  /** Signed-in user, or null when signed out. */
  user: User | null;
  tenant: Tenant;
  signIn: (email: string) => void;
  signOut: () => void;
}

const SessionContext = createContext<Session | null>(null);

function loadUser(): User | null {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    const { userId } = JSON.parse(raw);
    return USERS.find((u) => u.id === userId) ?? null;
  } catch {
    return null;
  }
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(loadUser);

  const signIn = useCallback((email: string) => {
    const match = USERS.find(
      (u) => u.email.toLowerCase() === email.trim().toLowerCase(),
    );
    const next = match ?? USERS[0]; // prototype: unknown email -> demo owner
    localStorage.setItem(KEY, JSON.stringify({ userId: next.id }));
    setUser(next);
  }, []);

  const signOut = useCallback(() => {
    localStorage.removeItem(KEY);
    setUser(null);
  }, []);

  const value = useMemo<Session>(
    () => ({ user, tenant: TENANT, signIn, signOut }),
    [user, signIn, signOut],
  );

  return (
    <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
  );
}

/** Session context (always present); check `.user` for auth state. */
export function useSession(): Session {
  const session = useContext(SessionContext);
  if (!session) throw new Error("Safeharbor: SessionProvider not mounted");
  return session;
}

/** Signed-in session, throwing if signed out — for screens behind the guard. */
export function useRequiredSession(): Session & { user: User } {
  const session = useSession();
  if (!session.user) throw new Error("Safeharbor: no active session");
  return session as Session & { user: User };
}

