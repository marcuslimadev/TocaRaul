import { createHmac } from "node:crypto";
import { beforeEach, describe, expect, it, vi } from "vitest";

const { markRequestQueuedAfterApprovedPayment } = vi.hoisted(() => ({ markRequestQueuedAfterApprovedPayment: vi.fn() }));
vi.mock("./db", () => ({
  markRequestQueuedAfterApprovedPayment,
  createPendingPayment: vi.fn(), createPendingRequest: vi.fn(), getVenueByCode: vi.fn(), getVenueById: vi.fn(), listQueue: vi.fn(), updateRequestStatus: vi.fn(), updateVenuePricing: vi.fn(),
}));

import { appRouter } from "./routers";

describe("payments.webhookApproved", () => {
  beforeEach(() => { process.env.MERCADOPAGO_WEBHOOK_SECRET = "secret"; markRequestQueuedAfterApprovedPayment.mockReset(); });
  const ctx = { user: undefined, req: { protocol: "https", headers: {} }, res: { clearCookie: vi.fn() } } as never;
  const signed = (payload: string) => createHmac("sha256", "secret").update(payload).digest("hex");

  it("rejects a signed webhook whose payment is not approved", async () => {
    const payload = JSON.stringify({ status: "pending" });
    await expect(appRouter.createCaller(ctx).payments.webhookApproved({ requestId: 1, externalId: "pay_1", payload, signature: signed(payload) })).rejects.toMatchObject({ code: "PRECONDITION_FAILED" });
    expect(markRequestQueuedAfterApprovedPayment).not.toHaveBeenCalled();
  });

  it("returns conflict when an approved webhook updates no pending request", async () => {
    markRequestQueuedAfterApprovedPayment.mockResolvedValue(false);
    const payload = JSON.stringify({ status: "approved" });
    await expect(appRouter.createCaller(ctx).payments.webhookApproved({ requestId: 1, externalId: "pay_1", payload, signature: signed(payload) })).rejects.toMatchObject({ code: "CONFLICT" });
  });
});
