/** Money movement is blocked until atomic reservations and reconciliation are tested. */
export async function runVenueDailyPayout(_venueId: number, _date?: string): Promise<never> {
  throw new Error("Repasses indisponíveis durante homologação do ledger Asaas");
}
