import { describe, expect, it, vi } from "vitest";
const { getVenueById } = vi.hoisted(() => ({ getVenueById: vi.fn() }));
vi.mock("./db", () => ({ getVenueById, createPendingPayment: vi.fn(), createPendingRequest: vi.fn(), getVenueByCode: vi.fn(), listQueue: vi.fn(), markRequestQueuedAfterApprovedPayment: vi.fn(), updateRequestStatus: vi.fn(), updateVenuePricing: vi.fn() }));
import { appRouter } from "./routers";

describe("jukebox contracts", () => {
  it("lists music catalog through tRPC", async () => {
    const result = await appRouter.createCaller({ user: undefined, req: {}, res: {} } as never).catalog.list({ query: "cazuza" });
    expect(result[0]).toMatchObject({ id: "2", title: "Exagerado", artist: "Cazuza", duration: "3:40" });
  });
  it("reads venue pricing for an authenticated manager", async () => {
    getVenueById.mockResolvedValue({ id: 1, code: "RAUL08", name: "Casa do Raul", musicPriceCents: 300, dedicationPriceCents: 200 });
    const result = await appRouter.createCaller({ user: { id: 1, role: "admin" }, req: {}, res: {} } as never).venue.config({ venueId: 1 });
    expect(result?.musicPriceCents).toBe(300);
  });
});
