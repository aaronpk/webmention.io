-- Whether a site has proved it belongs to its account.
--
-- Sites added since the rewrite must advertise the account's webmention
-- endpoint before they are accepted. Sites from before then were never
-- checked; 376 domains are held by more than one account. These columns
-- record the outcome of checking each one (tools/verify-sites, and the
-- "Check now" button on the Sites page). While a site is unverified it
-- still receives its own mentions, but if another account holds a verified
-- site for the same domain, the unverified site's mentions are left out of
-- public target queries.
--
-- Additive, nullable; the Ruby app never reads them.

ALTER TABLE `sites`
  ADD COLUMN `verified_at` datetime DEFAULT NULL,
  ADD COLUMN `verification_checked_at` datetime DEFAULT NULL,
  ADD COLUMN `verification_error` varchar(255) DEFAULT NULL,
  ALGORITHM=INPLACE, LOCK=NONE;

-- The sign-in domain was proved by IndieAuth when the account was created.
UPDATE `sites` s JOIN `accounts` a ON a.id = s.account_id
  SET s.verified_at = COALESCE(s.created_at, NOW())
  WHERE s.domain = a.domain OR s.domain = a.username;
