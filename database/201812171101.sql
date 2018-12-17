ALTER TABLE `links`
ADD COLUMN `domain` VARCHAR(255) NOT NULL DEFAULT '' AFTER `href`;

ALTER TABLE `links` ADD INDEX `account_domain` (`account_id`, `domain`);

UPDATE `links`
SET `domain` = SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(`href`, '/', 3), '://', -1), '/', 1), '?', 1);

CREATE TABLE `blocks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
