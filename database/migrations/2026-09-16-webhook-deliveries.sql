-- Web hook deliveries (issue 231): every POST to a site's callback URL,
-- with what was sent, what came back and how long it took, so the site's
-- settings page can show whether the hook fires and why it fails, and can
-- re-send one. Only the newest 50 per site are kept.
--
-- Additive; the Ruby app never reads this table.

CREATE TABLE `webhook_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `link_id` int(10) unsigned DEFAULT NULL,
  `kind` varchar(16) NOT NULL,
  `url` varchar(255) NOT NULL,
  `status_code` smallint(5) unsigned DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `duration_ms` int(10) unsigned NOT NULL DEFAULT 0,
  `request_body` mediumtext NOT NULL,
  `response_body` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `site_created` (`site_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
