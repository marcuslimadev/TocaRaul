import { describe, expect, it } from "vitest";
import { confirmPixPayment, demoRequests, moveToQueue, queueOnly } from "../shared/jukebox";

describe("TocaRaul queue rules", () => {
  it("does not include an unpaid request in the queue", () => {
    const unpaid = { ...demoRequests[0], status: "AWAITING_PAYMENT" as const };
    expect(queueOnly([unpaid])).toEqual([]);
    expect(() => moveToQueue(unpaid)).toThrow("Only paid requests can enter the queue");
  });

  it("moves a request to the queue only after Pix confirmation", () => {
    const unpaid = { ...demoRequests[0], status: "AWAITING_PAYMENT" as const };
    const confirmed = confirmPixPayment(unpaid, "pix_test_123");
    expect(confirmed.status).toBe("QUEUED");
    expect(confirmed.paymentId).toBe("pix_test_123");
    expect(queueOnly([confirmed])).toHaveLength(1);
  });

  it("rejects empty payment ids", () => {
    expect(() => confirmPixPayment(demoRequests[0], " ")).toThrow("paymentId is required");
  });
});
