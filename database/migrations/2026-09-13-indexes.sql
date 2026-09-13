-- Target lookups (/api/count, /api/mentions?target=, and every incoming
-- webmention) query pages by href, and the per-domain endpoint queries sites
-- by domain. Neither column was indexed, so each lookup scanned the table.
--
-- Additive only; no data changes.

ALTER TABLE `pages` ADD INDEX `href` (`href`);
ALTER TABLE `sites` ADD INDEX `domain` (`domain`);
