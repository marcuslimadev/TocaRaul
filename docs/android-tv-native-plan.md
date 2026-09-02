# TocaRaul Android TV nativo

O app Android TV e escrito em Kotlin e instalado na TV ou TV Box do bar.

## Decisao de arquitetura

- O app Android TV nunca conecta diretamente no MySQL remoto.
- O app fala com a API publica do TocaRaul por HTTPS.
- A API do TocaRaul, escrita no backend, acessa o MySQL remoto.
- Credenciais de banco, Pagar.me e provedores de musica ficam somente no backend.

Conectar o APK direto no MySQL exigiria embutir host, usuario e senha no app, expondo o banco a engenharia reversa e acesso indevido.

## Escopo do app

- Ativacao da TV por codigo temporario.
- Persistencia do token do dispositivo.
- Heartbeat periodico.
- Busca do estado atual da jukebox.
- Tela de player.
- Tela offline/reconectando.
- Dedicatoria em overlay.
- Fila e QR Code do bar.

## API minima

- `POST /api/device/activate`
- `POST /api/device/heartbeat`
- `GET /api/device/state`

## Primeiro milestone

1. Rodar o app no emulador Android TV.
2. Exibir `ActivationScreen`.
3. Ativar a TV pelo backend.
4. Persistir `deviceToken`.
5. Abrir `PlayerScreen`.
6. Sincronizar estado pelo backend.
7. Simular pedido e dedicatória.
8. Ver a TV atualizar sem depender da rede local do cliente.
