CREATE TABLE `accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tiktokbot_uri` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tiktokbot_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `xmpp_to` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `xmpp_user` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `xmpp_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `aperture_uri` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aperture_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blocklists` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(11) DEFAULT NULL,
  `source` varchar(512) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `blocks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `debugs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `page_url` varchar(255) DEFAULT NULL,
  `domain` varchar(100) DEFAULT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 0,
  `on_success` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `page_url` (`page_url`),
  KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `href` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `verified` tinyint(1) DEFAULT NULL,
  `protocol` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `summary` blob DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `page_id` int(10) unsigned NOT NULL,
  `html` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author_url` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author_name` blob DEFAULT NULL,
  `author_photo` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` blob DEFAULT NULL,
  `content` blob DEFAULT NULL,
  `content_text` blob DEFAULT NULL,
  `published` datetime DEFAULT NULL,
  `published_ts` int(11) DEFAULT NULL,
  `published_offset` int(11) DEFAULT NULL,
  `url` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relcanonical` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_direct` tinyint(1) DEFAULT 1,
  `site_id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `notification_id` int(10) unsigned DEFAULT NULL,
  `syndication` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `swarm_coins` int(11) DEFAULT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `photo` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_links_page` (`page_id`),
  KEY `index_links_token` (`token`),
  KEY `index_links_site` (`site_id`),
  KEY `page_index` (`page_id`,`deleted`,`verified`),
  KEY `protocol_index` (`protocol`),
  KEY `account_index` (`account_id`,`deleted`,`verified`),
  KEY `account_index_sort` (`account_id`,`created_at`,`deleted`,`verified`),
  KEY `account_domain` (`account_id`,`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `html` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `site_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `index_notifications_token` (`token`),
  KEY `index_notifications_created_at` (`created_at`),
  KEY `index_notifications_account` (`account_id`),
  KEY `index_notifications_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `href` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `site_id` int(10) unsigned NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` blob DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_pages_account` (`account_id`),
  KEY `index_pages_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `public_access` tinyint(1) DEFAULT 1,
  `irc_channel` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `xmpp_notify` tinyint(1) DEFAULT 0,
  `callback_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `callback_secret` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archive_avatars` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `index_sites_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

