-- Blocked source URLs (blocklists) are checked for every incoming webmention
-- by (site_id, source), and the Blocklists page now lists them per account.
-- The table had no index but the primary key.
--
-- First remove exact duplicates (same site, same source), keeping the oldest
-- row: the Ruby app inserted a block on every delete without looking. The
-- index is deliberately not unique, so that app keeps working if it is ever
-- put back. Additive otherwise; ALGORITHM=INPLACE, LOCK=NONE keeps the table
-- usable while it builds (6k rows, instant).

DELETE b FROM blocklists b
  JOIN blocklists keep ON keep.site_id = b.site_id AND keep.source = b.source AND keep.id < b.id;

ALTER TABLE `blocklists`
  ADD INDEX `site_source` (`site_id`, `source`(191)),
  ALGORITHM=INPLACE, LOCK=NONE;
