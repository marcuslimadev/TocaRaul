# Deploy do TocaRaul

Produção: **https://tocaraul.lojadaesquina.store** (Hostinger, PHP 8.3).

Não existe build. Os arquivos de `deploy/hostinger/tocaraul-api/` são copiados
como estão para o `public_html` do domínio.

## 1. Onde as coisas ficam no servidor

```
~/domains/tocaraul.lojadaesquina.store/
├── api_config.php          <- SEGREDOS. Fora do public_html, nunca sobrescrever.
└── public_html/            <- tudo de deploy/hostinger/tocaraul-api/ vai aqui
    ├── .htaccess
    ├── *.php
    └── assets/
```

`api_config.php` fica **um nível acima** do `public_html` de propósito: assim
não é acessível pela web. O código o lê com `require __DIR__.'/../api_config.php'`.

## 2. Acesso

| Item | Valor |
| --- | --- |
| Host SSH/SFTP | `145.223.105.168` |
| Porta | `65002` |
| Usuário | `u815655858` |
| Senha | está no `.env.local` (`TOCARAUL_SSH_PASSWORD`), nunca neste arquivo |

## 3. Deploy

```powershell
# envia todos os arquivos alterados e mostra o que subiu
powershell -File ./deploy.ps1

# envia só alguns arquivos
powershell -File ./deploy.ps1 -Only owner.php,assets/player.js

# mostra o que faria, sem enviar
powershell -File ./deploy.ps1 -DryRun
```

O script usa o módulo **Posh-SSH** (já instalado nesta máquina). Ele nunca
envia `api_config.php`.

### Manualmente, se preferir

Qualquer cliente SFTP (FileZilla, WinSCP) com os dados acima, enviando o
conteúdo de `deploy/hostinger/tocaraul-api/` para `public_html/`.

## 4. Configuração de produção (`api_config.php`)

```php
<?php
define('DB_HOST','localhost');
define('DB_NAME','u815655858_tocaraul');
define('DB_USER','u815655858_tocaraul');
define('DB_PASS','...');
define('PUBLIC_APP_URL','https://tocaraul.lojadaesquina.store');

// Login do dono do bar com Google
define('GOOGLE_CLIENT_ID','....apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET','...');   // secret real; sem ele o login falha
```

O resto (chave Asaas, token do webhook, chave do YouTube) **não** fica em
arquivo: é gravado criptografado no banco pelo painel `/admin`.

### Google OAuth

No [Google Cloud Console](https://console.cloud.google.com/apis/credentials),
no cliente OAuth do tipo **Aplicativo da Web**:

- **URI de redirecionamento autorizado**:
  `https://tocaraul.lojadaesquina.store/api/oauth/google/callback`
- Copie o **Client ID** e o **Client secret** para o `api_config.php`.

Atenção: para cliente do tipo *Aplicativo da Web* o `client_secret` é
**obrigatório** na troca do código. PKCE sozinho só basta em clientes públicos
(Desktop/Android/iOS). Se o secret estiver ausente ou com valor de exemplo, o
login do Google falha com "Falha ao autenticar com Google".

## 5. Banco de dados

Não há migração manual: cada página garante seu próprio schema na primeira
execução (`schema()`, `ensure_onboarding_columns()`, `moderation_schema()`).
Subir os arquivos é suficiente.

## 6. Depois de subir — verificação

```bash
curl -s https://tocaraul.lojadaesquina.store/api/health
# {"ok":true,"database":"online",...,"paymentConfigured":true}

curl -s https://tocaraul.lojadaesquina.store/api/commerce/health
curl -s -o /dev/null -w "%{http_code}\n" https://tocaraul.lojadaesquina.store/bar
```

Suíte completa contra produção:

```bash
TOCARAUL_BASE_URL=https://tocaraul.lojadaesquina.store npm run test:quality
```

## 7. Rollback

Tudo versionado no Git. Para voltar um arquivo:

```bash
git checkout <commit> -- deploy/hostinger/tocaraul-api/owner.php
powershell -File ./deploy.ps1 -Only owner.php
```

Antes de uma troca grande, um backup do que está no ar:

```bash
ssh -p 65002 u815655858@145.223.105.168 \
  "cd ~/domains/tocaraul.lojadaesquina.store && tar czf backup-$(date +%F).tar.gz -C public_html ."
```

## Logos dos bares

As logos enviadas pelo painel ficam em `public_html/assets/logos/`, criadas pelo
próprio PHP no primeiro upload. **O deploy nunca sobrescreve nem apaga essa
pasta** (`deploy.ps1` a exclui da lista de envio) — ela é conteúdo do servidor,
não do repositório. Se o upload falhar com "Não consegui gravar a logo no
servidor", confira a permissão de escrita de `public_html/assets`.

## Limite de conexões MySQL da Hostinger

O plano limita o usuário do banco a **500 conexões por hora** — estourar devolve
`SQLSTATE[HY000] [1226] ... max_connections_per_hour` e o site inteiro responde
500 até virar a hora. Um painel aberto a noite toda estoura isso sozinho, então:

- O painel faz **um** pedido por ciclo: `/api/device/state` devolve estado,
  registra o heartbeat e entrega o comando PLAY/PAUSE/SKIP pendente. O ciclo é
  de 2s enquanto toca e 5s parado.
- Esse pedido normalmente **não abre conexão nenhuma**: a resposta fica em
  `~/domains/tocaraul.lojadaesquina.store/cache/venue_<id>.json` por até 20s.
  Quem escreve (pagamento aprovado, comando do dono, música que acabou, playlist
  alterada) apaga o arquivo, e o próximo poll vai ao banco e reescreve — por isso
  um PAUSE continua chegando na hora.
- A pasta `cache/` fica **fora** do `public_html`, é criada pelo próprio PHP e
  pode ser apagada a qualquer momento: ela se refaz sozinha.

**Não use `PDO::ATTR_PERSISTENT`.** Já foi tentado: troca o limite por hora pelo
de conexões *simultâneas*, cada worker do PHP-FPM passa a segurar uma conexão, e
o site cai com 504 em vez de 500.

Se voltar a estourar, o sintoma aparece assim (o log fica só no stderr do PHP):

```bash
ssh -p 65002 u815655858@145.223.105.168   "cd ~/domains/tocaraul.lojadaesquina.store/public_html && php -d error_log=/dev/stderr    -r '\$_SERVER[\"REQUEST_URI\"]=\"/api/health\";\$_SERVER[\"REQUEST_METHOD\"]=\"GET\";include \"index.php\";'"
```
