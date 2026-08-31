import { createHmac, timingSafeEqual } from "node:crypto";
export type PaymentOrder = { requestId: number; amountCents: number; description: string };
export type PaymentResult = { externalId: string; status: "PENDING" | "APPROVED"; pixCopyPaste?: string };
export type PaymentStatus = "PENDING" | "APPROVED" | "REJECTED" | "CANCELLED";
export interface PaymentProvider { createPayment(order: PaymentOrder): Promise<PaymentResult>; getPayment(externalId: string): Promise<PaymentStatus>; }
export function isApprovedPaymentPayload(payload: string) {
  try { const parsed = JSON.parse(payload) as { status?: unknown }; return parsed.status === "approved"; } catch { return false; }
}
export function verifyWebhookSignature(payload: string, signature: string, secret: string) {
  if (!signature || !secret) return false;
  const expected = createHmac("sha256", secret).update(payload).digest("hex");
  const left = Buffer.from(expected, "utf8"); const right = Buffer.from(signature, "utf8");
  return left.length === right.length && timingSafeEqual(left, right);
}
export class MercadoPagoPaymentProvider implements PaymentProvider {
  async createPayment(_order: PaymentOrder): Promise<PaymentResult> { throw new Error("Mercado Pago credentials are required before creating Pix payments"); }
  async getPayment(_externalId: string): Promise<PaymentStatus> { throw new Error("Mercado Pago credentials are required before querying payments"); }
}
