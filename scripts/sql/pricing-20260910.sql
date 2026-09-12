-- Preços aprovados: música R$5; dedicatória R$1.
-- Executar no banco TocaRaul; pedidos e pagamentos anteriores são preservados.
START TRANSACTION;
SELECT id, name, musicPriceCents, dedicationPriceCents FROM venues FOR UPDATE;
UPDATE venues SET musicPriceCents=500, dedicationPriceCents=100;
SELECT id, name, musicPriceCents, dedicationPriceCents FROM venues;
COMMIT;
