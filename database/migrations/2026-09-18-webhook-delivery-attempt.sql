-- Web hook deliveries that fail are retried with backoff; each attempt is its
-- own row, numbered so the settings page can say "attempt 3". Additive: the
-- old app never reads this table.
ALTER TABLE webhook_deliveries ADD COLUMN attempt tinyint unsigned NOT NULL DEFAULT 1;
