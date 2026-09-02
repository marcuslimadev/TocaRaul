import fs from "node:fs";
import mysql from "mysql2/promise";

function readEnv(path) {
  return Object.fromEntries(
    fs.readFileSync(path, "utf8")
      .split(/\r?\n/)
      .filter(Boolean)
      .filter((line) => !line.trim().startsWith("#"))
      .map((line) => {
        const index = line.indexOf("=");
        return [line.slice(0, index), line.slice(index + 1)];
      })
  );
}

const env = readEnv(".env.local");
if (!env.DATABASE_URL) throw new Error("DATABASE_URL is required in .env.local");

const connection = await mysql.createConnection(env.DATABASE_URL);
const execute = (sql) => connection.query(sql);

await execute(`
  CREATE TABLE IF NOT EXISTS users (
    id int AUTO_INCREMENT NOT NULL,
    openId varchar(64) NOT NULL,
    name text,
    email varchar(320),
    loginMethod varchar(64),
    role enum('user','admin') NOT NULL DEFAULT 'user',
    createdAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lastSignedIn timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    UNIQUE KEY users_openId_unique(openId)
  )
`);

await execute(`
  CREATE TABLE IF NOT EXISTS venues (
    id int AUTO_INCREMENT NOT NULL,
    ownerId int NOT NULL,
    code varchar(16) NOT NULL,
    name varchar(120) NOT NULL,
    musicPriceCents int NOT NULL DEFAULT 300,
    dedicationPriceCents int NOT NULL DEFAULT 200,
    createdAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    UNIQUE KEY venues_code_unique(code)
  )
`);

await execute(`
  CREATE TABLE IF NOT EXISTS payments (
    id int AUTO_INCREMENT NOT NULL,
    requestId int NOT NULL,
    provider varchar(40) NOT NULL DEFAULT 'mercadopago',
    externalId varchar(160),
    status enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    amountCents int NOT NULL,
    pixCopyPaste text,
    createdAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(id)
  )
`);

await execute(`
  CREATE TABLE IF NOT EXISTS songRequests (
    id int AUTO_INCREMENT NOT NULL,
    venueId int NOT NULL,
    visitorName varchar(80) NOT NULL,
    tableCode varchar(12),
    providerId varchar(160) NOT NULL,
    title varchar(180) NOT NULL,
    artist varchar(180) NOT NULL,
    message varchar(180),
    amountCents int NOT NULL,
    status enum('AWAITING_PAYMENT','PAID','QUEUED','PLAYING','PLAYED','SKIPPED','CANCELLED','FAILED') NOT NULL DEFAULT 'AWAITING_PAYMENT',
    queuePosition int,
    createdAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(id)
  )
`);

const [venueColumns] = await connection.query(
  "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venues'"
);
const existingVenueColumns = new Set(venueColumns.map((row) => row.COLUMN_NAME));
const venueAdds = [];
if (!existingVenueColumns.has("pagarmeRecipientId")) venueAdds.push("ADD COLUMN pagarmeRecipientId varchar(64)");
if (!existingVenueColumns.has("pagarmeRecipientStatus")) venueAdds.push("ADD COLUMN pagarmeRecipientStatus varchar(32)");
if (!existingVenueColumns.has("pagarmeKycUrl")) venueAdds.push("ADD COLUMN pagarmeKycUrl text");
if (!existingVenueColumns.has("pagarmeKycExpiresAt")) venueAdds.push("ADD COLUMN pagarmeKycExpiresAt timestamp NULL");
if (!existingVenueColumns.has("splitBarPercent")) venueAdds.push("ADD COLUMN splitBarPercent int NOT NULL DEFAULT 70");
if (!existingVenueColumns.has("splitPlatformPercent")) venueAdds.push("ADD COLUMN splitPlatformPercent int NOT NULL DEFAULT 30");
if (!existingVenueColumns.has("ownerDocument")) venueAdds.push("ADD COLUMN ownerDocument varchar(32)");
if (!existingVenueColumns.has("ownerPhone")) venueAdds.push("ADD COLUMN ownerPhone varchar(32)");
if (!existingVenueColumns.has("pixKeyType")) venueAdds.push("ADD COLUMN pixKeyType varchar(32)");
if (!existingVenueColumns.has("pixKey")) venueAdds.push("ADD COLUMN pixKey varchar(180)");
if (!existingVenueColumns.has("splitAcceptedAt")) venueAdds.push("ADD COLUMN splitAcceptedAt timestamp NULL");
if (!existingVenueColumns.has("termsAcceptedAt")) venueAdds.push("ADD COLUMN termsAcceptedAt timestamp NULL");
if (!existingVenueColumns.has("mercadoPagoUserId")) venueAdds.push("ADD COLUMN mercadoPagoUserId varchar(64)");
if (!existingVenueColumns.has("mercadoPagoAccessToken")) venueAdds.push("ADD COLUMN mercadoPagoAccessToken text");
if (!existingVenueColumns.has("mercadoPagoRefreshToken")) venueAdds.push("ADD COLUMN mercadoPagoRefreshToken text");
if (!existingVenueColumns.has("mercadoPagoPublicKey")) venueAdds.push("ADD COLUMN mercadoPagoPublicKey text");
if (!existingVenueColumns.has("mercadoPagoTokenExpiresAt")) venueAdds.push("ADD COLUMN mercadoPagoTokenExpiresAt timestamp NULL");
if (venueAdds.length) await execute(`ALTER TABLE venues ${venueAdds.join(", ")}`);
await execute("UPDATE venues SET splitBarPercent = 70 WHERE splitBarPercent IS NULL OR splitBarPercent <= 0");
await execute("UPDATE venues SET splitPlatformPercent = 100 - splitBarPercent WHERE splitPlatformPercent IS NULL OR splitPlatformPercent <= 0");

await execute(`
  CREATE TABLE IF NOT EXISTS venueTables (
    id int AUTO_INCREMENT PRIMARY KEY,
    venueId int NOT NULL,
    label varchar(32) NOT NULL,
    qrToken varchar(32) NOT NULL,
    status enum('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    createdAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY venueTables_qrToken_unique(qrToken)
  )
`);

await execute(`
  CREATE TABLE IF NOT EXISTS devices (
    id int AUTO_INCREMENT PRIMARY KEY,
    venueId int,
    name varchar(80) NOT NULL DEFAULT 'TV Principal',
    activationCode varchar(12) NOT NULL,
    activationCodeExpiresAt timestamp NOT NULL,
    deviceToken varchar(96) NOT NULL,
    status enum('PENDING_ACTIVATION','ONLINE','OFFLINE','REVOKED') NOT NULL DEFAULT 'PENDING_ACTIVATION',
    lastSeenAt timestamp NULL,
    createdAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY devices_activationCode_unique(activationCode),
    UNIQUE KEY devices_deviceToken_unique(deviceToken)
  )
`);

await connection.query(
  "INSERT IGNORE INTO users (id, openId, name, email, loginMethod, role) VALUES (1, 'local-owner', 'TocaRaul Owner', NULL, 'seed', 'admin')"
);
await connection.query(
  "INSERT IGNORE INTO venues (id, ownerId, code, name, musicPriceCents, dedicationPriceCents, splitBarPercent, splitPlatformPercent) VALUES (1, 1, 'RAUL01', 'Bar do Centro', 300, 200, 70, 30)"
);

await connection.end();
console.log(JSON.stringify({ ok: true, tables: ["users", "venues", "payments", "songRequests", "venueTables", "devices"], seedVenue: "RAUL01" }));
