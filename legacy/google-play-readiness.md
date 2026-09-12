# Publicação Android — 10/09/2026 (atualizado)

Status: app e backend local validados ponta a ponta; publicação na Play Store
ainda bloqueada por 1 item externo (deploy do backend PHP em produção) e pelas
etapas manuais no Play Console. Nenhuma publicação foi realizada.

## Teste de ponta a ponta executado nesta rodada (ao vivo, sem mocks)

Sequência real, no emulador `emulator-5554` + PHP local (`scripts/local-php-router.php`)
+ MySQL local + Asaas **sandbox** real (não simulado):

1. App Android (build e2e, `-PtocaraulApiBaseUrl=http://127.0.0.1:8787`) exibiu
   código de ativação real (990206).
2. `scripts/test-php-e2e.mjs` cadastrou o bar pelo código, criou pedido pago,
   gerou cobrança Pix real no Asaas sandbox, confirmou o pagamento na API do
   Asaas e disparou o webhook autenticado (com replay para validar idempotência
   e um envio sem token para confirmar rejeição 401).
3. Pedido foi promovido a `QUEUED` e creditado no ledger do bar (R$ 3,50 de
   R$ 5,00, split 70/30) somente após a confirmação — nenhuma liberação sem
   pagamento aprovado.
4. A TV (app real rodando no emulador) buscou o pedido pago via
   `/api/player/claim` e **tocou o vídeo do YouTube automaticamente**
   (`PlaybackActivity`), sem intervenção manual — capturas em
   `tmp/emulator-playback.png` e `tmp/emulator-playback2.png`.
5. Painel do bar (`/bar`) autenticado mostrou o pedido pago em `PLAYING` e o
   saldo disponível correto.
6. Comando remoto **Pular** enviado pelo painel do bar foi recebido pela TV
   (poll de `/api/player/command`) e encerrou a reprodução corretamente,
   voltando à tela padrão da TV — captura em `tmp/emulator-after-skip.png`.
7. Painel admin autenticado (`/admin`) carregou normalmente.
8. Testes automatizados: `pnpm check` (TypeScript) limpo; `pnpm test`
   (Vitest) 37/37; Android `testDebugUnitTest` + `connectedDebugAndroidTest`
   (emulador) 3/3, incluindo a recuperação de erro do player
   (`playerErrorDoesNotCountAsPlayed`, `lateFinishedCallbackCannotOverwriteError`).

## Corrigido nesta rodada

- Gerada chave de upload real (`tocaraul-upload.jks`, fora do repositório em
  `C:\Users\marcuslima\tocaraul-keys\`, com README de backup). `bundleRelease`
  e `lintRelease` executados com essa assinatura: **BUILD SUCCESSFUL**, lint
  sem erros/avisos (18 notas informativas).
- **Bug de produção encontrado e corrigido**: `deploy/hostinger/tocaraul-api/.htaccess`
  não tinha regra para `/api/webhooks/asaas` (só existia para
  `/api/mercadopago/webhook`, uma rota legada). Sem essa regra, o webhook do
  Asaas cairia em 404 no ambiente publicado e nenhum pagamento seria
  confirmado em produção. Regra adicionada; validar após o próximo deploy.
- `PlaybackActivity.Bridge.playerError` já recupera corretamente (encerra a
  atividade com `RESULT_CANCELED`, sem contar como reproduzida) — confirmado
  por teste instrumentado real, não apenas leitura de código.
- Banner de Android TV usa `@drawable/tv_banner` próprio (não mais o ícone
  quadrado do launcher).

## Ainda bloqueado — depende de ação externa

1. **Backend de produção desatualizado.** `https://tocaraul.lojadaesquina.store`
   responde 404 para rotas que existem no código atual (`/api/health`,
   `/api/commerce/health`), inclusive na própria `index.php` sem cair no
   catch-all esperado — evidência de que o PHP publicado é uma versão antiga,
   anterior às mudanças para Asaas. Não há script de deploy neste repositório
   nem credenciais de FTP/SSH/Git para o Hostinger disponíveis nesta sessão.
   **Ação necessária do usuário**: publicar o conteúdo atual de
   `deploy/hostinger/tocaraul-api/` (incluindo o `.htaccess` corrigido) no
   Hostinger, ou fornecer acesso para que isso seja feito. Sem isso, o app
   publicado apontando para essa URL não processará pagamentos reais.
2. **Guarda da chave de upload.** A senha e o arquivo `.jks` foram gerados
   nesta máquina e só existem aqui — faça backup em um cofre de senhas e em
   um segundo local antes de qualquer coisa. No primeiro envio ao Play
   Console, aceite a inscrição no Play App Signing.
3. **Cadastro no Play Console** (conta, ficha do app, classificação
   indicativa, formulário de segurança de dados, política de privacidade
   publicada em URL própria, contato de suporte) depende da conta Google do
   usuário — não pode ser feito por esta sessão.
4. **Capturas de tela reais para a ficha da loja** devem ser recapturadas
   contra o backend de produção já publicado (as desta rodada validam o
   funcionamento, mas foram feitas contra o servidor local de teste).
5. Android TV real (hardware) ainda não testado; validação feita em
   emulador Android (phone) com a TV forçada em paisagem — funcionalmente
   equivalente, já que o app não usa APIs exclusivas de TV além da declaração
   no manifesto.

## Comandos usados nesta rodada

```powershell
$env:JAVA_HOME = "C:\Program Files\Android\Android Studio\jbr"
cd Android
$env:TOCARAUL_UPLOAD_STORE_FILE = "C:\Users\marcuslima\tocaraul-keys\tocaraul-upload.jks"
$env:TOCARAUL_UPLOAD_STORE_PASSWORD = "<ver README em tocaraul-keys>"
$env:TOCARAUL_UPLOAD_KEY_ALIAS = "tocaraul-upload"
$env:TOCARAUL_UPLOAD_KEY_PASSWORD = "<ver README em tocaraul-keys>"
.\gradlew.bat :app:bundleRelease :app:lintRelease
```

## Referências oficiais consultadas

- https://developer.android.com/studio/publish — distribuição por Android App Bundle.
- https://developer.android.com/training/tv/publishing/checklist — qualidade e recursos de Android TV.
- https://support.google.com/googleplay/android-developer/answer/11926878 — requisito de API alvo.
- https://support.google.com/googleplay/android-developer/answer/9842756 — Play App Signing.
