<?php
/**
 * CI: builds the database from database/schema.sql on an empty server, checks a
 * second sync changes nothing, and adds a test member for the smoke test.
 * Usage: php tests/ci-db.php   (DB_* environment variables point at an empty database)
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../functions.php';

$fail = function (string $msg) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };

$r = dbSync();
if (!$r['ok']) $fail("first sync: {$r['error']}\n  at: {$r['sql']}");
echo "First sync: " . count($r['applied']) . " changes, {$r['seeded']} seed rows, {$r['ms']} ms\n";

$tables = count(schemaDesired());
$have = (int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'schema_migrations'")->fetchColumn();
if ($have !== $tables) $fail("expected $tables tables, found $have");

$r2 = dbSync();
if (!$r2['ok']) $fail("second sync: {$r2['error']}");
if ($r2['applied']) $fail("second sync was not a no-op: " . implode('; ', $r2['applied']));
if (pendingMigrations()) $fail('pending after sync: ' . implode('; ', pendingMigrations()));
echo "Second sync: no changes (idempotent)\n";

// A member to sign in with during the smoke test.
db()->prepare("INSERT INTO members (uuid, type, email, password_hash, full_name, status, email_verified_at, unsubscribe_token, member_code)
               VALUES (UUID(), 'individual', 'ci@example.com', ?, 'Ci Tester', 'active', NOW(), SHA2(UUID(), 256), 'RARL-CI-0001')")
    ->execute([password_hash('ci-password', PASSWORD_DEFAULT)]);
echo "Test member created\n";
