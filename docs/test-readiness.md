# Homologação web e Android — 09/09/2026

## Modelo aprovado

- Conta Asaas central do TocaRaul; bar informa a chave Pix, sem conta no provedor.
- Repasse diário somente quando o saldo disponível atingir R$ 50 (5.000 centavos).
- Valores abaixo do mínimo acumulam; recebíveis ainda não liquidados não entram no repasse.
- Repasse automático permanece bloqueado até homologar reserva atômica, conciliação,
  estornos e tratamento de transferências com resultado desconhecido.

## Verificado localmente

- Dependências instaladas com pnpm 10.4.1.
- TypeScript e testes automatizados executados; build web gerado em `dist`.
- Provider Asaas restrito ao domínio sandbox, sem redirecionar credenciais.
- Consulta de saldo exige proprietário do bar no backend Node.
- Emulador `emulator-5554` detectado (AVD Pixel_10a, não Android TV).
- Android permite definir URL HTTPS via `-PtocaraulApiBaseUrl=https://...`.

## Ainda não pronto para teste ponta a ponta

- O backend PHP em `deploy/hostinger/tocaraul-api` usa Mercado Pago e tem contratos
  diferentes do backend Node. Alterações Node NÃO atualizam o PHP publicado.
- Migrar cobrança, cadastro do bar e webhooks PHP para Asaas; publicar e verificar.
- Criar bar e cliente de teste, mesas e Pix sandbox; confirmar QR real no site.
- O webhook Node ainda precisa validação de valor/identidade, transação única entre
  pagamento/fila/ledger e diferenciação entre confirmado e liquidado.
- Gerar/aplicar migração do ledger em banco de homologação e validar saldos.
- Não há APK novo instalado: Gradle falha antes da compilação com
  `Unable to establish loopback connection` / `UnixDomainSockets.connect0`.
  Reproduzido com JBR 25 e 17, seletor alternativo e diretório temporário curto.
- Depois do build, instalar APK e verificar ativação, fila, reprodução e reconexão.
- Testar repetição de webhook e repasse, saldo R$49,99 / R$50,00, falha de rede,
  transferência em processamento e estorno. Não liberar dinheiro real nesta etapa.

## Comandos locais

```powershell
npm exec --yes --package=pnpm@10.4.1 -- pnpm install --frozen-lockfile
npm run check
npm test
npm run build
cd Android
.\gradlew.bat :app:assembleDebug
```

As credenciais locais devem permanecer fora do Git e do APK. O Node usa dotenv
com `.env` por padrão; `.env.local` não é carregado automaticamente pelo servidor.
Nenhuma publicação ou transferência bancária foi feita nesta rodada.
