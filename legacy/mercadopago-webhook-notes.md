# Mercado Pago — notas de integração

Fonte oficial consultada: [Webhooks](https://www.mercadopago.com.br/developers/en/docs/your-integrations/notifications/webhooks) e [Notifications](https://www.mercadopago.com.br/developers/en/docs/your-integrations/notifications).

A documentação indica Webhooks como mecanismo recomendado para receber atualizações de pagamento via HTTP POST, com assinatura secreta no cabeçalho `x-signature`. A origem deve ser validada antes de processar o evento. Após responder com HTTP 200 ou 201, o recurso completo deve ser consultado pela API do Mercado Pago; para o tópico `payment`, a documentação aponta `GET https://api.mercadopago.com/v1/payments/{id}`. A confirmação deve ser idempotente, pois notificações podem ser reenviadas quando a entrega não é reconhecida.

A implementação atual mantém uma interface `PaymentProvider`, exige assinatura HMAC e exige `status: approved` no payload de entrada. A integração produtiva ainda depende de credenciais e da validação específica do formato `x-signature` do Mercado Pago, que deve substituir o helper genérico antes da operação real.
