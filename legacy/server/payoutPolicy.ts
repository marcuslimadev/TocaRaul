export const DEFAULT_MINIMUM_PAYOUT_CENTS = 5000;

/** Only settled, unreserved balance is eligible; pending receipts do not count. */
export function payoutEligibility(availableCents: number, minimumCents = DEFAULT_MINIMUM_PAYOUT_CENTS) {
  if (!Number.isSafeInteger(availableCents) || !Number.isSafeInteger(minimumCents) || minimumCents <= 0) {
    throw new Error("Saldo ou mínimo de repasse inválido");
  }
  return {
    eligible: availableCents >= minimumCents,
    minimumCents,
    missingCents: Math.max(0, minimumCents - availableCents),
  };
}
