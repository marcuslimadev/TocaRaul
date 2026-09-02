import { randomBytes, randomInt } from "node:crypto";
import type { Express, Request } from "express";
import { activateDevice, createPendingDevice, ensureDefaultVenueTable, getDeviceByActivationCode, getDeviceByToken, getVenueById, listQueue, markDeviceHeartbeat } from "./db";

const activationTtlMs = 15 * 60 * 1000;

function publicBaseUrl(req: Request) {
  return process.env.PUBLIC_APP_URL || `${req.protocol}://${req.get("host")}`;
}

function newActivationCode() {
  return String(randomInt(100000, 999999));
}

function newDeviceToken() {
  return randomBytes(36).toString("base64url");
}

function readBearerToken(req: Request) {
  const header = req.get("authorization") || "";
  return header.toLowerCase().startsWith("bearer ") ? header.slice(7).trim() : "";
}

export function registerDeviceRoutes(app: Express) {
  app.post("/api/device/session", async (req, res, next) => {
    try {
      const activationCode = newActivationCode();
      const device = await createPendingDevice({
        activationCode,
        activationCodeExpiresAt: new Date(Date.now() + activationTtlMs),
        deviceToken: newDeviceToken(),
        name: typeof req.body?.name === "string" ? req.body.name : "TV Principal",
      });
      res.json({
        deviceId: device.id,
        activationCode,
        activationUrl: `${publicBaseUrl(req)}/activate-tv?code=${activationCode}`,
        expiresAt: device.activationCodeExpiresAt,
      });
    } catch (error) {
      next(error);
    }
  });

  app.post("/api/device/activate", async (req, res, next) => {
    try {
      const activationCode = String(req.body?.activationCode || "").replace(/\D/g, "");
      const venueId = Number(req.body?.venueId);
      if (!activationCode || !Number.isInteger(venueId) || venueId <= 0) return res.status(400).json({ message: "activationCode and venueId are required" });
      const device = await getDeviceByActivationCode(activationCode);
      if (!device || device.status !== "PENDING_ACTIVATION") return res.status(404).json({ message: "Activation code not found" });
      if (new Date(device.activationCodeExpiresAt).getTime() < Date.now()) return res.status(410).json({ message: "Activation code expired" });
      const venue = await getVenueById(venueId);
      if (!venue) return res.status(404).json({ message: "Venue not found" });
      const activated = await activateDevice(device.id, venue.id, typeof req.body?.name === "string" ? req.body.name : undefined);
      await ensureDefaultVenueTable(venue.id);
      res.json({ deviceToken: activated?.deviceToken, venue: { id: venue.id, name: venue.name, code: venue.code } });
    } catch (error) {
      next(error);
    }
  });

  app.post("/api/device/heartbeat", async (req, res, next) => {
    try {
      const deviceToken = readBearerToken(req);
      if (!deviceToken) return res.status(401).json({ message: "Device token is required" });
      const device = await markDeviceHeartbeat(deviceToken);
      if (!device || device.status === "REVOKED") return res.status(401).json({ message: "Invalid device token" });
      res.json({ ok: true, status: device.status, lastSeenAt: device.lastSeenAt });
    } catch (error) {
      next(error);
    }
  });

  app.get("/api/device/state", async (req, res, next) => {
    try {
      const deviceToken = readBearerToken(req);
      if (!deviceToken) return res.status(401).json({ message: "Device token is required" });
      const device = await getDeviceByToken(deviceToken);
      if (!device || device.status === "REVOKED") return res.status(401).json({ message: "Invalid device token" });
      if (!device.venueId) return res.json({ connection: "WAITING_ACTIVATION" });
      const venue = await getVenueById(device.venueId);
      const table = await ensureDefaultVenueTable(device.venueId);
      const queue = await listQueue(device.venueId);
      const nowPlaying = queue.find((request) => request.status === "PLAYING") ?? null;
      res.json({
        connection: "ONLINE",
        venue: venue ? { id: venue.id, name: venue.name, code: venue.code } : null,
        nowPlaying: nowPlaying ? { id: String(nowPlaying.id), providerId: nowPlaying.providerId, title: nowPlaying.title, artist: nowPlaying.artist, message: nowPlaying.message, tableCode: nowPlaying.tableCode } : null,
        queueSize: queue.filter((request) => request.status === "QUEUED").length,
        qrCodeUrl: `${publicBaseUrl(req)}/j/${table.qrToken}`,
        playbackState: nowPlaying ? "PLAYING" : "IDLE",
      });
    } catch (error) {
      next(error);
    }
  });
}
