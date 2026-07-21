/**
 * Phase 0 demo seed — the 8 West IT tenant, its team, clients, and tickets.
 * Times are relative to "now" at load so the prototype always looks alive.
 */

import type { Client, Tenant, Ticket, TimeEntry, User } from "./types";

const now = Date.now();
const h = (hours: number) => new Date(now + hours * 3_600_000).toISOString();
const m = (mins: number) => new Date(now + mins * 60_000).toISOString();

export const TENANT: Tenant = {
  id: "tnt_8west",
  name: "8 West IT, LLC",
  slug: "8west",
  plan: "suite",
};

export const USERS: User[] = [
  { id: "usr_frankie", tenantId: TENANT.id, name: "Frankie Gonzalez", email: "frankie@8westit.com", role: "owner", initials: "FG", color: "#2D8CFF" },
  { id: "usr_ana", tenantId: TENANT.id, name: "Ana Ruiz", email: "ana@8westit.com", role: "tech", initials: "AR", color: "#7DDCFF" },
  { id: "usr_marcus", tenantId: TENANT.id, name: "Marcus Tran", email: "marcus@8westit.com", role: "tech", initials: "MT", color: "#F6C95B" },
];

export const CLIENTS: Client[] = [
  {
    id: "cli_harbor", tenantId: TENANT.id, name: "Harbor Dental Group",
    domain: "harbordental.example", slaTier: "premium", health: "good",
    contacts: [
      { id: "con_dds", clientId: "cli_harbor", name: "Dr. Priya Nair", email: "pnair@harbordental.example" },
      { id: "con_front", clientId: "cli_harbor", name: "Sam Whitfield", email: "frontdesk@harbordental.example" },
    ],
  },
  {
    id: "cli_bluefin", tenantId: TENANT.id, name: "Bluefin Realty",
    domain: "bluefinrealty.example", slaTier: "standard", health: "watch",
    contacts: [
      { id: "con_broker", clientId: "cli_bluefin", name: "Dana Cole", email: "dana@bluefinrealty.example" },
    ],
  },
  {
    id: "cli_copper", tenantId: TENANT.id, name: "Copper Kettle Bakery",
    domain: "copperkettle.example", slaTier: "standard", health: "good",
    contacts: [
      { id: "con_baker", clientId: "cli_copper", name: "Jo March", email: "jo@copperkettle.example" },
    ],
  },
  {
    id: "cli_northwind", tenantId: TENANT.id, name: "Northwind Legal",
    domain: "northwindlegal.example", slaTier: "premium", health: "good",
    contacts: [
      { id: "con_law", clientId: "cli_northwind", name: "Alex Reyes", email: "areyes@northwindlegal.example" },
    ],
  },
  {
    id: "cli_driftwood", tenantId: TENANT.id, name: "Driftwood Marina",
    domain: "driftwoodmarina.example", slaTier: "standard", health: "good",
    contacts: [
      { id: "con_dock", clientId: "cli_driftwood", name: "Riley Booth", email: "riley@driftwoodmarina.example" },
    ],
  },
];

let n = 1036;
function ticket(partial: Omit<Ticket, "id" | "tenantId" | "number" | "thread"> & { thread?: Ticket["thread"] }): Ticket {
  n += 1;
  return {
    id: `tck_${n}`,
    tenantId: TENANT.id,
    number: n,
    thread: [],
    ...partial,
  };
}

export const TICKETS: Ticket[] = [
  ticket({
    clientId: "cli_harbor", contactId: "con_front",
    subject: "Front desk PC won't print claim forms after update",
    status: "open", priority: "urgent", assigneeId: null, channel: "email",
    createdAt: h(-1.2), updatedAt: h(-0.4), slaDueAt: m(38),
    thread: [
      { id: "msg1", authorName: "Sam Whitfield", kind: "client", at: h(-1.2), body: "Hi — since this morning's Windows update the front desk PC refuses to print claim forms. Regular documents print fine. Patients are checking out in 20 minutes, help!" },
      { id: "msg2", authorName: "Safeharbor", kind: "system", at: h(-1.18), body: "Ticket created from email · SLA: Premium response due in 2 hours" },
    ],
  }),
  ticket({
    clientId: "cli_bluefin", contactId: "con_broker",
    subject: "Shared mailbox not syncing on two agent laptops",
    status: "in_progress", priority: "high", assigneeId: "usr_ana", channel: "portal",
    createdAt: h(-5), updatedAt: h(-1), slaDueAt: h(1.5),
    thread: [
      { id: "msg3", authorName: "Dana Cole", kind: "client", at: h(-5), body: "Listings@ is syncing on my desktop but not on the two new agent laptops. Both are on M365 Business Standard." },
      { id: "msg4", authorName: "Ana Ruiz", kind: "tech", at: h(-2.5), body: "Reproduced — automapping didn't apply. Adding both users explicitly and re-initializing Outlook profiles. I'll confirm within the hour." },
      { id: "msg5", authorName: "Ana Ruiz", kind: "note", at: h(-1), body: "Internal: if this recurs, script it — third time this quarter for Bluefin." },
    ],
  }),
  ticket({
    clientId: "cli_northwind", contactId: "con_law",
    subject: "MFA prompt loop for one attorney after phone swap",
    status: "waiting", priority: "normal", assigneeId: "usr_frankie", channel: "phone",
    createdAt: h(-8), updatedAt: h(-3), slaDueAt: h(4),
    thread: [
      { id: "msg6", authorName: "Frankie Gonzalez", kind: "tech", at: h(-3), body: "Reset the registration and re-enrolled the new device. Waiting on Alex to confirm sign-in works from court Wi-Fi tomorrow." },
    ],
  }),
  ticket({
    clientId: "cli_copper", contactId: "con_baker",
    subject: "POS tablet loses Wi-Fi every morning around opening",
    status: "open", priority: "high", assigneeId: null, channel: "alert",
    createdAt: h(-2), updatedAt: h(-2), slaDueAt: h(2),
  }),
  ticket({
    clientId: "cli_driftwood", contactId: "con_dock",
    subject: "Add fuel dock camera to the office viewing PC",
    status: "open", priority: "low", assigneeId: "usr_marcus", channel: "portal",
    createdAt: h(-26), updatedAt: h(-26), slaDueAt: h(22),
  }),
  ticket({
    clientId: "cli_harbor", contactId: "con_dds",
    subject: "New hygienist starts Monday — accounts & operatory PC access",
    status: "in_progress", priority: "normal", assigneeId: "usr_frankie", channel: "portal",
    createdAt: h(-20), updatedAt: h(-1), slaDueAt: h(28),
  }),
  ticket({
    clientId: "cli_bluefin", contactId: "con_broker",
    subject: "OneDrive 'processing changes' stuck on broker desktop",
    status: "open", priority: "normal", assigneeId: null, channel: "email",
    createdAt: h(-3), updatedAt: h(-3), slaDueAt: h(5),
  }),
  ticket({
    clientId: "cli_northwind", contactId: "con_law",
    subject: "Quarterly phishing refresher — schedule for the firm",
    status: "waiting", priority: "low", assigneeId: "usr_ana", channel: "email",
    createdAt: h(-50), updatedAt: h(-24), slaDueAt: h(46),
  }),
  ticket({
    clientId: "cli_copper", contactId: "con_baker",
    subject: "Recipe SharePoint library permissions cleanup",
    status: "resolved", priority: "normal", assigneeId: "usr_marcus", channel: "portal",
    createdAt: h(-70), updatedAt: h(-10), slaDueAt: h(-10),
    thread: [
      { id: "msg7", authorName: "Marcus Tran", kind: "tech", at: h(-10), body: "Permissions trimmed to the two managers + read for staff. Sent the summary — marking resolved. Holler if anything's missing." },
    ],
  }),
  ticket({
    clientId: "cli_driftwood", contactId: "con_dock",
    subject: "Marina office PC slow after hours — check scheduled tasks",
    status: "open", priority: "normal", assigneeId: null, channel: "alert",
    createdAt: h(-6), updatedAt: h(-6), slaDueAt: h(1.2),
  }),
  ticket({
    clientId: "cli_harbor", contactId: "con_front",
    subject: "Operatory 3 imaging software license expires Friday",
    status: "in_progress", priority: "high", assigneeId: "usr_ana", channel: "portal",
    createdAt: h(-30), updatedAt: h(-2), slaDueAt: h(30),
  }),
  ticket({
    clientId: "cli_bluefin", contactId: "con_broker",
    subject: "Wi-Fi dead zone in the back conference room",
    status: "open", priority: "low", assigneeId: null, channel: "email",
    createdAt: h(-9), updatedAt: h(-9), slaDueAt: h(15),
  }),
  ticket({
    clientId: "cli_northwind", contactId: "con_law",
    subject: "Server backup alert: repository 87% full",
    status: "open", priority: "urgent", assigneeId: "usr_frankie", channel: "alert",
    createdAt: h(-1.5), updatedAt: h(-1.5), slaDueAt: m(55),
  }),
  ticket({
    clientId: "cli_driftwood", contactId: "con_dock",
    subject: "Seasonal staff accounts — disable until April",
    status: "resolved", priority: "low", assigneeId: "usr_marcus", channel: "email",
    createdAt: h(-90), updatedAt: h(-40), slaDueAt: h(-40),
  }),
];

export const TIME_ENTRIES: TimeEntry[] = [
  { id: "te1", ticketId: "tck_1038", userId: "usr_ana", minutes: 35, note: "Mailbox automapping fix + profile rebuild", at: h(-2), billable: true },
  { id: "te2", ticketId: "tck_1042", userId: "usr_frankie", minutes: 20, note: "Hygienist account provisioning", at: h(-1), billable: true },
  { id: "te3", ticketId: "tck_1044", userId: "usr_marcus", minutes: 45, note: "SharePoint permission audit", at: h(-11), billable: true },
];

export const clientById = (id: string) => CLIENTS.find((c) => c.id === id);
export const userById = (id: string | null) => USERS.find((u) => u.id === id);
