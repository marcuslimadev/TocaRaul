import { createHmac } from "node:crypto";
import { describe, expect, it } from "vitest";
import { isApprovedPaymentPayload, verifyWebhookSignature } from "./payment";

describe("payment webhook security", () => {
  it("accepts the expected HMAC signature", () => {
    const payload = JSON.stringify({ id: "pay_1", status: "approved" });
    const signature = createHmac("sha256", "secret").update(payload).digest("hex");
    expect(verifyWebhookSignature(payload, signature, "secret")).toBe(true);
  });
  it("rejects authenticated payloads that are not approved", () => {
    expect(isApprovedPaymentPayload(JSON.stringify({ status: "pending" }))).toBe(false);
    expect(isApprovedPaymentPayload(JSON.stringify({ status: "rejected" }))).toBe(false);
    expect(isApprovedPaymentPayload(JSON.stringify({ status: "approved" }))).toBe(true);
    expect(isApprovedPaymentPayload("not-json")).toBe(false);
  });
  it("rejects tampered payloads and missing secrets", () => {
    const signature = createHmac("sha256", "secret").update("original").digest("hex");
    expect(verifyWebhookSignature("tampered", signature, "secret")).toBe(false);
    expect(verifyWebhookSignature("original", signature, "")).toBe(false);
  });
});
