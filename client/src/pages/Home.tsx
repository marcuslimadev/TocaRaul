import { createContext, useContext, useEffect, useMemo, useState } from "react";
import {
  ArrowDown,
  ArrowUp,
  Check,
  ChevronRight,
  CircleDollarSign,
  Clock3,
  Copy,
  Disc3,
  ExternalLink,
  ListMusic,
  LockKeyhole,
  Menu,
  Music2,
  Pause,
  Play,
  Plus,
  QrCode,
  ReceiptText,
  Search,
  Settings2,
  SkipForward,
  Sparkles,
  Tv2,
  UserRound,
  X,
  Zap,
} from "lucide-react";
import {
  catalog,
  confirmPixPayment,
  demoRequests,
  formatBRL,
  type SongRequest,
} from "@shared/jukebox";
import { parseVenueAccess, persistVenue, readStoredVenue, resolveVenue, type Venue } from "@shared/venue";

type View = "client" | "dashboard" | "tv";
const VenueContext = createContext<{ venue: Venue; setVenue: (venue: Venue) => void } | null>(null);
function useVenue() { const context = useContext(VenueContext); if (!context) throw new Error("Venue context is required"); return context; }
const navItems: { id: View; label: string; icon: typeof Music2 }[] = [
  { id: "client", label: "Pedir música", icon: Music2 },
  { id: "dashboard", label: "Gestão", icon: ListMusic },
  { id: "tv", label: "Tela da TV", icon: Tv2 },
];

function Brand({ compact = false }: { compact?: boolean }) {
  return (
    <div className="brand-lockup">
      <div className="brand-mark"><Disc3 size={compact ? 17 : 20} strokeWidth={2.4} /></div>
      <div>
        <strong>TocaRaul</strong>
        {!compact && <span>Pediu. Tocou.</span>}
      </div>
    </div>
  );
}

function StatusPill({ children, tone = "neutral" }: { children: React.ReactNode; tone?: "neutral" | "green" | "amber" | "violet" }) {
  return <span className={`status-pill ${tone}`}><span className="status-dot" />{children}</span>;
}

function SongGlyph({ index = 0, large = false }: { index?: number; large?: boolean }) {
  return <div className={`song-glyph glyph-${index % 4} ${large ? "large" : ""}`}><Music2 size={large ? 28 : 19} /></div>;
}

function Shell({ view, setView, children }: { view: View; setView: (view: View) => void; children: React.ReactNode }) {
  const { venue } = useVenue();
  return (
    <div className="app-shell">
      <aside className="app-sidebar">
        <Brand />
        <div className="venue-chip"><span className="live-indicator" /> {venue.name} <ChevronRight size={14} /></div>
        <div className="nav-label">Operação</div>
        <nav>{navItems.map((item) => { const Icon = item.icon; return <button key={item.id} className={`nav-item ${view === item.id ? "active" : ""}`} onClick={() => setView(item.id)}><Icon size={18} />{item.label}</button>; })}</nav>
        <div className="sidebar-spacer" />
        <div className="sidebar-card"><Sparkles size={17} /><strong>Seu som, sua noite.</strong><p>Deixe a fila fluir e o bar acontecer.</p></div>
        <button className="nav-item muted"><Settings2 size={18} />Configurações</button>
        <div className="profile-row"><div className="avatar">CR</div><div><strong>{venue.name}</strong><span>Administrador</span></div><ChevronRight size={15} /></div>
      </aside>
      <main className="app-main">
        <header className="topbar"><button className="mobile-menu"><Menu size={20} /></button><div className="topbar-context">{view === "client" ? "Experiência do cliente" : view === "dashboard" ? "Painel de gestão" : "Modo TV"}<span>•</span><strong>{venue.name}</strong></div><div className="topbar-actions"><div className="live-status"><span className="live-indicator" /> TV online</div><button className="icon-button"><BellIcon /></button><div className="top-avatar">CR</div></div></header>
        <div className="page-wrap">{children}</div>
      </main>
    </div>
  );
}

function BellIcon() { return <span className="bell-icon"><span /></span>; }

function ClientView() {
  const { venue, setVenue } = useVenue();
  const [search, setSearch] = useState("");
  const [accessOpen, setAccessOpen] = useState(false);
  const [accessCode, setAccessCode] = useState(venue.code);
  const [tableCode, setTableCode] = useState(venue.table);
  const [accessError, setAccessError] = useState("");
  const [selected, setSelected] = useState<(typeof catalog)[number] | null>(null);
  const [message, setMessage] = useState("");
  const [ordered, setOrdered] = useState(false);
  const [paid, setPaid] = useState(false);
  const filtered = useMemo(() => catalog.filter((song) => `${song.title} ${song.artist}`.toLowerCase().includes(search.toLowerCase())), [search]);
  const total = message.trim() ? 500 : 300;

  if (ordered) return <ClientConfirmation paid={paid} onBack={() => { setOrdered(false); setPaid(false); setSelected(null); setMessage(""); }} />;

  return <div className="client-page">
    <div className="client-hero">
      <div className="eyebrow"><span className="live-indicator" /> {venue.name.toUpperCase()} · AMBIENTE {venue.code}</div>
      <h1>Escolha o som.<br /><em>A noite é sua.</em></h1>
      <p>Peça uma música, mande um recado e veja sua escolha tocar na TV.</p>
      <div className="client-access"><QrCode size={18} /><span>Você está na <strong>Mesa {tableCode}</strong></span><button onClick={() => { setAccessError(""); setAccessOpen(true); }}>Alterar</button></div>
    </div>
    <div className="client-content">
      <div className="client-section-head"><div><span className="section-kicker">A trilha da casa</span><h2>O que você quer ouvir?</h2></div><span className="catalog-count">{catalog.length} sugestões</span></div>
      <div className="search-field"><Search size={19} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Busque por música ou artista" /><kbd>⌘ K</kbd></div>
      <div className="song-grid">{filtered.map((song, index) => <button className="song-card" key={song.id} onClick={() => setSelected(song)}><SongGlyph index={index} /><div className="song-card-info"><strong>{song.title}</strong><span>{song.artist}</span></div><span className="song-duration">{song.duration}</span><ChevronRight size={17} className="song-chevron" /></button>)}</div>
      <div className="client-note"><Zap size={17} /><div><strong>Pedidos aprovados entram direto na fila.</strong><span>O pagamento Pix é confirmado antes da música ser liberada para tocar.</span></div></div>
    </div>
    {accessOpen && <div className="modal-backdrop" onClick={() => setAccessOpen(false)}><div className="access-modal" onClick={(e) => e.stopPropagation()}><button className="modal-close" onClick={() => setAccessOpen(false)}><X size={18} /></button><span className="section-kicker">Trocar ambiente</span><h2>Onde você está?</h2><p>Digite o código exibido na TV ou acesse pelo QR Code da sua mesa.</p><label className="field-label">Código do ambiente</label><input className="access-input" value={accessCode} onChange={(e) => setAccessCode(e.target.value.toUpperCase())} placeholder="Ex.: RAUL08" /><label className="field-label">Mesa <span>opcional</span></label><input className="access-input" value={tableCode} onChange={(e) => setTableCode(e.target.value.replace(/\D/g, "").slice(0, 2))} placeholder="08" />{accessError && <div className="access-error">{accessError}</div>}<button className="primary-button full" onClick={() => { const resolved = resolveVenue(accessCode, tableCode); if (!resolved) { setAccessError("Código inválido. Use 4 letras e de 2 a 4 números."); return; } setVenue(resolved); setAccessOpen(false); }}>Entrar no ambiente <ChevronRight size={17} /></button><div className="secure-note"><QrCode size={13} /> O QR Code já preenche estes dados automaticamente</div></div></div>}
    {selected && <div className="modal-backdrop" onClick={() => setSelected(null)}><div className="request-modal" onClick={(e) => e.stopPropagation()}><button className="modal-close" onClick={() => setSelected(null)}><X size={18} /></button><div className="modal-song"><SongGlyph index={Number(selected.id)} large /><div><span className="section-kicker">Sua escolha</span><h2>{selected.title}</h2><p>{selected.artist} · {selected.duration}</p></div></div><label className="field-label">Quer deixar um recado? <span>opcional</span></label><textarea maxLength={80} value={message} onChange={(e) => setMessage(e.target.value)} placeholder="Ex.: Para a mesa 8 — essa é nossa!" /><div className="char-count">{message.length}/80</div><div className="price-summary"><div><span>Pedido da música</span><strong>{formatBRL(300)}</strong></div>{message.trim() && <div><span>Dedicatória</span><strong>{formatBRL(200)}</strong></div>}<div className="price-total"><span>Total</span><strong>{formatBRL(total)}</strong></div></div><button className="primary-button full" onClick={() => setOrdered(true)}><QrCode size={18} />Continuar para Pix <ChevronRight size={17} /></button><div className="secure-note"><LockKeyhole size={13} /> Pagamento seguro · só entra na fila após confirmação</div></div></div>}
  </div>;
}

function ClientConfirmation({ paid, onBack }: { paid: boolean; onBack: () => void }) {
  return <div className="confirmation-page"><div className="confirmation-card"><div className={`confirmation-icon ${paid ? "success" : "pending"}`}>{paid ? <Check size={31} /> : <QrCode size={31} />}</div><span className="section-kicker">Pedido TR-2051</span><h1>{paid ? "Pagamento confirmado." : "Quase lá."}</h1><p>{paid ? "Sua música foi aprovada e já está na fila da Casa do Raul." : "Escaneie o QR Code Pix para liberar sua música."}</p>{!paid && <div className="pix-box"><div className="fake-qr"><QrCode size={74} /></div><div><strong>Pix copia e cola</strong><span>00020126580014BR.GOV.BCB.PIX0136...</span><button><Copy size={14} /> Copiar código</button></div></div>}<div className="request-timeline"><div className="timeline-step done"><span><Check size={13} /></span><div><strong>Pedido criado</strong><small>Agora mesmo</small></div></div><div className={`timeline-line ${paid ? "done" : ""}`} /><div className={`timeline-step ${paid ? "done" : "current"}`}><span>{paid ? <Check size={13} /> : <CircleDollarSign size={13} />}</span><div><strong>{paid ? "Pagamento confirmado" : "Aguardando Pix"}</strong><small>{paid ? "Liberado para a fila" : "A fila só libera após a confirmação"}</small></div></div><div className="timeline-line" /><div className="timeline-step"><span><ListMusic size={13} /></span><div><strong>Na fila</strong><small>Você verá a posição aqui</small></div></div></div><button className="text-button" onClick={onBack}>← Pedir outra música</button></div></div>;
}

function DashboardView() {
  const { venue } = useVenue();
  const [requests, setRequests] = useState<SongRequest[]>(demoRequests);
  const [price, setPrice] = useState(3);
  const [notice, setNotice] = useState("");
  const pending = requests.filter((request) => request.status === "QUEUED");
  const nowPlaying = requests.find((request) => request.status === "PLAYING") ?? requests[0];
  const remove = (id: string) => { setRequests((list) => list.filter((item) => item.id !== id)); setNotice("Pedido removido da fila"); };
  const skip = (id: string) => { setRequests((list) => list.map((item) => item.id === id ? { ...item, status: "SKIPPED" } : item)); setNotice("Música pulada"); };
  const promote = (id: string) => { setRequests((list) => list.map((item) => item.id === id ? { ...item, status: "PLAYING" } : item)); setNotice("Música enviada para tocar agora"); };
  return <div className="dashboard-page">
    <div className="page-heading"><div><span className="section-kicker">Sexta-feira, 14 de junho</span><h1>Boa noite, {venue.name}.</h1><p>Acompanhe o que está tocando e mantenha a pista em movimento.</p></div><button className="outline-button"><ExternalLink size={16} /> Abrir TV</button></div>
    <div className="metrics-row"><div className="metric-card"><div className="metric-icon violet"><ListMusic size={19} /></div><span>Na fila agora</span><strong>{pending.length + 2}</strong><small><span className="metric-up">↑ 12%</span> desde ontem</small></div><div className="metric-card"><div className="metric-icon green"><CircleDollarSign size={19} /></div><span>Arrecadação hoje</span><strong>R$ 184,00</strong><small><span className="metric-up">↑ 18%</span> vs. sexta passada</small></div><div className="metric-card"><div className="metric-icon amber"><Clock3 size={19} /></div><span>Tempo médio na fila</span><strong>18 min</strong><small>baseado em 42 pedidos</small></div><div className="metric-card tv-metric"><div className="tv-metric-copy"><span>Status da TV</span><strong><span className="live-indicator" /> Online</strong><small>Última sincronização há 8s</small></div><Tv2 size={42} /></div></div>
    <div className="dashboard-grid"><section className="panel queue-panel"><div className="panel-heading"><div><span className="section-kicker">Ordem de reprodução</span><h2>Fila de músicas <span className="heading-count">{pending.length}</span></h2></div><button className="icon-button subtle"><Plus size={18} /></button></div><div className="now-playing-row"><div className="now-art"><SongGlyph index={0} /></div><div className="now-copy"><span>TOCANDO AGORA</span><strong>{nowPlaying.title}</strong><small>{nowPlaying.artist} · pedido de {nowPlaying.visitor}</small></div><div className="now-controls"><button><Pause size={16} /></button><button onClick={() => skip(nowPlaying.id)}><SkipForward size={16} /></button></div></div><div className="queue-list">{pending.map((request, index) => <div className="queue-item" key={request.id}><div className="queue-position">{String(index + 1).padStart(2, "0")}</div><SongGlyph index={index + 1} /><div className="queue-copy"><strong>{request.title}</strong><span>{request.artist}</span></div><div className="queue-meta"><span>{request.table}</span><small>{request.visitor}</small></div><div className="queue-actions"><button onClick={() => promote(request.id)} title="Tocar agora"><Play size={14} /></button><button onClick={() => skip(request.id)} title="Pular"><SkipForward size={14} /></button><button onClick={() => remove(request.id)} title="Remover"><X size={14} /></button></div></div>)}</div><button className="queue-footer">Ver todos os pedidos <ChevronRight size={16} /></button></section>
    <section className="panel settings-panel"><div className="panel-heading"><div><span className="section-kicker">Configuração rápida</span><h2>Seu ambiente</h2></div><Settings2 size={19} className="panel-muted-icon" /></div><div className="setting-block"><div className="setting-label"><div><strong>Preço por música</strong><span>O valor que o cliente paga por pedido</span></div><div className="price-input"><span>R$</span><input type="number" value={price} min="0" step="1" onChange={(e) => setPrice(Number(e.target.value))} /></div></div><div className="range-track"><span style={{ width: `${Math.min(price / 10 * 100, 100)}%` }} /></div><div className="range-labels"><span>Grátis</span><span>R$ 10,00</span></div></div><div className="setting-divider" /><div className="setting-block"><div className="setting-label"><div><strong>QR Code do ambiente</strong><span>Clientes escaneiam para pedir</span></div><button className="outline-button compact"><QrCode size={15} /> Ver QR</button></div><div className="room-code"><span>Código rápido · Mesa {venue.table}</span><strong>{venue.code}</strong><button><Copy size={14} /></button></div></div><div className="setting-divider" /><div className="setting-block"><div className="setting-label"><div><strong>Pedidos gratuitos</strong><span>Desativados neste ambiente</span></div><div className="toggle" /></div></div></section></div>
    {notice && <button className="toast" onClick={() => setNotice("")}><Check size={16} /> {notice}</button>}
  </div>;
}

function TvView() {
  const { venue } = useVenue();
  return <div className="tv-page"><div className="tv-toolbar"><div><span className="section-kicker">Preview · Android TV</span><h1>O palco da noite</h1></div><div className="tv-toolbar-actions"><StatusPill tone="green">Sincronizada</StatusPill><button className="outline-button"><ExternalLink size={15} /> Tela cheia</button></div></div><div className="tv-screen"><div className="tv-screen-top"><Brand compact /><span className="tv-live"><span className="live-indicator" /> AO VIVO · {venue.name.toUpperCase()}</span><span className="tv-clock">22:48</span></div><div className="tv-screen-body"><div className="tv-current"><span className="tv-label"><span className="equalizer"><i /><i /><i /></span> TOCANDO AGORA</span><div className="tv-title-row"><div className="tv-disc"><div className="disc-center"><Music2 size={24} /></div></div><div><h2>Exagerado</h2><p>Cazuza</p><span className="tv-requester">Pedido de Mariana · Mesa {venue.table}</span></div></div><div className="progress-bar"><span /></div><div className="progress-time"><span>02:14</span><span>03:40</span></div><div className="dedication"><Sparkles size={15} /><div><span>DEDICATÓRIA DE MARIANA</span><strong>“Para quem transforma qualquer noite em história.”</strong></div></div></div><div className="tv-side"><div className="tv-side-heading"><span>PRÓXIMAS</span><strong>na fila <em>2</em></strong></div><div className="tv-next-item"><span>01</span><div className="mini-art"><Music2 size={16} /></div><div><strong>Evidências</strong><small>Chitãozinho & Xororó</small></div></div><div className="tv-next-item"><span>02</span><div className="mini-art alt"><Music2 size={16} /></div><div><strong>Tempo Perdido</strong><small>Legião Urbana</small></div></div><div className="tv-qr"><div className="fake-qr small"><QrCode size={48} /></div><div><strong>Peça uma música</strong><span>Aponte a câmera para o QR Code</span><b>tocaraul.app/{venue.code.toLowerCase()}</b></div></div></div></div><div className="tv-screen-bottom"><span><span className="live-indicator" /> O som da casa é feito por vocês</span><span>tocar. pedir. repetir.</span></div></div><div className="tv-under-note"><Check size={16} /><span>Feedback de pedidos aprovado aparece aqui automaticamente.</span><button onClick={() => {}}>Entendi</button></div></div>;
}

export default function Home() {
  const [view, setView] = useState<View>("client");
  const [venue, setVenue] = useState<Venue>(() => {
    const saved = readStoredVenue(window.localStorage);
    if (saved) return saved;
    return { code: "RAUL08", name: "Casa do Raul", table: "08" };
  });
  useEffect(() => { persistVenue(window.localStorage, venue); }, [venue]);
  useEffect(() => {
    const access = parseVenueAccess(window.location.pathname, window.location.search, venue.table);
    const resolved = access ? resolveVenue(access.code, access.table) : null;
    if (resolved) setVenue(resolved);
  }, []);
  return <VenueContext.Provider value={{ venue, setVenue }}><Shell view={view} setView={setView}>{view === "client" ? <ClientView /> : view === "dashboard" ? <DashboardView /> : <TvView />}</Shell></VenueContext.Provider>;
}
