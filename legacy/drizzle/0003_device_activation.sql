CREATE TABLE IF NOT EXISTS `venueTables` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `venueId` int NOT NULL,
  `label` varchar(32) NOT NULL,
  `qrToken` varchar(32) NOT NULL,
  `status` enum('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
  `createdAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `venueTables_qrToken_unique` (`qrToken`)
);

CREATE TABLE IF NOT EXISTS `devices` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `venueId` int,
  `name` varchar(80) NOT NULL DEFAULT 'TV Principal',
  `activationCode` varchar(12) NOT NULL,
  `activationCodeExpiresAt` timestamp NOT NULL,
  `deviceToken` varchar(96) NOT NULL,
  `status` enum('PENDING_ACTIVATION','ONLINE','OFFLINE','REVOKED') NOT NULL DEFAULT 'PENDING_ACTIVATION',
  `lastSeenAt` timestamp NULL,
  `createdAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `devices_activationCode_unique` (`activationCode`),
  UNIQUE KEY `devices_deviceToken_unique` (`deviceToken`)
);
