-- Other URLs a page has been mentioned under.
--
-- A mention is filed under the target's canonical URL: the URL after the
-- site's own redirects and its rel=canonical, with any #fragment removed.
-- Every other form that led to that page (the URL as the sender gave it, an
-- old URL that now redirects, a fragment URL) is kept here, so later mentions
-- and API queries for it find the same page without fetching anything.
--
-- Additive; the Ruby app never reads it. href(191) keeps the unique key under
-- the index size limit; matches still compare the whole column.

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
