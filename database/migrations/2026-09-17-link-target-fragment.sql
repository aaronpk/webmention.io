-- The #fragment a webmention was sent to.
--
-- Mentions are filed under the target's fragment-less URL, so a query for
-- ".../post" returns everything sent to ".../post#anything" (issue 106).
-- That lost the distinction for sites whose content is addressed only by
-- fragment, such as one page per image in a gallery: their owners could no
-- longer ask for one fragment, and two likes from one person to two
-- fragments collapsed into a single row.
--
-- This records the fragment as sent, so a target with a fragment can be
-- answered precisely and each fragment keeps its own row. Rows received
-- before this is deployed have NULL and answer only fragment-less queries;
-- tools/recover-fragments fills them in from a pre-fold backup.
--
-- No index: a fragment filter always comes with a page_id, which the
-- existing page indexes narrow first.
--
-- Additive; the Ruby app never reads the column.

ALTER TABLE `links`
  ADD COLUMN `target_fragment` varchar(255) DEFAULT NULL,
  ALGORITHM=INPLACE, LOCK=NONE;
