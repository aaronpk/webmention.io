ALTER TABLE `accounts`
ADD COLUMN `pingback_enabled` TINYINT(4) NOT NULL DEFAULT 0 AFTER `token`