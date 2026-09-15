-- Archived sites: a site its owner no longer uses. It refuses new webmentions
-- and is skipped by verification rechecks, but keeps the webmentions it has,
-- which stay in the API and the export. NULL is a live site.
--
-- Additive; the Ruby app never reads the column, so if it were put back in
-- front of this database, archived sites would accept webmentions again.

ALTER TABLE `sites`
  ADD COLUMN `archived_at` datetime DEFAULT NULL,
  ALGORITHM=INPLACE, LOCK=NONE;
