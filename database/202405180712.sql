ALTER TABLE `links` ADD COLUMN `endpoint_type` ENUM('account','site') NOT NULL DEFAULT 'account' AFTER `protocol`;
ALTER TABLE `links` ADD INDEX `date_endpoint_type` (`created_at`, `endpoint_type`);
