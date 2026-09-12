import { afterEach, describe, expect, it, vi } from "vitest";
import { AsaasPaymentProvider } from "./payment";

describe("Asaas sandbox safety", () => {
  afterEach(() => vi.unstubAllGlobals());
  it.each(["https://api.asaas.com/v3", "http://api-sandbox.asaas.com/v3", "https://example.com/v3"])("refuses endpoint %s", (baseUrl) => {
    expect(() => new AsaasPaymentProvider({ apiKey: "test", baseUrl })).toThrow("sandbox");
  });
  it("rejects invalid amounts before contacting Asaas", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    const provider = new AsaasPaymentProvider({ apiKey: "test", baseUrl: "https://api-sandbox.asaas.com/v3" });
    await expect(provider.createPayment({ requestId: 1, amountCents: -1, description: "test" })).rejects.toThrow("inválido");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
