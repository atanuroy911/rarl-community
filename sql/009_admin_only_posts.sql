-- ============================================================
-- RARL — Community posting paused for members: only admins can post to the
-- feed now (via admin/community.php), members can still comment and like.
-- Admin-authored posts need a `members` row to satisfy community_posts'
-- existing member_id FK/author-lookup pattern, so this seeds one dedicated
-- system account ("RARL Community Team") rather than reworking that join.
-- It can never log in (random unusable password) and is excluded from the
-- public directory/roster (directory_visible = 0).
-- Idempotent, safe to re-run.
-- ============================================================

INSERT INTO `members`
  (`uuid`, `type`, `email`, `password_hash`, `full_name`, `status`, `email_verified_at`,
   `unsubscribe_token`, `newsletter_opt_in`, `directory_visible`, `community_notify`)
SELECT
  UUID(), 'individual', 'community-team@rarl.internal', SHA2(UUID(), 256), 'RARL Community Team', 'active', NOW(),
  SHA2(CONCAT(UUID(), NOW()), 256), 0, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `members` WHERE `email` = 'community-team@rarl.internal');
