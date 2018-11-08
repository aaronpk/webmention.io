ALTER TABLE `links`
ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL AFTER `site_id`;

UPDATE `links`
JOIN `sites` ON `links`.site_id = `sites`.id
SET `links`.account_id = `sites`.account_id;

ALTER TABLE `links` ADD INDEX `account_index` (`account_id`, `deleted`, `verified`);

ALTER TABLE `links` ADD INDEX `account_index_sort` (`account_id`, `created_at`, `deleted`, `verified`);
