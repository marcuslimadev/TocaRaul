export type Venue = { code: string; name: string; table: string };
type StorageLike = { getItem: (key: string) => string | null; setItem: (key: string, value: string) => void };
export const venueStorageKey = "tocaraul-venue";
export function readStoredVenue(storage: StorageLike): Venue | null {
  const value = storage.getItem(venueStorageKey);
  if (!value) return null;
  try { return JSON.parse(value) as Venue; } catch { return null; }
}
export function persistVenue(storage: StorageLike, venue: Venue) {
  storage.setItem(venueStorageKey, JSON.stringify(venue));
}

export function parsePrintAccess(pathname: string, search: string, fallbackTable = "01") {
  const code = pathname.match(/\/(?:print|join)\/([^/]+)/)?.[1] ?? "";
  const table = new URLSearchParams(search).get("table") || fallbackTable;
  return code ? { code, table } : null;
}

export function parseVenueAccess(pathname: string, search: string, fallbackTable = "01") {
  const params = new URLSearchParams(search);
  const code = params.get("room") || pathname.match(/\/join\/([^/]+)/)?.[1];
  const table = params.get("table") || fallbackTable;
  return code ? { code, table } : null;
}

export function venueJoinUrl(venue: Venue, baseUrl = "https://tocaraul.app") {
  return `${baseUrl}/join/${venue.code.toLowerCase()}?table=${venue.table}`;
}

export function resolveVenue(code: string, table: string): Venue | null {
  const normalized = code.trim().toUpperCase();
  if (!/^[A-Z]{4}\d{2,4}$/.test(normalized)) return null;
  const prefix = normalized.slice(0, 4);
  const names: Record<string, string> = {
    RAUL: "Casa do Raul",
    SAMB: "Samba da Vila",
    LUNA: "Luna Bar",
    VIVA: "Viva Música",
  };
  return {
    code: normalized,
    name: names[prefix] ?? `${prefix.charAt(0)}${prefix.slice(1).toLowerCase()} Music House`,
    table: table.replace(/\D/g, "").slice(0, 2).padStart(2, "0") || "01",
  };
}
