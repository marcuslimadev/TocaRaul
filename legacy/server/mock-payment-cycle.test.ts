import { describe, expect, it, vi } from "vitest";
import { mockPaymentProvider } from "./payment";
import { appRouter } from "./routers";
import type { TrpcContext } from "./_core/context";

function createContext(): TrpcContext {
  return {
    user: null,
    req: { protocol: "https", headers: {} } as TrpcContext["req"],
    res: {} as TrpcContext["res"],
  };
}

describe("mock payment cycle", () => {
  it("keeps a new request pending until explicit confirmation", async () => {
    const caller = appRouter.createCaller(createContext());
    const created = await caller.requests.mockCreate({ title: "Evidências", artist: "Chitãozinho & Xororó" });
    const pending = await caller.payments.mockStatus({ requestId: created.requestId });

    expect(created.status).toBe("AWAITING_PAYMENT");
    expect(pending?.paymentStatus).toBe("PENDING");
    expect(pending?.requestStatus).toBe("AWAITING_PAYMENT");

    await caller.payments.mockConfirm({ requestId: created.requestId, externalId: created.externalId });
    const confirmed = await caller.payments.mockStatus({ requestId: created.requestId });

    expect(confirmed?.paymentStatus).toBe("APPROVED");
    expect(confirmed?.requestStatus).toBe("QUEUED");
  });

  it("uses the provider as the source of payment status", async () => {
    const caller = appRouter.createCaller(createContext());
    const created = await caller.requests.mockCreate({ title: "Anna Júlia", artist: "Los Hermanos" });
    const providerSpy = vi.spyOn(mockPaymentProvider, "getPayment").mockResolvedValue("APPROVED");

    const status = await caller.payments.mockStatus({ requestId: created.requestId });

    expect(status?.paymentStatus).toBe("APPROVED");
    expect(status?.requestStatus).toBe("AWAITING_PAYMENT");
    expect(providerSpy).toHaveBeenCalledWith(created.externalId);
    providerSpy.mockRestore();
  });
});
