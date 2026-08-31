import { z } from "zod";
import { TRPCError } from "@trpc/server";
import { catalog } from "@shared/jukebox";
import { COOKIE_NAME } from "@shared/const";
import { getSessionCookieOptions } from "./_core/cookies";
import { systemRouter } from "./_core/systemRouter";
import { protectedProcedure, publicProcedure, router } from "./_core/trpc";
import { createPendingPayment, createPendingRequest, getVenueByCode, getVenueById, listQueue, markRequestQueuedAfterApprovedPayment, updateRequestStatus, updateVenuePricing } from "./db";
import { isApprovedPaymentPayload, verifyWebhookSignature } from "./payment";

const statusSchema = z.enum(["PLAYING", "PLAYED", "SKIPPED", "CANCELLED"]);
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
  }),
  catalog: router({ list: publicProcedure.input(z.object({ query: z.string().max(80).optional() }).optional()).query(({ input }) => { const query = input?.query?.toLowerCase().trim(); return query ? catalog.filter((song) => `${song.title} ${song.artist}`.toLowerCase().includes(query)) : catalog; }) }),
  requests: router({
    create: publicProcedure.input(z.object({ venueId: z.number().int().positive(), visitorName: z.string().min(1).max(80), tableCode: z.string().max(12).optional(), providerId: z.string().min(1).max(160), title: z.string().min(1).max(180), artist: z.string().min(1).max(180), message: z.string().max(180).optional(), amountCents: z.number().int().nonnegative() })).mutation(async ({ input }) => { const requestId = await createPendingRequest(input); const paymentId = await createPendingPayment({ requestId, amountCents: input.amountCents }); return { requestId, paymentId, status: "AWAITING_PAYMENT" as const }; }),
    updateStatus: protectedProcedure.input(z.object({ requestId: z.number().int().positive(), status: statusSchema })).mutation(({ input }) => updateRequestStatus(input.requestId, input.status).then(() => ({ success: true as const }))),
  }),
  payments: router({
    webhookApproved: publicProcedure.input(z.object({ requestId: z.number().int().positive(), externalId: z.string().min(1), payload: z.string().min(2), signature: z.string().min(1) })).mutation(async ({ input }) => { const secret = process.env.MERCADOPAGO_WEBHOOK_SECRET; if (!secret || !verifyWebhookSignature(input.payload, input.signature, secret)) throw new TRPCError({ code: "UNAUTHORIZED", message: "Invalid payment notification signature" }); if (!isApprovedPaymentPayload(input.payload)) throw new TRPCError({ code: "PRECONDITION_FAILED", message: "Payment is not approved" }); const updated = await markRequestQueuedAfterApprovedPayment(input.requestId, input.externalId); if (!updated) throw new TRPCError({ code: "CONFLICT", message: "No pending payment was updated" }); return { success: true as const, status: "QUEUED" as const }; }),
  }),
});
export type AppRouter = typeof appRouter;
