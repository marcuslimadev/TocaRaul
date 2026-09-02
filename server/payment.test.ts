import { createHmac } from "node:crypto";
import { afterEach, describe, expect, it, vi } from "vitest";
import { isApprovedPaymentPayload, PagarMePaymentProvider, verifyWebhookSignature } from "./payment";

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

describe("Pagar.me Pix split", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("splits the net proceeds equally between venue and platform", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ id: "or_123", charges: [{ last_transaction: { status: "pending", qr_code: "pix-code" } }] }), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);
    const provider = new PagarMePaymentProvider({ secretKey: "sk_test_123", platformRecipientId: "rp_platform" });

    const payment = await provider.createPayment({ requestId: 42, amountCents: 500, description: "Música", venueRecipientId: "rp_venue" });

    expect(payment).toEqual({ externalId: "or_123", status: "PENDING", pixCopyPaste: "pix-code" });
    const [, request] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(String(request.body));
    expect(body.items).toEqual([{ code: "request_42", amount: 500, description: "Música", quantity: 1 }]);
    expect(body.payments[0].split).toEqual([
      { amount: 50, type: "percentage", recipient_id: "rp_venue", options: { liable: true, charge_processing_fee: true, charge_remainder_fee: false } },
      { amount: 50, type: "percentage", recipient_id: "rp_platform", options: { liable: false, charge_processing_fee: true, charge_remainder_fee: true } },
    ]);
  });

  it("creates the recipient and immediately requests its KYC link", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ id: "rp_venue", status: "registration" }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ url: "https://pagar.me/kyc/abc", expiration_date: "2026-09-01T00:00:00.000Z" }), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);
    const provider = new PagarMePaymentProvider({ secretKey: "sk_test_123", platformRecipientId: "rp_platform" });

    const onboarding = await provider.startRecipientOnboarding({ code: "venue_7", registerInformation: { legal_name: "Bar do Centro" }, defaultBankAccount: { type: "checking" } });

    expect(onboarding).toMatchObject({ recipientId: "rp_venue", recipientStatus: "registration", kycUrl: "https://pagar.me/kyc/abc" });
    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      "https://api.pagar.me/core/v5/recipients",
      "https://api.pagar.me/core/v5/recipients/rp_venue/kyc_link",
    ]);
  });

  it("starts venue onboarding before the platform recipient is configured", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ id: "rp_venue", status: "registration" }), { status: 200 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({}), { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);
    const provider = new PagarMePaymentProvider({ secretKey: "sk_test_123" });

    await expect(provider.startRecipientOnboarding({ code: "venue_7", registerInformation: {}, defaultBankAccount: {} })).resolves.toMatchObject({ recipientId: "rp_venue" });
  });
});
