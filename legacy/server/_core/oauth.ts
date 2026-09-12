import { COOKIE_NAME, ONE_YEAR_MS } from "@shared/const";
import { randomBytes, createHash } from "node:crypto";
import { parse as parseCookie, serialize } from "cookie";
import type { Express, Request, Response } from "express";
import { createRemoteJWKSet, jwtVerify } from "jose";
import * as db from "../db";
import { getSessionCookieOptions } from "./cookies";
import { sdk } from "./sdk";
import { ENV } from "./env";

const AUTH = "https://accounts.google.com/o/oauth2/v2/auth";
const TOKEN = "https://oauth2.googleapis.com/token";
const KEYS = createRemoteJWKSet(new URL("https://www.googleapis.com/oauth2/v3/certs"));
const cookieName = (req: Request) => req.secure || req.headers["x-forwarded-proto"] === "https" ? "__Host-google_oauth_state" : "google_oauth_state";
const b64 = (v: Buffer | string) => Buffer.from(v).toString("base64url");

export function registerOAuthRoutes(app: Express) {
  app.get("/api/oauth/google/start", (req, res) => {
    if (!ENV.googleClientId || !ENV.googleClientSecret) { res.status(503).send("Google login is not configured"); return; }
    const redirectUri = ENV.googleRedirectUri || `${req.protocol}://${req.get("host")}/api/oauth/google/callback`;
    const state = b64(randomBytes(32));
    const verifier = b64(randomBytes(48));
    const challenge = b64(createHash("sha256").update(verifier).digest());
    const name = cookieName(req);
    res.setHeader("Set-Cookie", serialize(name, `${state}.${verifier}`, { httpOnly: true, secure: name.startsWith("__Host-"), sameSite: "lax", path: "/", maxAge: 600 }));
    const url = new URL(AUTH);
    url.search = new URLSearchParams({ client_id: ENV.googleClientId, redirect_uri: redirectUri, response_type: "code", scope: "openid email profile", state, nonce: state, code_challenge: challenge, code_challenge_method: "S256", prompt: "select_account" }).toString();
    res.redirect(302, url.toString());
  });

  app.get("/api/oauth/google/callback", async (req: Request, res: Response) => {
    const code = typeof req.query.code === "string" ? req.query.code : "";
    const state = typeof req.query.state === "string" ? req.query.state : "";
    const name = cookieName(req);
    const [expected, verifier] = (parseCookie(req.headers.cookie ?? "")[name] ?? "").split(".");
    res.setHeader("Set-Cookie", serialize(name, "", { httpOnly: true, secure: name.startsWith("__Host-"), sameSite: "lax", path: "/", maxAge: 0 }));
    if (!code || !state || state !== expected || !verifier) { res.status(403).send("Invalid OAuth state"); return; }
    try {
      const redirectUri = ENV.googleRedirectUri || `${req.protocol}://${req.get("host")}/api/oauth/google/callback`;
      const tokenRes = await fetch(TOKEN, { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: new URLSearchParams({ code, client_id: ENV.googleClientId, client_secret: ENV.googleClientSecret, redirect_uri: redirectUri, grant_type: "authorization_code", code_verifier: verifier }) });
      if (!tokenRes.ok) throw new Error(`Google token exchange failed: ${tokenRes.status}`);
      const tokens = await tokenRes.json() as { id_token?: string };
      if (!tokens.id_token) throw new Error("Google did not return an ID token");
      const { payload } = await jwtVerify(tokens.id_token, KEYS, { issuer: ["https://accounts.google.com", "accounts.google.com"], audience: ENV.googleClientId });
      if (payload.nonce !== state || typeof payload.sub !== "string" || payload.email_verified !== true) throw new Error("Invalid Google identity");
      const openId = `google:${payload.sub}`;
      const email = typeof payload.email === "string" ? payload.email : null;
      const displayName = typeof payload.name === "string" && payload.name.trim() ? payload.name : email ?? "Usuário Google";
      await db.upsertUser({ openId, name: displayName, email, loginMethod: "google", lastSignedIn: new Date() });
      const session = await sdk.createSessionToken(openId, { name: displayName, expiresInMs: ONE_YEAR_MS });
      res.cookie(COOKIE_NAME, session, { ...getSessionCookieOptions(req), maxAge: ONE_YEAR_MS });
      res.redirect(302, "/");
    } catch (error) { console.error("[Google OAuth] Callback failed", error); res.status(401).send("Não foi possível entrar com o Google. Tente novamente."); }
  });
}
