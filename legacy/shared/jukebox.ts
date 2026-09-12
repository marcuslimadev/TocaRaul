export type RequestStatus =
  | "AWAITING_PAYMENT"
  | "PAID"
  | "QUEUED"
  | "PLAYING"
  | "PLAYED"
  | "SKIPPED"
  | "CANCELLED"
  | "FAILED";

export type SongRequest = {
  id: string;
  title: string;
  artist: string;
  thumb: string;
  visitor: string;
  table: string;
  message?: string;
  status: RequestStatus;
  amountCents: number;
  paymentId?: string;
};

export function canEnterQueue(request: Pick<SongRequest, "status">) {
  return request.status === "PAID" || request.status === "QUEUED";
}

export function confirmPixPayment(request: SongRequest, paymentId: string): SongRequest {
  if (!paymentId.trim()) throw new Error("paymentId is required");
  if (request.status === "CANCELLED" || request.status === "FAILED") {
    throw new Error("Cannot confirm payment for a closed request");
  }
  return { ...request, status: "QUEUED", paymentId };
}

export function moveToQueue(request: SongRequest): SongRequest {
  if (!canEnterQueue(request)) throw new Error("Only paid requests can enter the queue");
  return { ...request, status: "QUEUED" };
}

export function markPlaying(request: SongRequest): SongRequest {
  if (request.status !== "QUEUED") throw new Error("Only queued requests can start playing");
  return { ...request, status: "PLAYING" };
}

export function formatBRL(cents: number) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(cents / 100);
}

export const statusLabel: Record<RequestStatus, string> = {
  AWAITING_PAYMENT: "Aguardando Pix",
  PAID: "Pagamento confirmado",
  QUEUED: "Na fila",
  PLAYING: "Tocando agora",
  PLAYED: "Já tocou",
  SKIPPED: "Pulada",
  CANCELLED: "Cancelada",
  FAILED: "Falhou",
};

export const demoRequests: SongRequest[] = [
  {
    id: "TR-2048",
    title: "Exagerado",
    artist: "Cazuza",
    thumb: "https://images.unsplash.com/photo-1524368535928-5b5e00ddc76b?auto=format&fit=crop&w=320&q=80",
    visitor: "Mariana",
    table: "Mesa 08",
    message: "Para quem transforma qualquer noite em história.",
    status: "PLAYING",
    amountCents: 500,
    paymentId: "pix_approved_2048",
  },
  {
    id: "TR-2049",
    title: "Evidências",
    artist: "Chitãozinho & Xororó",
    thumb: "https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?auto=format&fit=crop&w=320&q=80",
    visitor: "Rafa",
    table: "Mesa 03",
    message: "Essa é nossa!",
    status: "QUEUED",
    amountCents: 500,
    paymentId: "pix_approved_2049",
  },
  {
    id: "TR-2050",
    title: "Tempo Perdido",
    artist: "Legião Urbana",
    thumb: "https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=320&q=80",
    visitor: "João",
    table: "Mesa 11",
    status: "QUEUED",
    amountCents: 300,
    paymentId: "pix_approved_2050",
  },
];

export const catalog = [
  { id: "1", title: "Evidências", artist: "Chitãozinho & Xororó", duration: "4:42", thumb: demoRequests[1].thumb },
  { id: "2", title: "Exagerado", artist: "Cazuza", duration: "3:40", thumb: demoRequests[0].thumb },
  { id: "3", title: "Tempo Perdido", artist: "Legião Urbana", duration: "5:01", thumb: demoRequests[2].thumb },
  { id: "4", title: "Anna Júlia", artist: "Los Hermanos", duration: "4:12", thumb: "https://images.unsplash.com/photo-1516280440614-37939bbacd81?auto=format&fit=crop&w=320&q=80" },
];

export const queueStatus = ["QUEUED", "PLAYING"] as const;

export function queueOnly(requests: SongRequest[]) {
  return requests.filter((request) => queueStatus.includes(request.status as (typeof queueStatus)[number]));
}
