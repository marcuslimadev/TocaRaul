CREATE TABLE `payments` (
	`id` int AUTO_INCREMENT NOT NULL,
	`requestId` int NOT NULL,
	`provider` varchar(40) NOT NULL DEFAULT 'mercadopago',
	`externalId` varchar(160),
	`status` enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
	`amountCents` int NOT NULL,
	`pixCopyPaste` text,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `payments_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `songRequests` (
	`id` int AUTO_INCREMENT NOT NULL,
	`venueId` int NOT NULL,
	`visitorName` varchar(80) NOT NULL,
	`tableCode` varchar(12),
	`providerId` varchar(160) NOT NULL,
	`title` varchar(180) NOT NULL,
	`artist` varchar(180) NOT NULL,
	`message` varchar(180),
	`amountCents` int NOT NULL,
	`status` enum('AWAITING_PAYMENT','PAID','QUEUED','PLAYING','PLAYED','SKIPPED','CANCELLED','FAILED') NOT NULL DEFAULT 'AWAITING_PAYMENT',
	`queuePosition` int,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `songRequests_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `venues` (
	`id` int AUTO_INCREMENT NOT NULL,
	`ownerId` int NOT NULL,
	`code` varchar(16) NOT NULL,
	`name` varchar(120) NOT NULL,
	`musicPriceCents` int NOT NULL DEFAULT 300,
	`dedicationPriceCents` int NOT NULL DEFAULT 200,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `venues_id` PRIMARY KEY(`id`),
	CONSTRAINT `venues_code_unique` UNIQUE(`code`)
);
