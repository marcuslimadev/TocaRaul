# TocaRaul

Jukebox digital para bares. O cliente escaneia o QR Code da mesa, escolhe a
música, paga por Pix e a música entra na fila que toca na tela do bar.

## Regra de negócio

- **Preço**: R$ 5,00 por música. A dedicatória é **opcional e grátis**.
- **Divisão**: 70% para o bar, 30% para a plataforma.
- **Recebimento**: o TocaRaul recebe numa conta Asaas central e repassa para a
  chave Pix do bar. O bar **não** abre conta em provedor de pagamento.
- **Repasse**: mínimo de R$ 50,00 acumulados. Repasse automático ainda está
  bloqueado até homologar conciliação e estornos.
- **Ambiente**: Asaas **sandbox**. Nenhum dinheiro real circula ainda
  (`asaas.php` aponta fixo para `api-sandbox.asaas.com`).
- **Fila**: pedido pago tem prioridade sobre a playlist do bar. Pedido só entra
  na fila depois do pagamento confirmado.
- **Conteúdo**: o bar é responsável, por contrato, pelo uso público do conteúdo
  do YouTube no estabelecimento.
- **Moderação**: busca restrita a música (categoria + tópico do YouTube,
  `safeSearch=strict`), filtro de palavrão embutido, mais listas de palavras e
  músicas banidas por bar.

## Jornada

1. O dono entra em `/` e clica em cadastrar (ou entra com Google).
2. Preenche bar, CPF/CNPJ e chave Pix em `/cadastro` — sem precisar de TV.
3. Cai logado no painel `/bar`, que já traz o player do YouTube embutido.
4. Envia a logo do bar e monta a playlist buscando músicas pelo nome.
5. Imprime o QR Code de pedidos e cola nas mesas.
6. O cliente pede e paga em `/j/<token>`; a fila toca sozinha na tela do bar.
7. No painel o dono controla tocar/pausar/pular, bane palavras e músicas, e vê
   o saldo.

### A tela do bar

O palco em `/bar` é uma peça só, em 16:9, pronta para projetor ou TV:

```
┌──────────────────────────────┬──────────────┐
│ [logo do bar]                │  dedicatória │
│                              │    escrita   │
│         PLAYER YOUTUBE       ├──────────────┤
│                              │   QR Code    │
└──────────────────────────────┴──────────────┘
```

A logo fica sobreposta ao vídeo, a dedicatória do pedido que está tocando fica
escrita na coluna da direita, e o QR Code de pedidos fica sempre à vista.

## Stack

**Só duas linguagens**: PHP no servidor e JavaScript no navegador. Sem build,
sem framework, sem transpilação — o que está em `deploy/hostinger/tocaraul-api/`
é exatamente o que roda em produção.

| Caminho | O que é |
| --- | --- |
| `deploy/hostinger/tocaraul-api/` | O produto inteiro (páginas + API em PHP) |
| `deploy/hostinger/tocaraul-api/assets/` | JS e imagens servidos ao navegador |
| `scripts/` | Servidor local de teste e suítes automatizadas (Node, só ferramenta) |
| `docs/` | Pesquisa de apoio |
| `legacy/` | Versões abandonadas — ver abaixo |

### Páginas

| Rota | Arquivo | Para quem |
| --- | --- | --- |
| `/` | `home.php` | Visitante |
| `/cadastro` | `signup.php` | Dono cadastrando o bar |
| `/bar` | `owner.php` | Dono: painel com o player do YouTube embutido |
| `/j/<token>` | `customer.php` | Cliente na mesa |
| `/parceiro` | `partner.php` | Parceiro comercial que cadastra bares |
| `/admin` | `admin.php` | Operação TocaRaul |
| `/privacy` | `privacy.php` | Política de privacidade |
| `/auth/google/*` | `google_bar.php` | Login do dono com Google |

### Como rodar local

```bash
# banco de teste (MariaDB do XAMPP) na porta 3307 + servidor PHP em :8787
node scripts/start-php-e2e.mjs

npm run test:signup    # cadastro do bar sem tela
npm run test:partner   # parceiro e moderação
npm run test:quality   # jornada completa: pedido pago toca no painel
```

`.env.local` guarda as credenciais locais e nunca vai para o Git.

## legacy/

Tentativas anteriores, mantidas só como histórico. **Nada ali roda em produção
e nada ali reflete a regra de negócio atual.** Pode apagar a pasta inteira:

- `legacy/server`, `legacy/client`, `legacy/shared`, `legacy/drizzle` — MVP em
  Node/React/tRPC/Drizzle, substituído pelo PHP.
- `legacy/Android` — app Android TV em Kotlin, substituído pelo player web.
- `legacy/todo.md`, `legacy/google-play-*`, `legacy/*.md` — planos da fase
  Android e da fase Mercado Pago (o pagamento hoje é Asaas).

```bash
git rm -r legacy   # quando quiser eliminar de vez
```

## Deploy

Ver [deploy.md](deploy.md).
