-- ============================================================
-- RARL — Indexes for the columns member and admin pages filter on.
-- Pages stay fast as the membership grows (status filters, a member's
-- certificates, upcoming events, the live-announcement feed).
-- Idempotent; the migration engine skips any index that already exists.
-- ============================================================

ALTER TABLE `members`
  ADD KEY IF NOT EXISTS `idx_status` (`status`),
  ADD KEY IF NOT EXISTS `idx_section` (`section_id`),
  ADD KEY IF NOT EXISTS `idx_created` (`created_at`);

ALTER TABLE `certificates`
  ADD KEY IF NOT EXISTS `idx_member` (`member_id`),
  ADD KEY IF NOT EXISTS `idx_recipient_email` (`recipient_email`),
  ADD KEY IF NOT EXISTS `idx_event` (`event_id`);

ALTER TABLE `events`
  ADD KEY IF NOT EXISTS `idx_active_date` (`is_active`, `event_date`);

ALTER TABLE `announcements`
  ADD KEY IF NOT EXISTS `idx_live` (`is_published`, `created_at`);

ALTER TABLE `resources`
  ADD KEY IF NOT EXISTS `idx_category` (`category_id`);

ALTER TABLE `community_posts`
  ADD KEY IF NOT EXISTS `idx_feed` (`is_hidden`, `created_at`);
