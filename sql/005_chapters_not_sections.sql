-- ============================================================
-- RARL — Consolidate "Section" terminology into "Chapter".
-- Removes the redundant continent-level "Asia Section" / "Asia Vice Chair"
-- rows (a country already has its own Chapter row) and relabels every
-- remaining chair_title from "Section Chair" to "Chapter Chair".
-- Safe to re-run: deletes match on exact name, updates match on exact title.
-- ============================================================

DELETE FROM `regional_sections` WHERE `name` IN ('Asia Section', 'Asia Vice Chair');

UPDATE `regional_sections` SET `name` = 'Africa Chapter' WHERE `name` = 'Africa Section';

UPDATE `regional_sections` SET `chair_title` = 'Chapter Chair' WHERE `chair_title` = 'Section Chair';

ALTER TABLE `regional_sections`
  MODIFY COLUMN `chair_title` VARCHAR(100) NOT NULL DEFAULT 'Chapter Chair';
