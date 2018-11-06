CREATE TABLE `debug` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `page_url` varchar(255) DEFAULT NULL,
  `debug` tinyint(4) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
