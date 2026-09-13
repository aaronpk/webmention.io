-- Listing and counting the mentions of one page read every one of its link
-- rows, which is slow for pages with many mentions: http://tantek.com/ has
-- 172,000, and its newest-20 list took 9.2s and its count by type 8.8s.
--
-- These indexes cover both queries, so they are answered from the index.
-- Additive only; no data changes. ALGORITHM=INPLACE, LOCK=NONE keeps the
-- table readable and writable while they build.

ALTER TABLE `links`
  ADD INDEX `page_verified_created` (`page_id`, `verified`, `deleted`, `created_at`),
  ADD INDEX `page_verified_type` (`page_id`, `verified`, `deleted`, `type`),
  ALGORITHM=INPLACE, LOCK=NONE;
