-- ============================================================
-- RARL — starter data. Runs on every sync; every statement only
-- inserts rows that are missing and never changes existing values,
-- so admin edits are always kept.
-- ============================================================

-- from schema.sql
INSERT INTO `settings` (`key`, `value`) VALUES
('community_guidelines','Welcome to the Robotics & Automation Research Lab (RARL). Please be respectful and collaborative.'),
('site_name',           'Robotics & Automation Research Lab (RARL)'),
('site_tagline',        'Robotics & Automation Research Lab'),
('site_url',            'https://community.rarl-lab.com/membership'),
('require_approval',    '1'),
('cert_id_prefix',      'RARL'),
('cert_id_counter',     '0')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- from schema.sql
INSERT INTO `partnership_tiers` (`category`, `name`, `description`, `fee_amount`, `fee_currency`, `fee_period`, `is_addon`, `display_order`) VALUES
('membership',  'Membership',                 'Universities and research institutions, private or public, that support our association, train students, and conduct international research.', 5000.00, 'EUR', 'year', 0, 1),
('partnership', 'Private Partnership',        'Companies in the wine, robotics, or automation industry that support our association.', 5000.00, 'EUR', 'year', 0, 2),
('partnership', 'Institutional Partnership',  'Any institution, or association, research or interprofessional institute that supports our association.', 5000.00, 'EUR', 'year', 0, 3),
('partnership', 'High Visibility',            'Contribute to production fees for RARL publications and gain additional visibility across our channels.', 10000.00, 'EUR', 'year', 1, 4)
ON DUPLICATE KEY UPDATE `name` = `name`;

-- from schema.sql
INSERT INTO `settings` (`key`, `value`) VALUES
('partner_page_intro',      'RARL welcomes members and partners who support open, collaborative robotics and automation research.'),
('partner_benefits_member', 'Right to vote at the General Assembly during annual meetings\nInput on RARL strategy and media development\nPromotion of open access dissemination'),
('partner_benefits_partner','Involvement with the RARL Technical Reviews editorial board\nNetworking opportunities with researchers\nParticipation in the yearly RARL Science meeting'),
('partner_ethics_note',     'All private partners of RARL are asked to sign a charter of ethics.'),
('partner_contact_name',    'RARL Partnerships Team'),
('partner_contact_email',   'info@rarl-lab.com')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- from schema.sql
INSERT INTO `resource_categories` (`name`, `slug`, `icon_emoji`, `description`, `display_order`) VALUES
('Scientific Writing',  'scientific-writing',  '✍️', 'Learn how to write impactful research papers and academic documents.', 1),
('Research Methodology','research-methodology','🔬', 'Fundamentals of designing and conducting research.', 2),
('Machine Learning',    'machine-learning',    '🤖', 'Tutorials and courses on ML algorithms and applications.', 3),
('Artificial Intelligence','artificial-intelligence','🧠','Foundations and advanced topics in AI.', 4),
('Programming',         'programming',         '💻', 'Python, MATLAB, and other tools for researchers.', 5),
('Statistics',          'statistics',          '📊', 'Statistical methods for research analysis.', 6),
('Publishing & Journals','publishing',         '📄', 'How to navigate academic publishing and peer review.', 7),
('LaTeX',               'latex',               '📝', 'Document preparation for academic writing.', 8)
ON DUPLICATE KEY UPDATE `slug` = `slug`;

-- from 002_v2_features.sql
INSERT INTO `membership_plans` (`slug`,`name`,`tagline`,`description`,`audience`,`regular_price`,`display_order`) VALUES
('free',         'Free',         'Open to every researcher',      'Full community access at no cost — individuals and labs alike.', 'both',       NULL,    1),
('student',      'Student',      'For students and trainees',     'For PhD/MSc/BSc students and early-career researchers.',          'individual', 0.00,    2),
('organization', 'Organization', 'For labs & institutions',       'For research labs and institutions joining as an organization.',  'lab',        2500.00, 3)
ON DUPLICATE KEY UPDATE `name` = `name`;

-- from 002_v2_features.sql
INSERT INTO `settings` (`key`,`value`) VALUES
('home_hero_badge',        'Now Open for Membership'),
('home_hero_title',        'Join the Global RARL Research Community'),
('home_hero_subtitle',     'Connect with researchers worldwide. Access curated learning resources, receive newsletters, attend events, and earn verifiable digital certificates for your contributions.'),
('community_feed_intro',   'Share updates, ask questions, and connect with fellow RARL researchers.'),
('stat_members_override',  ''),
('stat_labs_override',     ''),
('stat_certs_override',    ''),
('stat_countries',         '5'),
('popup_enabled',          '0'),
('popup_image_path',       ''),
('popup_link_url',         ''),
('popup_alt_text',         ''),
('idcard_signer1_name',    'Ata Jahangir Moshayedi — RARL President'),
('idcard_signer2_name',    'S. Ali Eftekhari — Asia Section Chair'),
('id_card_counter',        '0')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- from 006_multi_email_accounts.sql
INSERT INTO `member_emails` (`member_id`, `email`, `is_primary`, `verified_at`)
SELECT `id`, `email`, 1, IFNULL(`email_verified_at`, `created_at`) FROM `members`
ON DUPLICATE KEY UPDATE `member_id` = `member_id`;

-- from 009_admin_only_posts.sql
INSERT INTO `members`
  (`uuid`, `type`, `email`, `password_hash`, `full_name`, `status`, `email_verified_at`,
   `unsubscribe_token`, `newsletter_opt_in`, `directory_visible`, `community_notify`)
SELECT
  UUID(), 'individual', 'community-team@rarl.internal', SHA2(UUID(), 256), 'Robotics & Automation Research Lab (RARL) Team', 'active', NOW(),
  SHA2(CONCAT(UUID(), NOW()), 256), 0, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `members` WHERE `email` = 'community-team@rarl.internal');

-- Membership standing (activity rules). Off until an admin turns it on.
INSERT INTO `settings` (`key`, `value`) VALUES
  ('standing_enabled', '0'),
  ('standing_window_days', '180'),
  ('standing_target_points', '3'),
  ('standing_pts_event', '3'),
  ('standing_pts_share', '1'),
  ('standing_pts_paper', '5'),
  ('standing_pts_login', '1'),
  ('standing_grace_days', '14'),
  ('standing_new_member_days', '90')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- from 016_membership_cert_settings.sql
INSERT INTO `settings` (`key`, `value`) VALUES
  ('membership_cert_auto', '0'),
  ('membership_cert_autoregen', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;

