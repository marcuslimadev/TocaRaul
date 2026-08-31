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
- [x] Conectar a confirmação mockada da UI ao backend e refletir a fila nas jornadas
- [x] Testar o ciclo completo create AWAITING_PAYMENT → mockConfirm → QUEUED

- [x] Fazer payments.mockStatus consultar efetivamente MockPaymentProvider.getPayment
- [x] Retornar também o status do pedido na consulta mockada
- [x] Testar a procedure de status mockado e o uso efetivo do provider

- [x] Incorporar a referência visual da tela Pix ao modal de solicitação e confirmação do cliente
- [x] Reforçar hierarquia de QR Code, resumo de valores e estado “Aguardando pagamento” sem sugerir pagamento real
- [x] Revalidar visualmente cliente, painel e TV após o refinamento

- [x] Refinar também o modal de solicitação do cliente com o padrão visual Pix da referência
- [x] Ajustar e validar visualmente o resumo de valores junto do QR Code e estado de pagamento
- [x] Executar validação visual explícita do painel e da tela de TV após o refinamento

- [x] Adaptar a tela principal do cliente para a referência mobile-first com cards, CTA amarelo e navegação inferior
- [x] Adicionar ações visuais de categoria e dedicatória sem alterar a regra de pagamento mockado
- [x] Validar a tela principal em viewport móvel após o refinamento

- [x] Garantir que a experiência do cliente seja apresentada e validada como site web responsivo, sem dependência de aplicativo nativo
- [x] Remover ou evitar qualquer linguagem de instalação de app na jornada web do cliente

- [x] Priorizar o QR Code fixado na mesa como entrada principal do site, resolvendo bar e mesa automaticamente
- [x] Manter o código digitável apenas como alternativa de contingência
- [x] Validar uma URL de QR Code específica por mesa e a identificação exibida no site

- [x] Refinar a jornada do cliente para abrir já contextualizada por QR Code de mesa como fluxo principal
- [x] Ajustar a copy e a hierarquia visual do acesso manual para deixá-lo explicitamente como contingência
- [x] Adicionar teste ou validação visual específica da abertura por URL de mesa mostrando bar e mesa automaticamente

- [x] Implementar na tela principal do cliente a navegação inferior e CTA amarelo por música conforme a referência mobile-first
- [x] Adicionar ações visuais de categoria na listagem do cliente e manter a dedicatória conectada ao fluxo mockado
- [x] Registrar validação visual explícita da tela principal refinada mostrando cards, CTA e navegação inferior

- [x] Validar visualmente o modal de solicitação e a confirmação Pix com resumo, QR Code e estado aguardando
- [x] Executar validação visual específica do painel após o refinamento
- [x] Executar validação visual específica da tela de TV após o refinamento

- [ ] Criar estado demonstrativo para abrir diretamente o modal de solicitação por URL
- [ ] Capturar screenshot do modal de solicitação com resumo de valores antes do Pix

- [x] Fazer payments.mockStatus consultar o provider também no caminho de requests em memória
- [x] Adicionar teste de payments.mockStatus cobrindo status do provider e status do pedido
- [x] Adicionar teste do ciclo mockCreate → mockStatus pendente → mockConfirm → mockStatus aprovado/QUEUED

- [x] Adicionar teste específico que diferencie a fonte de verdade do provider do estado em memória
- [x] Expor uma seam testável para observar a consulta do MockPaymentProvider no status do pedido

- [ ] Conectar cliente, painel e TV aos dados de fila via tRPC em vez de depender apenas dos dados estáticos
- [ ] Atualizar a confirmação mockada para consultar/invalidate a fila após mockConfirm
- [ ] Adicionar teste de integração da fila compartilhada após confirmação do pedido
