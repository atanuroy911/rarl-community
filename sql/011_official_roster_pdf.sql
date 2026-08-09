-- ============================================================
-- RARL — Point the public roster page at the official 2026 batch PDF
-- (member-list-20260801to20260884.pdf, codes #20260801-#20260884) and make
-- sure future auto-generated member codes (now #<year><counter> format,
-- see nextMemberCode() in functions.php) don't collide with that range.
-- Idempotent, safe to re-run.
-- ============================================================

INSERT INTO `settings` (`key`, `value`) VALUES
  ('custom_roster_pdf_path', 'member-list-20260801to20260884.pdf')
ON DUPLICATE KEY UPDATE `value` = 'member-list-20260801to20260884.pdf';

UPDATE `settings` SET `value` = '884'
WHERE `key` = 'id_card_counter' AND CAST(`value` AS UNSIGNED) < 884;
