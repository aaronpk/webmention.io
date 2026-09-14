-- Moderation: hold new mentions for review, mute sources or authors without
-- deleting, and keep a record of deletions.
--
-- links.status says why a row has verified = 0: "pending" (held for the
-- owner's review) or "hidden" (a mute rule matches). NULL is an ordinary
-- published mention. verified stays the switch every reader honours, so the
-- API, counts, web hooks and the Ruby app all leave held and hidden mentions
-- out without any change. Approving or un-muting sets verified = 1 again.
--
-- sites.moderation is the site's hold policy: NULL or "off" publishes at
-- once, "first" holds mentions from a source domain the account has never
-- published a mention from, "all" holds everything.
--
-- mutes are the account's mute rules: kind "source" matches the mention's
-- URL, "author" its author URL; a pattern containing :// is a URL prefix,
-- otherwise a hostname (and its subdomains).
--
-- Additive; the Ruby app never reads the new column or table.

ALTER TABLE `links`
  ADD COLUMN `status` varchar(16) DEFAULT NULL,
  ADD INDEX `account_status` (`account_id`, `status`),
  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE `sites`
  ADD COLUMN `moderation` varchar(16) DEFAULT NULL,
  ALGORITHM=INPLACE, LOCK=NONE;

CREATE TABLE `mutes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `pattern` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
