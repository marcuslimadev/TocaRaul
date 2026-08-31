# Project TODO — TocaRaul

## Entregue nesta versão

- [x] Projeto inicializado como aplicação full-stack
- [x] Requisitos técnicos e direção de estilo recebidos
- [x] Nome da marca definido: TocaRaul
- [x] Assinatura definida: “Pediu. Tocou.”
- [x] Identidade visual elegante com tema escuro, acentos violeta e tipografia editorial
- [x] Experiência inicial do cliente por QR Code e código do ambiente
- [x] Busca e seleção de faixa no catálogo
- [x] Campo opcional de dedicatória com limite de caracteres
- [x] Tela de pedido criado e estado aguardando Pix
- [x] Painel de gestão com métricas, configuração de preço e código rápido
- [x] Fila visual com tocar agora, pular e remover
- [x] Tela de TV em modo paisagem com música atual, próximas, QR Code e dedicatória
- [x] Testes Vitest das regras de pagamento e fila
- [x] Validação visual desktop e mobile

## Próximos passos de produto

- [ ] Persistir ambientes, músicas, pedidos e pagamentos no banco de dados
- [x] Criar procedimentos tRPC para catálogo, pedido, fila e configuração
- [ ] Implementar sincronização persistente entre celular, painel e TV via WebSocket ou SSE
- [ ] Integrar geração real da cobrança Pix no Mercado Pago
- [ ] Integrar webhook autenticado de confirmação do Mercado Pago com idempotência
- [x] Garantir no backend que somente pagamentos confirmados possam mudar o pedido para QUEUED
- [ ] Implementar OAuth por estabelecimento e comissão/split da plataforma
- [ ] Conectar player real da TV por uma interface desacoplada do provedor
- [ ] Implementar autenticação e permissões específicas do estabelecimento
- [ ] Validar ponta a ponta com contas de teste do Mercado Pago antes da produção

## Histórico

- [x] Documento de referência anexado: /home/ubuntu/upload/jukebox_mvp_especificacao.md
- [x] Webhooks do Mercado Pago verificados na documentação oficial
- [x] A especificação de produção registra que Pix confirmado é pré-requisito da fila

- [x] Implementar entrada por código do ambiente no cliente, com validação e resolução de estabelecimento/mesa
- [x] Adicionar suporte verificável a acesso via QR Code por parâmetros de ambiente e mesa
- [x] Persistir e sincronizar a identificação do ambiente e da mesa entre as jornadas

- [x] Implementar resolução real de ambiente e mesa a partir do código informado ou dos parâmetros da rota, sem depender de um único código fixo
- [x] Propagar ambiente e mesa resolvidos para todas as jornadas (cliente, painel e TV) usando estado compartilhado/persistência verificável
- [x] Adicionar testes cobrindo entrada por código/QR e a sincronização da identificação do ambiente/mesa

- [x] Exibir e consumir venue.table também no painel, garantindo ambiente e mesa consistentes nas três jornadas
- [x] Adicionar testes para parsing de rota/query e persistência da identificação via localStorage
- [x] Adicionar teste de integração da propagação do ambiente e mesa do cliente para painel e TV

- [ ] Adicionar teste de UI/componente cobrindo persistência e reidratação de venue via localStorage
- [ ] Adicionar teste de integração que altere ambiente e mesa no cliente e verifique a atualização refletida no painel e na TV
- [ ] Avaliar persistência do estado compartilhado fora do estado local para sincronização real entre dispositivos

- [x] Adicionar procedimento tRPC para catálogo de músicas e procedimento tRPC para configuração do estabelecimento e preço
- [x] Validar confirmação de pagamento no backend antes de promover pedido para QUEUED, com autenticidade e falha quando nada for atualizado
- [x] Adicionar testes para promoção inválida sem pagamento e para os procedimentos tRPC de catálogo e configuração

- [x] Validar explicitamente status approved no payload do webhook antes de promover para QUEUED
- [x] Testar rejeição de webhook assinado com status diferente de approved e ausência de atualização
- [x] Manter aberta a confirmação por provider real até configurar credenciais de produção

- [x] Manter o provedor Mercado Pago mockado no MVP, sem solicitar credenciais reais
- [x] Validar que o fluxo mockado não libera a fila sem confirmação explícita de pagamento

- [x] Integrar o MockPaymentProvider ao fluxo de criação e consulta de pagamentos do MVP
- [x] Conectar a confirmação mockada ao estado da fila e bloquear QUEUED antes da confirmação
- [x] Testar o ciclo mockado pendente → confirmado → QUEUED

- [x] Adicionar procedure de consulta de pagamento/status do pedido usando o MockPaymentProvider
- [ ] Conectar a confirmação mockada da UI ao backend e refletir a fila nas jornadas
- [ ] Testar o ciclo completo create AWAITING_PAYMENT → mockConfirm → QUEUED

- [ ] Fazer payments.mockStatus consultar efetivamente MockPaymentProvider.getPayment
- [ ] Retornar também o status do pedido na consulta mockada
- [ ] Testar a procedure de status mockado e o uso efetivo do provider
