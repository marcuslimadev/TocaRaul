import { int, mysqlEnum, mysqlTable, text, timestamp, varchar } from "drizzle-orm/mysql-core";

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
  musicPriceCents: int("musicPriceCents").default(300).notNull(),
  dedicationPriceCents: int("dedicationPriceCents").default(200).notNull(),
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

export type User = typeof users.$inferSelect;
export type InsertUser = typeof users.$inferInsert;
export type Venue = typeof venues.$inferSelect;
export type SongRequest = typeof songRequests.$inferSelect;
export type Payment = typeof payments.$inferSelect;
