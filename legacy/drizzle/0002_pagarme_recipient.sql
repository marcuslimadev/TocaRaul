ALTER TABLE `venues`
  ADD `pagarmeRecipientId` varchar(64),
  ADD `pagarmeRecipientStatus` varchar(32),
  ADD `pagarmeKycUrl` text,
  ADD `pagarmeKycExpiresAt` timestamp;
