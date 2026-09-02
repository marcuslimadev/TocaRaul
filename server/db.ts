import { and, asc, eq, inArray } from "drizzle-orm";
import { drizzle } from "drizzle-orm/mysql2";
import { devices, InsertUser, payments, songRequests, users, venues, venueTables } from "../drizzle/schema";
import { ENV } from "./_core/env";

let _db: ReturnType<typeof drizzle> | null = null;
export async function getDb() {
  if (!_db && process.env.DATABASE_URL) { try { _db = drizzle(process.env.DATABASE_URL); } catch (error) { console.warn("[Database] Failed to connect:", error); _db = null; } }
  return _db;
}
export async function upsertUser(user: InsertUser): Promise<void> {
  if (!user.openId) throw new Error("User openId is required for upsert");
  const db = await getDb(); if (!db) return;
  const values: InsertUser = { openId: user.openId }; const updateSet: Record<string, unknown> = {};
  for (const field of ["name", "email", "loginMethod"] as const) { if (user[field] !== undefined) { values[field] = user[field] ?? null; updateSet[field] = user[field] ?? null; } }
  if (user.lastSignedIn !== undefined) { values.lastSignedIn = user.lastSignedIn; updateSet.lastSignedIn = user.lastSignedIn; } else { values.lastSignedIn = new Date(); updateSet.lastSignedIn = new Date(); }
  if (user.role !== undefined || user.openId === ENV.ownerOpenId) { values.role = user.role ?? "admin"; updateSet.role = values.role; }
  await db.insert(users).values(values).onDuplicateKeyUpdate({ set: updateSet });
}
export async function getUserByOpenId(openId: string) { const db = await getDb(); if (!db) return undefined; const rows = await db.select().from(users).where(eq(users.openId, openId)).limit(1); return rows[0]; }
export async function getVenueByCode(code: string) { const db = await getDb(); if (!db) return undefined; const rows = await db.select().from(venues).where(eq(venues.code, code.toUpperCase())).limit(1); return rows[0]; }
export async function listQueue(venueId: number) { const db = await getDb(); if (!db) return []; return db.select().from(songRequests).where(and(eq(songRequests.venueId, venueId), inArray(songRequests.status, ["QUEUED", "PLAYING"]))).orderBy(asc(songRequests.queuePosition), asc(songRequests.createdAt)); }
export async function createPendingRequest(input: typeof songRequests.$inferInsert) { const db = await getDb(); if (!db) throw new Error("Database unavailable"); const result = await db.insert(songRequests).values({ ...input, status: "AWAITING_PAYMENT" }); return Number(result[0].insertId); }
export async function createPendingPayment(input: typeof payments.$inferInsert) { const db = await getDb(); if (!db) throw new Error("Database unavailable"); const result = await db.insert(payments).values({ ...input, status: "PENDING" }); return Number(result[0].insertId); }
export async function getPaymentByRequestId(requestId: number) { const db = await getDb(); if (!db) return undefined; const rows = await db.select().from(payments).where(eq(payments.requestId, requestId)).limit(1); return rows[0]; }
export async function getRequestById(requestId: number) { const db = await getDb(); if (!db) return undefined; const rows = await db.select().from(songRequests).where(eq(songRequests.id, requestId)).limit(1); return rows[0]; }
export async function markRequestQueuedAfterApprovedPayment(requestId: number, externalId: string) { const db = await getDb(); if (!db) throw new Error("Database unavailable"); const paymentUpdate = await db.update(payments).set({ status: "APPROVED", externalId }).where(and(eq(payments.requestId, requestId), eq(payments.status, "PENDING"))); if (!paymentUpdate[0] || Number(paymentUpdate[0].affectedRows) !== 1) return false; const requestUpdate = await db.update(songRequests).set({ status: "QUEUED" }).where(and(eq(songRequests.id, requestId), eq(songRequests.status, "AWAITING_PAYMENT"))); return Boolean(requestUpdate[0] && Number(requestUpdate[0].affectedRows) === 1); }
export async function updateVenuePricing(venueId: number, musicPriceCents: number, dedicationPriceCents: number) { const db = await getDb(); if (!db) throw new Error("Database unavailable"); await db.update(venues).set({ musicPriceCents, dedicationPriceCents }).where(eq(venues.id, venueId)); return getVenueById(venueId); }
export async function updateVenuePagarmeOnboarding(venueId: number, onboarding: { recipientId: string; recipientStatus: string; kycUrl?: string; kycExpiresAt?: Date }) { const db = await getDb(); if (!db) throw new Error("Database unavailable"); await db.update(venues).set({ pagarmeRecipientId: onboarding.recipientId, pagarmeRecipientStatus: onboarding.recipientStatus, pagarmeKycUrl: onboarding.kycUrl ?? null, pagarmeKycExpiresAt: onboarding.kycExpiresAt ?? null }).where(eq(venues.id, venueId)); return getVenueById(venueId); }
export async function getVenueById(venueId: number) { const db = await getDb(); if (!db) return undefined; const rows = await db.select().from(venues).where(eq(venues.id, venueId)).limit(1); return rows[0]; }
export async function updateRequestStatus(requestId: number, status: "PLAYING" | "PLAYED" | "SKIPPED" | "CANCELLED") { const db = await getDb(); if (!db) throw new Error("Database unavailable"); await db.update(songRequests).set({ status }).where(eq(songRequests.id, requestId)); }

export async function createPendingDevice(input: typeof devices.$inferInsert) {
  const db = await getDb(); if (!db) throw new Error("Database unavailable");
  const result = await db.insert(devices).values(input);
  const rows = await db.select().from(devices).where(eq(devices.id, Number(result[0].insertId))).limit(1);
  return rows[0];
}

export async function getDeviceByActivationCode(activationCode: string) {
  const db = await getDb(); if (!db) return undefined;
  const rows = await db.select().from(devices).where(eq(devices.activationCode, activationCode.replace(/\D/g, ""))).limit(1);
  return rows[0];
}

export async function getDeviceByToken(deviceToken: string) {
  const db = await getDb(); if (!db) return undefined;
  const rows = await db.select().from(devices).where(eq(devices.deviceToken, deviceToken)).limit(1);
  return rows[0];
}

export async function activateDevice(deviceId: number, venueId: number, name?: string) {
  const db = await getDb(); if (!db) throw new Error("Database unavailable");
  await db.update(devices).set({ venueId, name: name || "TV Principal", status: "ONLINE", lastSeenAt: new Date() }).where(eq(devices.id, deviceId));
  return getDeviceById(deviceId);
}

export async function getDeviceById(deviceId: number) {
  const db = await getDb(); if (!db) return undefined;
  const rows = await db.select().from(devices).where(eq(devices.id, deviceId)).limit(1);
  return rows[0];
}

export async function markDeviceHeartbeat(deviceToken: string) {
  const db = await getDb(); if (!db) throw new Error("Database unavailable");
  await db.update(devices).set({ status: "ONLINE", lastSeenAt: new Date() }).where(eq(devices.deviceToken, deviceToken));
  return getDeviceByToken(deviceToken);
}

export async function ensureDefaultVenueTable(venueId: number) {
  const db = await getDb(); if (!db) throw new Error("Database unavailable");
  const existing = await db.select().from(venueTables).where(and(eq(venueTables.venueId, venueId), eq(venueTables.label, "Mesa 01"))).limit(1);
  if (existing[0]) return existing[0];
  const token = Math.random().toString(36).slice(2, 12) + Date.now().toString(36).slice(-4);
  const result = await db.insert(venueTables).values({ venueId, label: "Mesa 01", qrToken: token });
  const rows = await db.select().from(venueTables).where(eq(venueTables.id, Number(result[0].insertId))).limit(1);
  return rows[0];
}
