// Shared TocaRaul screen runtime: claims the paid queue and plays it on YouTube.
// Used full screen by /player and embedded inside the bar panel (/bar).
(function () {
  function el(id) { return id ? document.getElementById(id) : null; }

  window.TocaRaulPlayer = function (opts) {
    const mount = opts.mount;
    const ui = opts.ui || {};
    const store = 'tocaraul_player_' + opts.venueId;
    let deviceToken = null;
    try { deviceToken = localStorage.getItem(store) || null; } catch (e) {}

    let started = false, pendingRequestId = null, pendingResult = null, currentlyPlaying = false;
    let ytApiReady = false, ytPlayer = null, watchdog = null;
    let voiceDone = true, pausedByOwner = false, ptVoice = null;

    window.onYouTubeIframeAPIReady = () => { ytApiReady = true; };
    (() => { const s = document.createElement('script'); s.src = 'https://www.youtube.com/iframe_api'; document.head.appendChild(s); })();

    function loadVoices() {
      const v = speechSynthesis.getVoices();
      ptVoice = v.find((x) => x.lang && x.lang.toLowerCase().startsWith('pt-br'))
        || v.find((x) => x.lang && x.lang.toLowerCase().startsWith('pt')) || null;
    }
    if ('speechSynthesis' in window) { loadVoices(); speechSynthesis.onvoiceschanged = loadVoices; }

    function speak(text) {
      return new Promise((resolve) => {
        if (!('speechSynthesis' in window)) return resolve();
        try {
          const u = new SpeechSynthesisUtterance(text);
          u.lang = 'pt-BR'; u.rate = 0.9; u.pitch = 0.85; u.volume = 1;
          if (ptVoice) u.voice = ptVoice;
          u.onend = resolve; u.onerror = resolve;
          speechSynthesis.speak(u);
        } catch (e) { resolve(); }
      });
    }

    async function api(path, body, token) {
      const res = await fetch(path, {
        method: body ? 'POST' : 'GET',
        headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) },
        ...(body ? { body: JSON.stringify(body) } : {}),
      });
      if (!res.ok) throw Object.assign(new Error('http_' + res.status), { status: res.status });
      return res.json();
    }

    async function ensureToken() {
      if (deviceToken) return;
      const d = await api('/api/player/token', { name: opts.screenName || 'Tela do bar' });
      deviceToken = d.deviceToken;
      try { localStorage.setItem(store, deviceToken); } catch (e) {}
    }

    // Only guards a song that never starts or stalls. A pause asked for by the bar must never be skipped.
    function armWatchdog() { clearTimeout(watchdog); watchdog = setTimeout(() => finishPlayback('SKIPPED'), 60000); }
    function clearWatchdog() { clearTimeout(watchdog); watchdog = null; }

    function finishPlayback(result) {
      if (!currentlyPlaying) return;
      currentlyPlaying = false; pausedByOwner = false;
      clearWatchdog();
      mount.classList.remove('on');
      try { ytPlayer && ytPlayer.stopVideo && ytPlayer.stopVideo(); } catch (e) {}
      pendingResult = result;
    }

    function rampVolumeUp() {
      if (!ytPlayer) return;
      let v = 18;
      const iv = setInterval(() => {
        v += 8; if (v >= 100) { v = 100; clearInterval(iv); }
        try { ytPlayer.setVolume(v); } catch (e) { clearInterval(iv); }
      }, 120);
    }

    function startPlayback(videoId, requestId) {
      pendingRequestId = requestId; currentlyPlaying = true; pausedByOwner = false;
      mount.classList.add('on');
      const begin = () => {
        mount.innerHTML = '<div class="ytmount" style="width:100%;height:100%"></div>';
        ytPlayer = new YT.Player(mount.firstChild, {
          width: '100%', height: '100%', videoId,
          playerVars: { autoplay: 1, controls: 1, rel: 0, playsinline: 1 },
          events: {
            onReady: (e) => { e.target.setVolume(voiceDone ? 100 : 18); e.target.playVideo(); armWatchdog(); },
            onStateChange: (e) => {
              if (e.data === YT.PlayerState.PLAYING || e.data === YT.PlayerState.BUFFERING) clearWatchdog();
              else if (!pausedByOwner) armWatchdog();
              if (e.data === YT.PlayerState.ENDED) finishPlayback('PLAYED');
            },
            onError: () => finishPlayback('SKIPPED'),
          },
        });
      };
      if (ytApiReady) begin();
      else { const t = setInterval(() => { if (ytApiReady) { clearInterval(t); begin(); } }, 200); }
    }

    const set = (id, text) => { const node = el(id); if (node) node.textContent = text; };

    // The owner's PLAY/PAUSE/SKIP rides along with the state poll.
    function applyCommand(command) {
      if (!command) return;
      if (command === 'SKIP') { if (currentlyPlaying) finishPlayback('SKIPPED'); return; }
      if (!ytPlayer || !currentlyPlaying) return; // pausar uma tela parada nao quer dizer nada
      if (command === 'PAUSE') { pausedByOwner = true; clearWatchdog(); ytPlayer.pauseVideo(); }
      else if (command === 'PLAY') { pausedByOwner = false; ytPlayer.playVideo(); }
    }

    function paint(state) {
      set(ui.venue, state.venue?.name || 'TocaRaul');
      set(ui.queue, (state.queueSize || 0) + ' pedido(s) na fila');
      // O painel pode ser recarregado enquanto uma música já está tocando.
      // Nesse caso currentlyPlaying ainda é falso no navegador, embora a API
      // já tenha um nowPlaying. Sempre sincronizamos os textos com o estado.
      if (state.nowPlaying) {
        set(ui.title, state.nowPlaying.title || '');
        set(ui.artist, state.nowPlaying.artist || '');
        set(ui.dedication, state.nowPlaying.message
          ? state.nowPlaying.message + (state.nowPlaying.visitorName ? ' — ' + state.nowPlaying.visitorName : '')
          : '');
      } else if (!currentlyPlaying) {
        set(ui.title, 'Aguardando pedidos');
        set(ui.artist, '');
        set(ui.dedication, '');
      }
      if (opts.onState) opts.onState(state);
    }

    async function tick() {
      try {
        await ensureToken();
        const state = await api('/api/device/state', null, deviceToken);
        if (state.connection === 'ONLINE') {
          if (opts.onOnline) opts.onOnline(state);
          paint(state);
          applyCommand(state.command);
          if (pendingRequestId && pendingResult) {
            await api('/api/player/complete', { requestId: parseInt(pendingRequestId, 10), result: pendingResult }, deviceToken);
            pendingRequestId = null; pendingResult = null;
          }
          if (started && !pendingRequestId && !currentlyPlaying) {
            const claimed = await api('/api/player/claim', {}, deviceToken);
            const track = claimed.track;
            const videoId = track?.providerId?.replace('youtube:', '');
            if (track && videoId && /^[A-Za-z0-9_-]{11}$/.test(videoId)) {
              if (track.message && state.venue?.announceDedication !== false) {
                voiceDone = false;
                const who = track.visitorName && track.visitorName !== 'Cliente' ? track.visitorName : 'um cliente';
                speak('Uma dedicatória de ' + who + '. ' + track.message + '. E agora, para você: ' + track.title + '.')
                  .then(() => { voiceDone = true; rampVolumeUp(); });
              } else voiceDone = true;
              // paint() leaves the texts alone while a song runs, so write this one's now.
              set(ui.title, track.title || '');
              set(ui.artist, track.artist || '');
              set(ui.dedication, track.message ? track.message + (track.visitorName ? ' — ' + track.visitorName : '') : '');
              startPlayback(videoId, track.id);
            } else if (track) {
              await api('/api/player/complete', { requestId: parseInt(track.id, 10), result: 'SKIPPED' }, deviceToken);
            }
          }
        } else if (opts.onOffline) opts.onOffline(state);
      } catch (e) {
        if (e.status === 401) {
          // O token da tela pode ser revogado/expirar sem que o bar precise
          // voltar para a tela inicial. Remova-o e deixe o próximo ciclo criar
          // outro automaticamente, preservando a sessão e a reprodução.
          try { localStorage.removeItem(store); } catch (x) {}
          deviceToken = null;
          setTimeout(tick, 1000);
          return;
        }
        if (e.status === 410 || e.status === 404) { try { localStorage.removeItem(store); } catch (x) {} deviceToken = null; }
        if (opts.onOffline) opts.onOffline(null);
      }
      setTimeout(tick, currentlyPlaying ? 2000 : 5000);
    }

    return {
      begin() { started = true; },
      isPlaying() { return currentlyPlaying; },
      isPausedByOwner() { return pausedByOwner; },
      run() { tick(); },
    };
  };
})();
