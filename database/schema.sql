-- Schema for webmention.io, taken from the production database.
--
-- Legacy tables and columns the application no longer uses (notifications,
-- links.notification_id, debugs) are omitted. Production may still have them;
-- nothing reads or writes them.

CREATE TABLE `accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(1024) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  `pingback_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `tiktokbot_uri` varchar(255) DEFAULT NULL,
  `tiktokbot_token` varchar(255) DEFAULT NULL,
  `xmpp_to` varchar(255) DEFAULT NULL,
  `xmpp_user` varchar(255) DEFAULT NULL,
  `xmpp_password` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `aperture_uri` varchar(255) DEFAULT NULL,
  `aperture_token` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blocklists` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(11) DEFAULT NULL,
  `source` varchar(512) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_source` (`site_id`,`source`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blocks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `href` varchar(512) DEFAULT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `verified` tinyint(1) DEFAULT NULL,
  `protocol` varchar(30) DEFAULT NULL,
  `endpoint_type` enum('account','site') NOT NULL DEFAULT 'account',
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `summary` blob DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `page_id` int(10) unsigned NOT NULL,
  `html` mediumtext DEFAULT NULL,
  `author_url` varchar(256) DEFAULT NULL,
  `author_name` blob DEFAULT NULL,
  `author_photo` varchar(512) DEFAULT NULL,
  `name` blob DEFAULT NULL,
  `content` blob DEFAULT NULL,
  `content_text` blob DEFAULT NULL,
  `published` datetime DEFAULT NULL,
  `published_ts` int(11) DEFAULT NULL,
  `published_offset` int(11) DEFAULT NULL,
  `url` varchar(256) DEFAULT NULL,
  `relcanonical` varchar(255) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `is_direct` tinyint(1) DEFAULT 1,
  `site_id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `syndication` text DEFAULT NULL,
  `token` varchar(20) DEFAULT NULL,
  `swarm_coins` int(11) DEFAULT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `photo` text DEFAULT NULL,
  `video` text DEFAULT NULL,
  `audio` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_links_page` (`page_id`),
  KEY `index_links_token` (`token`),
  KEY `index_links_site` (`site_id`),
  KEY `page_index` (`page_id`,`deleted`,`verified`),
  KEY `protocol_index` (`protocol`),
  KEY `account_index` (`account_id`,`deleted`,`verified`),
  KEY `account_index_sort` (`account_id`,`created_at`,`deleted`,`verified`),
  KEY `account_domain` (`account_id`,`domain`),
  KEY `created_at` (`created_at`),
  KEY `date_endpoint_type` (`created_at`,`endpoint_type`),
  KEY `domain` (`domain`),
  KEY `page_verified_created` (`page_id`,`verified`,`deleted`,`created_at`),
  KEY `page_verified_type` (`page_id`,`verified`,`deleted`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `page_aliases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `href` varchar(512) NOT NULL,
  `page_id` int(10) unsigned NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_href` (`site_id`,`href`(191)),
  KEY `page` (`page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `href` varchar(512) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `site_id` int(10) unsigned NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `name` blob DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_pages_account` (`account_id`),
  KEY `index_pages_site` (`site_id`),
  KEY `href` (`href`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `account_id` int(10) unsigned NOT NULL,
  `public_access` tinyint(1) DEFAULT 1,
  `irc_channel` varchar(255) DEFAULT NULL,
  `xmpp_notify` tinyint(1) DEFAULT 0,
  `callback_url` varchar(255) DEFAULT NULL,
  `callback_secret` varchar(50) DEFAULT NULL,
  `archive_avatars` tinyint(1) DEFAULT 1,
  `verified_at` datetime DEFAULT NULL,
  `verification_checked_at` datetime DEFAULT NULL,
  `verification_error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_domain` (`account_id`,`domain`),
  KEY `index_sites_account` (`account_id`),
  KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
