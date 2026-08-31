# Pesquisa de provedores para split Pix

## Pagar.me
Fonte: https://docs.pagar.me/docs/pix-1
A documentação informa que o processo de recebimento transacional usa o Pagar.me como instituição de recebimento e que existe suporte a transações Pix com dois ou mais recebedores por meio da funcionalidade de split. A documentação também mantém seções específicas para Marketplace, Recebedores e dados mínimos de cadastro.

## Zoop
Fonte: https://docs.zoop.co/docs/implementando-split-de-pagamentos
A documentação oficial informa que, no Pix, o split a priori deve ser definido no momento da criação do Pix. No split a posteriori, a operação deve ocorrer logo após o evento `receivable.created`, porque o pagamento do recebível ocorre em D+1. A API permite consultar recebíveis e regras de split; a exclusão só é possível antes do recebimento do recebível. A documentação também possui seções de credenciamento de vendedores, eventos de webhook, Pix online e regras de payout.

## Observação
As fontes acima indicam que Pagar.me e Zoop são candidatos fortes para um marketplace com split Pix. É necessário complementar com Efí, Asaas e iugu antes da recomendação final, verificando especialmente onboarding/KYC, contas recebedoras, timing do split, estornos, idempotência, webhooks e elegibilidade comercial.

## Asaas
Fonte: https://docs.asaas.com/docs/split-de-pagamentos
O Asaas distribui parte do valor recebido em uma cobrança entre outras contas Asaas. Cada recebedor precisa ter uma conta Asaas e o fluxo usa `walletId`; o repasse pode ser fixo ou percentual e é calculado sobre o `netValue` após taxas. A documentação menciona webhooks `PAYMENT_SPLIT_DONE`, divergência de split e estorno dos repasses relacionados quando a cobrança é estornada. O modelo é forte para marketplace com subcontas, mas exige que cada estabelecimento tenha conta Asaas.

## Pagar.me — detalhe adicional
Fonte: https://docs.pagar.me/docs/pix-1
A documentação consultada confirma Pix com dois ou mais recebedores usando split. O Pagar.me também possui documentação específica de recebedores e marketplace, com requisitos regulatórios de cadastro.

## Zoop — detalhe adicional
Fonte: https://docs.zoop.co/docs/implementando-split-de-pagamentos
No Pix, a regra pode ser aplicada na criação ou logo após `receivable.created`, com consulta posterior dos recebíveis e regras de split. O fluxo é mais orientado a marketplace/conta gráfica e credenciamento de sellers.

## Efí
Fonte: https://dev.efipay.com.br/docs/api-pix/split-de-pagamento-pix/
A Efí informa que o split Pix só pode ocorrer entre contas Efí e aceita no máximo 20 contas para repasse. É necessário fornecer uma conta digital Efí válida e não é permitido dividir para a própria conta. A documentação oferece endpoints para configurar o split, vincular a cobrança Pix a uma configuração, consultar, remover o vínculo e solicitar devolução de cobrança Pix com split.

## iugu
Fonte: https://dev.iugu.com/docs/split-de-pagamentos
A iugu oferece split para contas do plano Marketplace usando Conta Mestre e Subcontas. A conta que cria a fatura pode dividir e transferir valores automaticamente quando o pagamento ocorre. Pela API, há parâmetros específicos para Pix em valor fixo ou percentual, além de composição de valores. A documentação indica que o split via API não tem limite de contas no objeto de regras, enquanto o fluxo Alia tem limite de uma conta mestre/subconta.

## Síntese provisória
Para o TocaRaul, Pagar.me, Zoop, Asaas, Efí e iugu possuem caminhos documentados para split Pix ou marketplace. A escolha depende principalmente de: necessidade de subcontas próprias, onboarding dos bares, disponibilidade comercial para o modelo de negócio, timing do split Pix, webhooks de liquidação, estornos e conciliação.

## Onboarding automático — Asaas
Fonte: https://docs.asaas.com/reference/criar-subconta
O endpoint de criação de subconta é descrito como adequado a White Labels, marketplaces e ERPs. A própria documentação dá como exemplo a criação automática de uma subconta para cada cliente da plataforma. A resposta retorna `walletId` e uma `apiKey` da subconta; a chave deve ser armazenada com segurança porque é retornada apenas uma vez. Há período de avaliação regulatória e limites iniciais para novos clientes de subcontas, portanto a aprovação comercial/regulatória precisa ser confirmada.

## Onboarding automático — Pagar.me
Fonte: https://docs.pagar.me/page/api-v5-adi%C3%A7%C3%A3o-do-fluxo-de-prova-de-vida
O Pagar.me documenta a criação de recebedor via `POST /recipient`, seguida da criação de link KYC via `POST /recipients/{id}/kyc_link`. O marketplace renderiza o link/QR Code para o recebedor concluir a prova de vida; o status é acompanhado via webhook `recipient.updated`. O link KYC expira em 20 minutos. O recebedor só consegue movimentar o saldo após credenciamento concluído e status `active`.

## Implicação
“As onboarding automático” deve significar que o TocaRaul inicia a criação e conduz o fluxo dentro da sua interface, mas o estabelecimento ainda precisa fornecer dados e concluir KYC/prova de vida. Nenhum provedor sério elimina a validação regulatória do recebedor.
