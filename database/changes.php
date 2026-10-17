<?php
/**
 * RARL — one-off data changes.
 *
 * Schema changes do NOT go here: edit database/schema.sql instead.
 * Use this only for a data fix that must run exactly once on every install,
 * e.g. renaming a value or moving data into a new column. Each entry runs
 * once (recorded in schema_migrations as "change:<name>") and must be safe
 * if the data it expects is not there.
 *
 * Keep names dated and descriptive, and never rename or remove an entry that
 * has been deployed.
 *
 * Example:
 *   '2026-11-01 fill new members.country_code' => function (PDO $pdo): void {
 *       $pdo->exec("UPDATE members SET country_code = 'GB' WHERE country = 'United Kingdom' AND country_code IS NULL");
 *   },
 */
return [
];
