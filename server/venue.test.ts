import { describe, expect, it } from "vitest";
import { parseVenueAccess, persistVenue, readStoredVenue, resolveVenue, venueStorageKey, type Venue } from "../shared/venue";

describe("venue access", () => {
  it("resolves a valid room code and normalizes the table", () => {
    expect(resolveVenue("luna42", "mesa 7")).toEqual({ code: "LUNA42", name: "Luna Bar", table: "07" });
  });
  it("supports unknown establishments without a hardcoded single venue", () => {
    expect(resolveVenue("ROCK123", "12")?.name).toBe("Rock Music House");
  });
  it("rejects invalid room codes", () => {
    expect(resolveVenue("RAUL", "08")).toBeNull();
  });
  it("parses a QR-style query and keeps the table context", () => {
    expect(parseVenueAccess("/", "?room=SAMB17&table=9")).toEqual({ code: "SAMB17", table: "9" });
  });
  it("parses a join route when no query string is present", () => {
    expect(parseVenueAccess("/join/VIVA302", "", "04")).toEqual({ code: "VIVA302", table: "04" });
  });
  it("persists and rehydrates the same venue for all views", () => {
    const store = new Map<string, string>();
    const storage = { getItem: (key: string) => store.get(key) ?? null, setItem: (key: string, value: string) => store.set(key, value) };
    const venue: Venue = { code: "SAMB17", name: "Samba da Vila", table: "09" };
    persistVenue(storage, venue);
    expect(store.has(venueStorageKey)).toBe(true);
    expect(readStoredVenue(storage)).toEqual(venue);
    expect(readStoredVenue(storage)?.table).toBe("09");
  });
});
