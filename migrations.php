<?php
/**
 * RARL — Database migration engine.
 *
 * - Tracks what has been applied in `schema_migrations` (file + checksum), so
 *   only new or edited files run; every file is still idempotent, so a
 *   re-run is always safe.
 * - Portable across MariaDB and MySQL 5.7/8: MySQL has no
 *   `ADD COLUMN/KEY IF NOT EXISTS`, so those clauses are checked against
 *   information_schema and run one by one without the IF NOT EXISTS.
 * - "Already exists" errors (duplicate column/key/table) are treated as done.
 * - A named lock stops two runs (e.g. a deploy and an admin click) overlapping.
 *
 * Applied automatically by deploy-extract.php after every deploy; admins can
 * also apply from Admin → Database (admin/migrate.php).
 */

const RARL_MIGRATIONS = [
    'schema.sql'                            => 'Base schema (members, certificates, settings, …)',
    '002_v2_features.sql'                   => 'Plans, OTP, community, ID cards, chapters',
    '003_community_richtext.sql'            => 'Rich-text team posts with a photo',
    '004_events_premium_directory.sql'      => 'Event RSVPs, members-only learning, roster visibility',
    '005_chapters_not_sections.sql'         => 'Rename "Section" to "Chapter"',
    '006_multi_email_accounts.sql'          => 'Several emails per account, temporary passwords, chairs',
    '007_certificate_templates.sql'         => 'Uploadable ID card and certificate templates',
    '008_membership_certs_and_chapters.sql' => 'Membership certificates, chapter announcements',
    '009_admin_only_posts.sql'              => 'The "RARL Team" account team posts are published as',
    '010_cv_url.sql'                        => 'External CV / résumé link',
    '012_email_queue.sql'                   => 'Background email queue for bulk sends',
    '013_announcement_controls.sql'         => 'Announcement button link and expiry date',
    '014_performance_indexes.sql'           => 'Indexes for faster member, event and feed pages',
];

// MySQL/MariaDB error codes that mean "this change is already in place".
const RARL_MIGRATION_ALREADY_DONE = [1050, 1060, 1061, 1091, 1826];

function migrationsTableEnsure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `file`       VARCHAR(190) NOT NULL,
        `checksum`   CHAR(40)     NOT NULL,
        `applied_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `statements` INT(11)      NOT NULL DEFAULT 0,
        `ms`         INT(11)      NOT NULL DEFAULT 0,
        PRIMARY KEY (`file`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Every known migration with its state: applied, pending (never run) or changed (file edited since). */
function migrationStatus(): array {
    $pdo = db();
    migrationsTableEnsure($pdo);
    $applied = [];
    foreach ($pdo->query('SELECT * FROM schema_migrations') as $r) $applied[$r['file']] = $r;
    $out = [];
    foreach (RARL_MIGRATIONS as $file => $desc) {
        $path = __DIR__ . '/sql/' . $file;
        $sum  = is_file($path) ? sha1_file($path) : '';
        $row  = $applied[$file] ?? null;
        $state = !$row ? 'pending' : ($row['checksum'] !== $sum ? 'changed' : 'applied');
        $out[$file] = ['file' => $file, 'desc' => $desc, 'state' => $state, 'missing' => $sum === '',
                       'applied_at' => $row['applied_at'] ?? null, 'statements' => (int)($row['statements'] ?? 0), 'ms' => (int)($row['ms'] ?? 0)];
    }
    return $out;
}

function pendingMigrations(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        return $cache = array_keys(array_filter(migrationStatus(), fn($m) => $m['state'] !== 'applied' && !$m['missing']));
    } catch (Throwable $e) {
        return [];
    }
}

/** Splits SQL on `;` outside quotes and comments. */
function sqlSplitStatements(string $sql): array {
    $out = []; $buf = ''; $q = null; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q === null) {
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') { $j = strpos($sql, "\n", $i); $i = $j === false ? $n : $j; $buf .= "\n"; continue; }
            if ($c === '#') { $j = strpos($sql, "\n", $i); $i = $j === false ? $n : $j; $buf .= "\n"; continue; }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') { $j = strpos($sql, '*/', $i + 2); $i = $j === false ? $n : $j + 1; continue; }
            if ($c === "'" || $c === '"' || $c === '`') $q = $c;
            if ($c === ';') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
        } elseif ($c === '\\' && $q !== '`') { $buf .= $c . ($sql[++$i] ?? ''); continue; }
        elseif ($c === $q) { if (($sql[$i + 1] ?? '') === $q) { $buf .= $c . $c; $i++; continue; } $q = null; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

/** Splits an ALTER TABLE's clause list on top-level commas. */
function sqlSplitClauses(string $s): array {
    $out = []; $buf = ''; $depth = 0; $q = null;
    foreach (str_split($s) as $c) {
        if ($q) { if ($c === $q) $q = null; }
        elseif ($c === "'" || $c === '"' || $c === '`') $q = $c;
        elseif ($c === '(') $depth++;
        elseif ($c === ')') $depth--;
        elseif ($c === ',' && $depth === 0) { $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

function migrationColumnExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]); return (bool)$st->fetchColumn();
}
function migrationIndexExists(PDO $pdo, string $table, string $key): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$table, $key]); return (bool)$st->fetchColumn();
}

/** Runs one statement portably; returns how many statements actually executed. */
function migrationExec(PDO $pdo, string $stmt): int {
    if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $stmt, $m) && stripos($stmt, 'IF NOT EXISTS') !== false) {
        $table = $m[1]; $ran = 0;
        foreach (sqlSplitClauses($m[2]) as $clause) {
            if (preg_match('/^ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $clause, $c)) {
                if (migrationColumnExists($pdo, $table, $c[1])) continue;
            } elseif (preg_match('/^ADD\s+((?:UNIQUE\s+|FULLTEXT\s+)?(?:KEY|INDEX))\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $clause, $c)) {
                if (migrationIndexExists($pdo, $table, $c[2])) continue;
            }
            $ran += migrationTry($pdo, "ALTER TABLE `$table` " . preg_replace('/\s+IF\s+NOT\s+EXISTS/i', '', $clause, 1));
        }
        return $ran;
    }
    return migrationTry($pdo, $stmt);
}
function migrationTry(PDO $pdo, string $sql): int {
    try { $pdo->exec($sql); return 1; }
    catch (PDOException $e) {
        if (in_array((int)($e->errorInfo[1] ?? 0), RARL_MIGRATION_ALREADY_DONE, true)) return 0;
        throw $e;
    }
}

/** Applies one migration file and records it. Returns ['ok', 'statements', 'ms', 'error'?]. */
function applyMigration(string $file): array {
    $pdo = db();
    migrationsTableEnsure($pdo);
    $path = __DIR__ . '/sql/' . basename($file);
    if (!is_file($path)) return ['ok' => false, 'statements' => 0, 'ms' => 0, 'error' => 'File not found: sql/' . $file];
    $t = microtime(true); $ran = 0; $stmts = sqlSplitStatements(file_get_contents($path));
    foreach ($stmts as $i => $stmt) {
        try { $ran += migrationExec($pdo, $stmt); }
        catch (PDOException $e) {
            return ['ok' => false, 'statements' => $ran, 'ms' => 0,
                    'error' => 'Statement ' . ($i + 1) . ' of ' . count($stmts) . ': ' . $e->getMessage(),
                    'sql' => mb_strimwidth(preg_replace('/\s+/', ' ', $stmt), 0, 240, '…')];
        }
    }
    $ms = (int)round((microtime(true) - $t) * 1000);
    $pdo->prepare('INSERT INTO schema_migrations (file, checksum, statements, ms) VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), statements = VALUES(statements), ms = VALUES(ms), applied_at = CURRENT_TIMESTAMP')
        ->execute([$file, sha1_file($path), $ran, $ms]);
    return ['ok' => true, 'statements' => $ran, 'ms' => $ms];
}

/** Applies $files (default: everything pending) in order, stopping at the first failure. */
function applyMigrations(?array $files = null): array {
    $pdo = db();
    $locked = (int)$pdo->query("SELECT GET_LOCK('rarl_migrate', 15)")->fetchColumn() === 1;
    if (!$locked) return ['_lock' => ['ok' => false, 'statements' => 0, 'ms' => 0, 'error' => 'Another migration run is in progress. Try again in a minute.']];
    try {
        $results = [];
        foreach ($files ?? pendingMigrations() as $file) {
            $results[$file] = applyMigration($file);
            if (!$results[$file]['ok']) break;
        }
        return $results;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('rarl_migrate')");
    }
}

/** Re-applies every migration (fresh install, or "repair" from the admin page). */
function runAllMigrations(): array {
    return applyMigrations(array_keys(RARL_MIGRATIONS));
}
