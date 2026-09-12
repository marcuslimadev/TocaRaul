import { describe, expect, it } from "vitest";
import { payoutEligibility } from "./payoutPolicy";
import { runVenueDailyPayout } from "./payouts";

describe("repasse ao bar", () => {
  it.each([0, 1, 4999])("acumula saldo abaixo de R$50: %i", (balance) => {
    expect(payoutEligibility(balance)).toEqual({ eligible: false, minimumCents: 5000, missingCents: 5000 - balance });
  });
  it.each([5000, 5001, 10000])("considera elegível a partir de R$50: %i", (balance) => {
    expect(payoutEligibility(balance).eligible).toBe(true);
  });
  it.each([NaN, Infinity, 50.5])("recusa centavos inválidos: %s", (balance) => {
    expect(() => payoutEligibility(balance)).toThrow();
  });
  it("não transfere enquanto a conciliação não foi homologada", async () => {
    await expect(runVenueDailyPayout(1)).rejects.toThrow("homologação");
  });
});
