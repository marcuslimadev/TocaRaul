ALTER TABLE `venues`
  ADD `splitBarPercent` int NOT NULL DEFAULT 70,
  ADD `splitPlatformPercent` int NOT NULL DEFAULT 30,
  ADD `ownerDocument` varchar(32),
  ADD `ownerPhone` varchar(32),
  ADD `pixKeyType` varchar(32),
  ADD `pixKey` varchar(180),
  ADD `splitAcceptedAt` timestamp NULL,
  ADD `termsAcceptedAt` timestamp NULL,
  ADD `mercadoPagoUserId` varchar(64),
  ADD `mercadoPagoAccessToken` text,
  ADD `mercadoPagoRefreshToken` text,
  ADD `mercadoPagoPublicKey` text,
  ADD `mercadoPagoTokenExpiresAt` timestamp NULL;
