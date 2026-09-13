-- One site per domain per account.
--
-- The Ruby app's add-site form never checked for an existing row, so a double
-- submit, or adding the domain the settings page had already created, left
-- duplicates (524 groups in production as of 2026-09-13). The new app looks
-- before it inserts, but two requests at once could still both insert. This
-- index makes the second one fail instead.
--
-- RUN 2026-09-14-dedupe-sites.php FIRST. While duplicates exist this ALTER
-- fails with "Duplicate entry" and changes nothing.
--
-- Notes:
-- * The column collation is utf8mb4_unicode_ci, so `Example.com` and
--   `example.com` count as the same domain. The new app stores lowercase.
-- * NULL domains would still be allowed more than once; there are none.
-- * The Ruby app, if it is ever put back, gets an error from its add-site
--   form on a duplicate rather than a second row. That is the point.
-- * Additive; ALGORITHM=INPLACE, LOCK=NONE keeps `sites` readable and
--   writable while it builds. The table is small (8k rows).

ALTER TABLE `sites`
  ADD UNIQUE INDEX `account_domain` (`account_id`, `domain`),
  ALGORITHM=INPLACE, LOCK=NONE;
