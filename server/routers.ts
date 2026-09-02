import { z } from "zod";
import { TRPCError } from "@trpc/server";
import { catalog } from "@shared/jukebox";
import { COOKIE_NAME } from "@shared/const";
import { getSessionCookieOptions } from "./_core/cookies";
import { systemRouter } from "./_core/systemRouter";
import { protectedProcedure, publicProcedure, router } from "./_core/trpc";
import { createPendingPayment, createPendingRequest, getPaymentByRequestId, getRequestById, getVenueByCode, getVenueById, listQueue, markRequestQueuedAfterApprovedPayment, updateRequestStatus, updateVenuePagarmeOnboarding, updateVenuePricing } from "./db";
import { activePaymentProvider, mockPaymentProvider, isApprovedPaymentPayload, PagarMeApiError, PagarMePaymentProvider, verifyWebhookSignature } from "./payment";

const statusSchema = z.enum(["PLAYING", "PLAYED", "SKIPPED", "CANCELLED"]);
const mockRequests = new Map<number, { requestId: number; externalId: string; title: string; artist: string; paymentStatus: "PENDING" | "APPROVED"; requestStatus: "AWAITING_PAYMENT" | "QUEUED" }>();
let nextMockRequestId = 9000;
export const appRouter = router({
  system: systemRouter,
  auth: router({
    me: publicProcedure.query((opts) => opts.ctx.user),
    logout: publicProcedure.mutation(({ ctx }) => { const cookieOptions = getSessionCookieOptions(ctx.req); ctx.res.clearCookie(COOKIE_NAME, { ...cookieOptions, maxAge: -1 }); return { success: true } as const; }),
  }),
  venue: router({
    byCode: publicProcedure.input(z.object({ code: z.string().min(6).max(16) })).query(({ input }) => getVenueByCode(input.code)),
    queue: publicProcedure.input(z.object({ venueId: z.number().int().positive() })).query(({ input }) => listQueue(input.venueId)),
    config: protectedProcedure.input(z.object({ venueId: z.number().int().positive() })).query(({ input }) => getVenueById(input.venueId)),
    updatePricing: protectedProcedure.input(z.object({ venueId: z.number().int().positive(), musicPriceCents: z.number().int().nonnegative(), dedicationPriceCents: z.number().int().nonnegative() })).mutation(({ input }) => updateVenuePricing(input.venueId, input.musicPriceCents, input.dedicationPriceCents)),
    startPagarmeOnboarding: protectedProcedure.input(z.object({ venueId: z.number().int().positive(), registerInformation: z.record(z.string(), z.unknown()), defaultBankAccount: z.record(z.string(), z.unknown()) })).mutation(async ({ input }) => {
      const venue = await getVenueById(input.venueId);
      if (!venue) throw new TRPCError({ code: "NOT_FOUND", message: "Venue not found" });
      if (venue.pagarmeRecipientId) throw new TRPCError({ code: "CONFLICT", message: "Venue already has a Pagar.me recipient" });
      let onboarding;
      try {
        onboarding = await new PagarMePaymentProvider().startRecipientOnboarding({ code: `venue_${venue.id}`, registerInformation: input.registerInformation, defaultBankAccount: input.defaultBankAccount });
      } catch (error) {
        if (error instanceof PagarMeApiError && error.message.includes("not allowed to create a recipient")) {
          throw new TRPCError({ code: "PRECONDITION_FAILED", message: "A conta Pagar.me ainda nao esta habilitada para criar recebedores via API. Solicite a liberacao de marketplace/split ao Pagar.me e tente novamente." });
        }
        throw error;
      }
      await updateVenuePagarmeOnboarding(venue.id, onboarding);
      return onboarding;
    }),
  }),
  catalog: router({ list: publicProcedure.input(z.object({ query: z.string().max(80).optional() }).optional()).query(({ input }) => { const query = input?.query?.toLowerCase().trim(); return query ? catalog.filter((song) => `${song.title} ${song.artist}`.toLowerCase().includes(query)) : catalog; }) }),
  requests: router({
    mockCreate: publicProcedure.input(z.object({ title: z.string().min(1), artist: z.string().min(1) })).mutation(async ({ input }) => { const requestId = nextMockRequestId++; const payment = await mockPaymentProvider.createPayment({ requestId, amountCents: 300, description: `${input.title} — ${input.artist}` }); mockRequests.set(requestId, { requestId, externalId: payment.externalId, title: input.title, artist: input.artist, paymentStatus: "PENDING", requestStatus: "AWAITING_PAYMENT" }); return { requestId, externalId: payment.externalId, pixCopyPaste: payment.pixCopyPaste, status: "AWAITING_PAYMENT" as const }; }),
    create: publicProcedure.input(z.object({ venueId: z.number().int().positive(), visitorName: z.string().min(1).max(80), tableCode: z.string().max(12).optional(), providerId: z.string().min(1).max(160), title: z.string().min(1).max(180), artist: z.string().min(1).max(180), message: z.string().max(180).optional(), amountCents: z.number().int().nonnegative(), payerEmail: z.string().email().optional() })).mutation(async ({ input }) => { const venue = await getVenueById(input.venueId); if (!venue) throw new TRPCError({ code: "NOT_FOUND", message: "Venue not found" }); const requestId = await createPendingRequest(input); const provider = activePaymentProvider(); const paymentProviderName = process.env.PAYMENT_PROVIDER?.toLowerCase() === "mercadopago" ? "mercadopago" : process.env.PAYMENT_PROVIDER?.toLowerCase() === "pagarme" ? "pagarme" : "mock"; const payment = await provider.createPayment({ requestId, amountCents: input.amountCents, description: `${input.title} — ${input.artist}`, venueRecipientId: venue.pagarmeRecipientId ?? undefined, splitBarPercent: venue.splitBarPercent ?? 70, mercadoPagoAccessToken: venue.mercadoPagoAccessToken ?? undefined, payerEmail: input.payerEmail }); const paymentId = await createPendingPayment({ requestId, provider: paymentProviderName, amountCents: input.amountCents, externalId: payment.externalId, pixCopyPaste: payment.pixCopyPaste }); return { requestId, paymentId, externalId: payment.externalId, pixCopyPaste: payment.pixCopyPaste, status: "AWAITING_PAYMENT" as const }; }),
    updateStatus: protectedProcedure.input(z.object({ requestId: z.number().int().positive(), status: statusSchema })).mutation(({ input }) => updateRequestStatus(input.requestId, input.status).then(() => ({ success: true as const }))),
  }),
  payments: router({
    mockStatus: publicProcedure.input(z.object({ requestId: z.number().int().positive() })).query(async ({ input }) => { const inMemory = mockRequests.get(input.requestId); if (inMemory) { const providerStatus = await mockPaymentProvider.getPayment(inMemory.externalId); return { paymentStatus: providerStatus, requestStatus: inMemory.requestStatus, externalId: inMemory.externalId, pixCopyPaste: `000201MOCKTOCARAUL${inMemory.requestId}` }; } const payment = await getPaymentByRequestId(input.requestId); const request = await getRequestById(input.requestId); if (!payment || !request) return null; const providerStatus = payment.externalId ? await mockPaymentProvider.getPayment(payment.externalId) : "PENDING"; return { paymentStatus: providerStatus, requestStatus: request.status, externalId: payment.externalId, pixCopyPaste: payment.pixCopyPaste }; }),
    mockConfirm: publicProcedure.input(z.object({ requestId: z.number().int().positive(), externalId: z.string().regex(/^mock_/) })).mutation(async ({ input }) => { const inMemory = mockRequests.get(input.requestId); if (inMemory && inMemory.externalId === input.externalId) { await mockPaymentProvider.approvePayment(input.externalId); inMemory.paymentStatus = "APPROVED"; inMemory.requestStatus = "QUEUED"; return { success: true as const, status: "QUEUED" as const }; } const updated = await markRequestQueuedAfterApprovedPayment(input.requestId, input.externalId); if (!updated) throw new TRPCError({ code: "CONFLICT", message: "Mock payment is not pending or request is already queued" }); return { success: true as const, status: "QUEUED" as const }; }),
    webhookApproved: publicProcedure.input(z.object({ requestId: z.number().int().positive(), externalId: z.string().min(1), payload: z.string().min(2), signature: z.string().min(1) })).mutation(async ({ input }) => { const secret = process.env.MERCADOPAGO_WEBHOOK_SECRET; if (!secret || !verifyWebhookSignature(input.payload, input.signature, secret)) throw new TRPCError({ code: "UNAUTHORIZED", message: "Invalid payment notification signature" }); if (!isApprovedPaymentPayload(input.payload)) throw new TRPCError({ code: "PRECONDITION_FAILED", message: "Payment is not approved" }); const updated = await markRequestQueuedAfterApprovedPayment(input.requestId, input.externalId); if (!updated) throw new TRPCError({ code: "CONFLICT", message: "No pending payment was updated" }); return { success: true as const, status: "QUEUED" as const }; }),
  }),
});
export type AppRouter = typeof appRouter;
