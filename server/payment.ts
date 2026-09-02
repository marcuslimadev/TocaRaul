import { createHmac, timingSafeEqual } from "node:crypto";

export type PaymentOrder = {
  requestId: number;
  amountCents: number;
  description: string;
  venueRecipientId?: string;
  splitBarPercent?: number;
  mercadoPagoAccessToken?: string;
  payerEmail?: string;
};
export type PaymentResult = { externalId: string; status: "PENDING" | "APPROVED"; pixCopyPaste?: string };
export type PaymentStatus = "PENDING" | "APPROVED" | "REJECTED" | "CANCELLED";
export interface PaymentProvider { createPayment(order: PaymentOrder): Promise<PaymentResult>; getPayment(externalId: string): Promise<PaymentStatus>; }
export class PagarMeApiError extends Error {
  constructor(readonly status: number, message: string) {
    super(message);
    this.name = "PagarMeApiError";
  }
}
export function isApprovedPaymentPayload(payload: string) {
  try { const parsed = JSON.parse(payload) as { status?: unknown }; return parsed.status === "approved"; } catch { return false; }
}
export function verifyWebhookSignature(payload: string, signature: string, secret: string) {
  if (!signature || !secret) return false;
  const expected = createHmac("sha256", secret).update(payload).digest("hex");
  const left = Buffer.from(expected, "utf8"); const right = Buffer.from(signature, "utf8");
  return left.length === right.length && timingSafeEqual(left, right);
}
export class MockPaymentProvider implements PaymentProvider {
  private readonly payments = new Map<string, PaymentStatus>();
  async createPayment(order: PaymentOrder): Promise<PaymentResult> { const externalId = `mock_${order.requestId}`; this.payments.set(externalId, "PENDING"); return { externalId, status: "PENDING", pixCopyPaste: `000201MOCKTOCARAUL${order.amountCents}` }; }
  async getPayment(externalId: string): Promise<PaymentStatus> { return this.payments.get(externalId) ?? "PENDING"; }
  async approvePayment(externalId: string) { this.payments.set(externalId, "APPROVED"); return this.getPayment(externalId); }
}

export const mockPaymentProvider = new MockPaymentProvider();

type MercadoPagoPaymentResponse = {
  id?: number | string;
  status?: string;
  point_of_interaction?: { transaction_data?: { qr_code?: string } };
};

function mapMercadoPagoStatus(status?: string): PaymentStatus {
  if (status === "approved") return "APPROVED";
  if (status === "rejected") return "REJECTED";
  if (status === "cancelled" || status === "canceled") return "CANCELLED";
  return "PENDING";
}

export class MercadoPagoPaymentProvider implements PaymentProvider {
  private readonly integratorAccessToken?: string;

  constructor(config = { accessToken: process.env.MERCADOPAGO_ACCESS_TOKEN }) {
    this.integratorAccessToken = config.accessToken;
  }

  private async request(path: string, accessToken: string, init?: RequestInit): Promise<MercadoPagoPaymentResponse> {
    const response = await fetch(`https://api.mercadopago.com${path}`, { ...init, headers: { Authorization: `Bearer ${accessToken}`, "Content-Type": "application/json", ...init?.headers } });
    const data = await response.json().catch(() => ({})) as MercadoPagoPaymentResponse & { message?: string };
    if (!response.ok) throw new Error(`Mercado Pago request failed (${response.status}): ${data.message ?? "unknown error"}`);
    return data;
  }

  async createPayment(order: PaymentOrder): Promise<PaymentResult> {
    const sellerAccessToken = order.mercadoPagoAccessToken;
    if (!sellerAccessToken) throw new Error("O bar precisa autorizar a conta Mercado Pago antes de receber pedidos pagos");
    const platformPercent = 100 - Math.min(100, Math.max(1, Math.round(order.splitBarPercent ?? 70)));
    const applicationFeeCents = Math.round(order.amountCents * platformPercent / 100);
    const data = await this.request("/v1/payments", sellerAccessToken, {
      method: "POST",
      body: JSON.stringify({
        transaction_amount: order.amountCents / 100,
        description: order.description,
        payment_method_id: "pix",
        application_fee: applicationFeeCents / 100,
        external_reference: `tocaraul_${order.requestId}`,
        payer: { email: order.payerEmail || "cliente@tocaraul.local" },
      }),
    });
    if (!data.id) throw new Error("Mercado Pago did not return a payment id");
    return { externalId: String(data.id), status: mapMercadoPagoStatus(data.status), pixCopyPaste: data.point_of_interaction?.transaction_data?.qr_code };
  }

  async getPayment(externalId: string): Promise<PaymentStatus> {
    if (!this.integratorAccessToken) throw new Error("Mercado Pago requires MERCADOPAGO_ACCESS_TOKEN to query payments centrally");
    const data = await this.request(`/v1/payments/${encodeURIComponent(externalId)}`, this.integratorAccessToken);
    return mapMercadoPagoStatus(data.status);
  }
}

type PagarMeOrderResponse = {
  id?: string;
  status?: string;
  charges?: Array<{ status?: string; last_transaction?: { status?: string; qr_code?: string } }>;
};

export type PagarMeRecipientInput = {
  code: string;
  registerInformation: Record<string, unknown>;
  defaultBankAccount: Record<string, unknown>;
};

export type PagarMeRecipientOnboarding = {
  recipientId: string;
  recipientStatus: string;
  kycUrl?: string;
  kycExpiresAt?: Date;
};

function mapPagarMeStatus(order: PagarMeOrderResponse): PaymentStatus {
  const status = order.charges?.[0]?.last_transaction?.status ?? order.charges?.[0]?.status ?? order.status;
  if (status === "paid") return "APPROVED";
  if (status === "failed") return "REJECTED";
  if (status === "canceled") return "CANCELLED";
  return "PENDING";
}

/** Pagar.me V5 Pix provider. Split is configurable per venue; default is 70% venue / 30% platform. */
export class PagarMePaymentProvider implements PaymentProvider {
  private readonly secretKey: string;
  private readonly platformRecipientId?: string;

  constructor(config = { secretKey: process.env.PAGARME_SECRET_KEY, platformRecipientId: process.env.PAGARME_PLATFORM_RECIPIENT_ID }) {
    if (!config.secretKey) throw new Error("Pagar.me requires PAGARME_SECRET_KEY");
    this.secretKey = config.secretKey;
    this.platformRecipientId = config.platformRecipientId;
  }

  private async request(path: string, init?: RequestInit): Promise<PagarMeOrderResponse> {
    const authorization = `Basic ${Buffer.from(`${this.secretKey}:`).toString("base64")}`;
    const response = await fetch(`https://api.pagar.me/core/v5${path}`, { ...init, headers: { Authorization: authorization, "Content-Type": "application/json", ...init?.headers } });
    const data = await response.json().catch(() => ({})) as PagarMeOrderResponse & { message?: string };
    if (!response.ok) throw new PagarMeApiError(response.status, `Pagar.me request failed (${response.status}): ${data.message ?? "unknown error"}`);
    return data;
  }

  async createPayment(order: PaymentOrder): Promise<PaymentResult> {
    if (!this.platformRecipientId) throw new Error("Pagar.me requires PAGARME_PLATFORM_RECIPIENT_ID to create split payments");
    if (!order.venueRecipientId) throw new Error("The venue must have an active Pagar.me recipient before accepting Pix payments");
    const venueShare = Math.min(100, Math.max(1, Math.round(order.splitBarPercent ?? 70)));
    const platformShare = 100 - venueShare;
    const data = await this.request("/orders", {
      method: "POST",
      body: JSON.stringify({
        code: `request_${order.requestId}`,
        items: [{ code: `request_${order.requestId}`, amount: order.amountCents, description: order.description, quantity: 1 }],
        payments: [{
          payment_method: "pix",
          pix: { expires_in: 900 },
          split: [
            { amount: venueShare, type: "percentage", recipient_id: order.venueRecipientId, options: { liable: true, charge_processing_fee: true, charge_remainder_fee: false } },
            { amount: platformShare, type: "percentage", recipient_id: this.platformRecipientId, options: { liable: false, charge_processing_fee: true, charge_remainder_fee: true } },
          ],
        }],
        metadata: { tocaRaulRequestId: String(order.requestId) },
      }),
    });
    if (!data.id) throw new Error("Pagar.me did not return an order id");
    return { externalId: data.id, status: mapPagarMeStatus(data) === "APPROVED" ? "APPROVED" : "PENDING", pixCopyPaste: data.charges?.[0]?.last_transaction?.qr_code };
  }

  async getPayment(externalId: string): Promise<PaymentStatus> { return mapPagarMeStatus(await this.request(`/orders/${encodeURIComponent(externalId)}`)); }

  async startRecipientOnboarding(input: PagarMeRecipientInput): Promise<PagarMeRecipientOnboarding> {
    const recipient = await this.request("/recipients", {
      method: "POST",
      body: JSON.stringify({ code: input.code, register_information: input.registerInformation, default_bank_account: input.defaultBankAccount }),
    }) as PagarMeOrderResponse & { id?: string; status?: string };
    if (!recipient.id) throw new Error("Pagar.me did not return a recipient id");
    const kyc = await this.request(`/recipients/${encodeURIComponent(recipient.id)}/kyc_link`, { method: "POST", body: "{}" }) as PagarMeOrderResponse & { url?: string; expiration_date?: string };
    return { recipientId: recipient.id, recipientStatus: recipient.status ?? "registration", kycUrl: kyc.url, kycExpiresAt: kyc.expiration_date ? new Date(kyc.expiration_date) : undefined };
  }
}

export function activePaymentProvider(): PaymentProvider {
  const provider = process.env.PAYMENT_PROVIDER?.toLowerCase();
  if (provider === "pagarme") return new PagarMePaymentProvider();
  if (provider === "mercadopago") return new MercadoPagoPaymentProvider();
  return mockPaymentProvider;
}
