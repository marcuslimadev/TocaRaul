import { int, mysqlEnum, mysqlTable, text, timestamp, unique, varchar } from "drizzle-orm/mysql-core";

export const users = mysqlTable("users", {
  id: int("id").autoincrement().primaryKey(),
  openId: varchar("openId", { length: 64 }).notNull().unique(),
  name: text("name"), email: varchar("email", { length: 320 }), loginMethod: varchar("loginMethod", { length: 64 }),
  role: mysqlEnum("role", ["user", "admin"]).default("user").notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(), updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(), lastSignedIn: timestamp("lastSignedIn").defaultNow().notNull(),
});

export const venues = mysqlTable("venues", {
  id: int("id").autoincrement().primaryKey(),
  ownerId: int("ownerId").notNull(),
  code: varchar("code", { length: 16 }).notNull().unique(),
  name: varchar("name", { length: 120 }).notNull(),
  musicPriceCents: int("musicPriceCents").default(500).notNull(),
  dedicationPriceCents: int("dedicationPriceCents").default(0).notNull(),
  splitBarPercent: int("splitBarPercent").default(70).notNull(),
  splitPlatformPercent: int("splitPlatformPercent").default(30).notNull(),
  ownerDocument: varchar("ownerDocument", { length: 32 }),
  ownerPhone: varchar("ownerPhone", { length: 32 }),
  pixKeyType: varchar("pixKeyType", { length: 32 }),
  pixKey: varchar("pixKey", { length: 180 }),
  splitAcceptedAt: timestamp("splitAcceptedAt"),
  termsAcceptedAt: timestamp("termsAcceptedAt"),
  pagarmeRecipientId: varchar("pagarmeRecipientId", { length: 64 }),
  pagarmeRecipientStatus: varchar("pagarmeRecipientStatus", { length: 32 }),
  pagarmeKycUrl: text("pagarmeKycUrl"),
  pagarmeKycExpiresAt: timestamp("pagarmeKycExpiresAt"),
  mercadoPagoUserId: varchar("mercadoPagoUserId", { length: 64 }),
  mercadoPagoAccessToken: text("mercadoPagoAccessToken"),
  mercadoPagoRefreshToken: text("mercadoPagoRefreshToken"),
  mercadoPagoPublicKey: text("mercadoPagoPublicKey"),
  mercadoPagoTokenExpiresAt: timestamp("mercadoPagoTokenExpiresAt"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export const venueTables = mysqlTable("venueTables", {
  id: int("id").autoincrement().primaryKey(),
  venueId: int("venueId").notNull(),
  label: varchar("label", { length: 32 }).notNull(),
  qrToken: varchar("qrToken", { length: 32 }).notNull().unique(),
  status: mysqlEnum("status", ["ACTIVE", "DISABLED"]).default("ACTIVE").notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export const devices = mysqlTable("devices", {
  id: int("id").autoincrement().primaryKey(),
  venueId: int("venueId"),
  name: varchar("name", { length: 80 }).default("TV Principal").notNull(),
  activationCode: varchar("activationCode", { length: 12 }).notNull().unique(),
  activationCodeExpiresAt: timestamp("activationCodeExpiresAt").notNull(),
  deviceToken: varchar("deviceToken", { length: 96 }).notNull().unique(),
  status: mysqlEnum("status", ["PENDING_ACTIVATION", "ONLINE", "OFFLINE", "REVOKED"]).default("PENDING_ACTIVATION").notNull(),
  lastSeenAt: timestamp("lastSeenAt"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export const songRequests = mysqlTable("songRequests", {
  id: int("id").autoincrement().primaryKey(),
  venueId: int("venueId").notNull(),
  visitorName: varchar("visitorName", { length: 80 }).notNull(),
  tableCode: varchar("tableCode", { length: 12 }),
  providerId: varchar("providerId", { length: 160 }).notNull(),
  title: varchar("title", { length: 180 }).notNull(),
  artist: varchar("artist", { length: 180 }).notNull(),
  message: varchar("message", { length: 180 }),
  amountCents: int("amountCents").notNull(),
  status: mysqlEnum("status", ["AWAITING_PAYMENT", "PAID", "QUEUED", "PLAYING", "PLAYED", "SKIPPED", "CANCELLED", "FAILED"]).default("AWAITING_PAYMENT").notNull(),
  queuePosition: int("queuePosition"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export const payments = mysqlTable("payments", {
  id: int("id").autoincrement().primaryKey(),
  requestId: int("requestId").notNull(),
  provider: varchar("provider", { length: 40 }).default("mercadopago").notNull(),
  externalId: varchar("externalId", { length: 160 }),
  status: mysqlEnum("status", ["PENDING", "APPROVED", "REJECTED", "CANCELLED"]).default("PENDING").notNull(),
  amountCents: int("amountCents").notNull(),
  pixCopyPaste: text("pixCopyPaste"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export const venueBalanceEntries = mysqlTable("venueBalanceEntries", {
  id: int("id").autoincrement().primaryKey(),
  venueId: int("venueId").notNull(),
  requestId: int("requestId"),
  type: mysqlEnum("type", ["EARNING", "PAYOUT", "REVERSAL"]).notNull(),
  amountCents: int("amountCents").notNull(),
  externalReference: varchar("externalReference", { length: 160 }).notNull(),
  status: mysqlEnum("status", ["PENDING", "AVAILABLE", "DONE", "CANCELLED"]).default("AVAILABLE").notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
}, table => ({ externalReferenceUnique: unique("venueBalanceEntries_externalReference").on(table.externalReference) }));

export const venuePayouts = mysqlTable("venuePayouts", {
  id: int("id").autoincrement().primaryKey(),
  venueId: int("venueId").notNull(),
  amountCents: int("amountCents").notNull(),
  pixKeyType: varchar("pixKeyType", { length: 32 }).notNull(),
  pixKey: varchar("pixKey", { length: 180 }).notNull(),
  externalReference: varchar("externalReference", { length: 160 }).notNull().unique(),
  providerTransferId: varchar("providerTransferId", { length: 160 }),
  status: mysqlEnum("status", ["PENDING", "PROCESSING", "DONE", "FAILED", "CANCELLED"]).default("PENDING").notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type User = typeof users.$inferSelect;
export type InsertUser = typeof users.$inferInsert;
export type Venue = typeof venues.$inferSelect;
export type VenueTable = typeof venueTables.$inferSelect;
export type Device = typeof devices.$inferSelect;
export type SongRequest = typeof songRequests.$inferSelect;
export type Payment = typeof payments.$inferSelect;
export type VenueBalanceEntry = typeof venueBalanceEntries.$inferSelect;
export type VenuePayout = typeof venuePayouts.$inferSelect;
