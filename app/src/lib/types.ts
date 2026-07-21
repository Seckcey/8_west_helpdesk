/**
 * Safeharbor multi-tenant data model (Phase 0 contract).
 *
 * Every entity is tenant-scoped: one MSP = one tenant; the MSP's customers
 * are Clients. Suite products (Milepost, Coastmark) resolve the same tenant
 * id via the 8 West ID SSO contract (see docs/suite-sso-contract.md).
 */

export type ID = string;

export type TicketStatus = "open" | "in_progress" | "waiting" | "resolved";
export type Priority = "low" | "normal" | "high" | "urgent";
export type Channel = "email" | "portal" | "alert" | "phone";

export interface Tenant {
  id: ID;
  name: string;
  slug: string;
  plan: "suite";
}

export interface User {
  id: ID;
  tenantId: ID;
  name: string;
  email: string;
  role: "owner" | "tech";
  initials: string;
  color: string; // avatar accent (brand token hex)
}

export interface Contact {
  id: ID;
  clientId: ID;
  name: string;
  email: string;
}

export type SlaTier = "standard" | "premium";

export interface Client {
  id: ID;
  tenantId: ID;
  name: string;
  domain: string;
  slaTier: SlaTier;
  contacts: Contact[];
  health: "good" | "watch";
}

export interface Message {
  id: ID;
  authorName: string;
  kind: "client" | "tech" | "note" | "system";
  at: string; // ISO
  body: string;
}

export interface Ticket {
  id: ID;
  tenantId: ID;
  clientId: ID;
  contactId: ID;
  number: number;
  subject: string;
  status: TicketStatus;
  priority: Priority;
  assigneeId: ID | null;
  channel: Channel;
  createdAt: string; // ISO
  updatedAt: string; // ISO
  slaDueAt: string; // ISO — first-response/resolution target for the prototype
  thread: Message[];
}

export interface TimeEntry {
  id: ID;
  ticketId: ID;
  userId: ID;
  minutes: number;
  note: string;
  at: string; // ISO
  billable: boolean;
}

export type SlaState = "healthy" | "at_risk" | "breached" | "met";
