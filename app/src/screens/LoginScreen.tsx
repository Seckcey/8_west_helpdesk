/**
 * Login — Phase 0 stub. Any email works; "Continue with 8 West ID"
 * exercises the suite SSO stub (lib/sso.ts).
 */

import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useSession } from "../lib/session";
import { beginSuiteSignIn } from "../lib/sso";

export function LoginScreen() {
  const session = useSession();
  const navigate = useNavigate();
  const [email, setEmail] = useState("frankie@8westit.com");
  const [busy, setBusy] = useState(false);

  const finish = (addr: string) => {
    session.signIn(addr);
    navigate("/", { replace: true });
  };

  const suiteSignIn = async () => {
    setBusy(true);
    const identity = await beginSuiteSignIn();
    finish(identity.email);
  };

  return (
    <div className="flex h-full items-center justify-center bg-navy-950 px-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <img src="/brand/favicon.svg" alt="" className="mb-4 h-16 w-16" />
          <h1 className="text-2xl font-semibold tracking-tight text-ink">Safeharbor</h1>
          <p className="mt-1 text-[13px] text-muted">Every client issue, safely ashore.</p>
        </div>

        <div className="card p-5">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              finish(email);
            }}
            className="flex flex-col gap-3"
          >
            <label className="flex flex-col gap-1.5">
              <span className="text-xs text-muted">Work email</span>
              <input
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                type="email"
                autoFocus
                className="rounded-sm border border-line bg-navy-900/70 px-3 py-2 text-[13.5px] text-ink placeholder:text-muted/60 focus:border-blue-500/60 focus:outline-none"
                placeholder="you@yourmsp.com"
              />
            </label>
            <label className="flex flex-col gap-1.5">
              <span className="text-xs text-muted">Password</span>
              <input
                type="password"
                placeholder="••••••••"
                className="rounded-sm border border-line bg-navy-900/70 px-3 py-2 text-[13.5px] text-ink placeholder:text-muted/60 focus:border-blue-500/60 focus:outline-none"
              />
            </label>
            <button
              type="submit"
              className="mt-1 rounded-sm bg-blue-500 py-2 text-[13.5px] font-medium text-white transition-colors hover:bg-blue-400"
            >
              Sign in
            </button>
          </form>

          <div className="my-4 flex items-center gap-3 text-[11px] text-muted/70">
            <span className="h-px flex-1 bg-line" />or<span className="h-px flex-1 bg-line" />
          </div>

          <button
            onClick={suiteSignIn}
            disabled={busy}
            className="flex w-full items-center justify-center gap-2 rounded-sm border border-line bg-white/[0.04] py-2 text-[13.5px] text-ink transition-colors hover:bg-white/[0.08] disabled:opacity-60"
          >
            <img src="/brand/safeharbor-mark.svg" alt="" className="h-4 w-4" />
            {busy ? "Contacting 8 West ID…" : "Continue with 8 West ID"}
          </button>
        </div>

        <p className="mt-5 text-center text-[11.5px] leading-relaxed text-muted/80">
          Phase 0 prototype — any email signs in.
          <br />
          by 8 West IT, LLC · Part of the 8 West IT Total Business Suite
        </p>
      </div>
    </div>
  );
}
