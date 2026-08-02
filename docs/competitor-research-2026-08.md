# Helpdesk / PSA competitive research — August 2026

Compiled 2026-08-01 to drive Safeharbor's v1.0 MVP scope. Companion to the
phased plan in `8_West_Helpdesk_App_Idea_and_Phased_Rollout.docx` (Section 4
of that doc was the July snapshot; this refresh adds UX-pattern and pricing
detail from ConnectWise, Autotask, HaloPSA, Syncro, Atera, SuperOps, DeskDay,
Help Scout, Freshdesk, Zendesk, Front, Intercom, Plain, and Linear).

## 1. Table-stakes for an MSP helpdesk v1.0 (ranked)

| # | Feature | Why it's table stakes | Safeharbor status |
|---|---------|----------------------|-------------------|
| 1 | Email-to-ticket that never loses mail — real threading (In-Reply-To/References + subject-token fallback), duplicate suppression, loop detection, attachments | Every MSP's clients live in email; a dropped or doubled ticket destroys trust | Have (Graph) — harden threading/dedupe, add attachments |
| 2 | Ticket queue with saved/filterable views (status, tech, client, SLA-due order) | The queue *is* the product | Have |
| 3 | Public reply vs. internal note, visually unmistakable, one composer | The #1 catastrophic failure mode is a private note emailed to a client | **Missing UI** (schema supports `note` kind) |
| 4 | Frictionless time tracking attached to tickets (timer + fast manual entry at reply time) | ConnectWise's single most hated screen; billing raw material | Have timer — add entry-at-reply |
| 5 | SLA due-times from client tier + breach visibility in the queue | Contractual for MSPs | Have (fixed 2h/8h; policies later) |
| 6 | Clients/contacts with per-client defaults; domain→client matching of inbound senders | How every PSA auto-files mail | Have |
| 7 | Canned responses / saved replies with merge fields | Universal top productivity feature | Build — cheap, huge win |
| 8 | Assignment + statuses + notifications (on assignment and client reply) | Basic accountability loop | Partial (client-reply mail only) |
| 9 | Merge tickets (3 emails, one issue) + inbound dedupe suggestions | Universally expected | Build |
| 10 | Collision detection ("X is viewing / typing…", block stale sends) | Help Scout ships it default-on; now assumed | Build |
| 11 | Fast global search incl. message bodies | ⌘K exists; index bodies + resolved tickets | Extend |
| 12 | Billable-time reporting/export (hours per client per period, CSV) | Invoice-capable without building invoicing | Build minimal |
| 13 | Basic dashboards (open by tech, aging, SLA compliance, time logged) | Owner's weekly view | Build minimal (current 22m stat is hardcoded) |
| 14 | One-click CSAT in the closure email | Nearly free (SmileBack pattern: 3 signed emoji links) | Cheap, v1.0-optional |
| 15 | Client portal | Analysts list it; small-MSP reality is email | Defer to v1.1 |

Explicitly NOT needed for MVP: projects, procurement/inventory, contracts
engine, full invoicing, RMM. "Most MSPs on ConnectWise aren't using even half
of what it can do."

## 2. Top 10 UX patterns that make modern helpdesks feel fast

1. **Command palette that executes actions**, not just navigation (Linear ⌘K: change status/assign/create from one input, backed by a local data store — instant).
2. **Single-letter shortcuts with visible hints** — shortcuts shown in every tooltip/menu so the mouse UI teaches the keyboard UI; `?` opens a shortcut overlay (Linear).
3. **Optimistic, local-first updates — zero spinners** (Linear; Plain markets "100ms interactions, no loading spinners").
4. **Collision detection** — Help Scout shows who's viewing/replying and *blocks your send* if the thread changed while you typed, showing the new message first. Front shows drafts forming live.
5. **Reply vs. internal note as two tabs of one composer, with @mentions** (Front) — kills side-channel Slack threads.
6. **Snooze / waiting that auto-resurfaces** on client reply or timeout — queue shows only actionable items.
7. **Send-and-next triage flow** (Front/Superhuman) — one keyboard session, not N page loads.
8. **Personalized default views** — each tech lands on "my tickets by SLA due" (Plain's Personalized Queues).
9. **Everything inline on the ticket screen** — HaloPSA praised for it; ConnectWise's popup-per-field pods are the anti-pattern.
10. **Quiet AI**: pinned one-line thread summary + draft-reply button. Summarize+draft is the honest scope (SuperOps takes flak for overpromising).

Perf tricks that buy the "instant" feel in a plain PHP app: prefetch ticket
detail on queue-row hover/focus, cache + reconcile, animate only
transform/opacity at 100–250ms, keep first paint small.

## 3. Incumbent wounds (quotable)

- **ConnectWise**: r/msp thread "ConnectWise time entry is driving my techs insane" — "so clunky that my techs wait until Friday afternoon to log time"; "too bloated… runs slower"; weeks of training, ~2-month implementations, quote-only pricing.
- **Autotask**: "outdated and clunky… too many steps"; inconsistent UI across modules; Kaseya lock-in; quote-only, multi-year contracts.
- **HaloPSA** (the loved one): "overwhelming… can take more than a year to get the most out of it"; sub-5-agent teams waitlisted + £3,200 onboarding fee.
- **Atera**: "ticketing depth lighter"; "reporting feels stuck in 2019"; read-only users need full licenses.
- **Syncro**: clunky appointment↔ticket flow, weak mobile, thin reporting.
- **SuperOps**: "the AI is mostly marketing."
- **Zendesk**: add-on creep — 10-agent team can owe $9k+/yr in AI fees alone.

Meta-pattern: every complaint is "too many clicks," "too slow," "too long to
learn," or "pricing is hidden."

## 4. Pricing landscape (per tech/agent/month)

| Product | Price | Notes |
|---|---|---|
| ConnectWise PSA | ~$35–65 list, $60–85 real | Quote-only; +40–80% first-year implementation |
| Autotask | Unpublished | Quote-only, multi-year lock-in |
| HaloPSA | ~$105–119 list | <5 agents: waitlist + £3,200 onboarding |
| Syncro | $129–139 annual / $159 monthly | Includes RMM, unlimited endpoints |
| Atera | $129–219 | Includes RMM; reporting gated to top tiers |
| SuperOps | PSA-only $79; unified $129–159 | 150 endpoints/tech cap |
| DeskDay | $59 annual | Chat-first budget entrant |
| Help Scout | $25/$45/$75 | AI Answers $0.75/resolution |
| Freshdesk | $19–89 | Freddy Copilot +$29/agent |
| Zendesk | $55–149 | AI Copilot +$50/agent |
| Front | $25 min, real $65–105 | |
| Intercom | $29/$85/$132 | Fin $0.99/resolution |
| Linear (reference) | ~$8–14 | What "fast + keyboard" can cost |

Gap: transparent $29–79/tech, no minimums, no onboarding fee — sub-5-tech
MSPs are literally waitlisted out of HaloPSA today. Per-resolution AI billing
($0.75–0.99) is the 2025–26 pattern; seat add-ons run $29–50.

## 5. "Steal this" — outsized wins for a keyboard-first Safeharbor

1. **Time entry inside the reply composer** — timer runs from ticket open; Send pre-fills elapsed minutes + billable toggle; Enter accepts, Esc skips. Time capture becomes a side effect of replying (kills the #1 ConnectWise complaint).
2. **Collision detection in PHP** — heartbeat row (`ticket_id, user_id, mode, last_seen`); badges on queue rows + ticket header; on Send, compare last-message-id in the form vs. DB — block stale sends and show the new message above the draft.
3. **Send-and-next triage**: `r` reply / `n` note (tabs of one composer), `s` snooze, `Ctrl+Enter` send, `Shift+Ctrl+Enter` send-and-next; `?` shortcut overlay; shortcuts visible in tooltips.
4. **Snooze/waiting auto-resurface** — inbound reply or 3-business-day timeout reopens at top of queue. Default per-tech view = "my tickets by SLA due."
5. **One-click CSAT** — three emoji links in the closure email, signed GET, no login. A weekend build; SmileBack's whole business is this pattern.
6. **Saved replies via `/` in the composer** — fuzzy list, merge fields ({contact.first_name}, {ticket.id}) resolved on insert, keyboard-only.
7. **Hover/focus-prefetch of ticket detail** — most of Linear's instant feel without a sync engine.
8. **Merge via ⌘K** (`merge into #1234`) + auto-suggest when same sender, similar subject, within 48h.
9. **Quiet AI**: pinned thread summary ("6 messages · printer VPN issue · waiting on client since Tue") + Draft reply.
10. **Publish pricing** on the site: $X/tech/month, no minimums, no onboarding fee — positioning feature, not just a business decision.
