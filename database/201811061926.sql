ALTER TABLE `links` ADD COLUMN `protocol` VARCHAR(30) DEFAULT null AFTER verified;
ALTER TABLE `links` ADD INDEX `protocol_index` (`protocol`);
