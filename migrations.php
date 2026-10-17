<?php
/**
 * RARL — Database schema sync.
 *
 * One source of truth instead of a growing pile of migration files:
 *   database/schema.sql   CREATE TABLE for every table, exactly as it should be.
 *   database/seed.sql     starter data (insert-if-missing; never overwrites).
 *   database/changes.php  rare one-off data fixes, each run once and recorded.
 *
 * dbSync() compares schema.sql with the live database (information_schema) and
 * only ADDS what is missing: tables, columns (in position) and indexes. It also
 * widens a column when schema.sql allows more (new enum values, a longer
 * VARCHAR). It never drops or narrows anything, so it is always safe to run.
 * Works the same on MySQL 5.7/8 and MariaDB.
 *
 * Runs automatically after every deploy (deploy-extract.php), on the first-run
 * installer, and from Admin → Database.
 */

const DB_SCHEMA_FILE  = __DIR__ . '/database/schema.sql';
const DB_SEED_FILE    = __DIR__ . '/database/seed.sql';
const DB_CHANGES_FILE = __DIR__ . '/database/changes.php';

// ── SQL parsing helpers ────────────────────────────────────

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

/** Splits a comma list on top-level commas (outside quotes and brackets). */
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

/**
 * The desired schema: [table => ['create' => sql, 'columns' => [name => def], 'order' => [names],
 * 'indexes' => [name => def], 'primary' => def|null]].
 */
function schemaDesired(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $tables = [];
    foreach (sqlSplitStatements((string)@file_get_contents(DB_SCHEMA_FILE)) as $stmt) {
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*)\)([^)]*)$/is', $stmt, $m)) continue;
        $t = ['create' => $stmt, 'columns' => [], 'order' => [], 'indexes' => [], 'primary' => null];
        foreach (sqlSplitClauses($m[2]) as $line) {
            if (preg_match('/^`(\w+)`\s+(.+)$/s', $line, $c)) { $t['columns'][$c[1]] = $c[2]; $t['order'][] = $c[1]; }
            elseif (preg_match('/^PRIMARY\s+KEY/i', $line)) $t['primary'] = $line;
            elseif (preg_match('/^(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX)\s+`(\w+)`/i', $line, $k)) $t['indexes'][$k[1]] = $line;
        }
        $tables[$m[1]] = $t;
    }
    return $cache = $tables;
}

function schemaEnumValues(string $type): ?array {
    if (!preg_match('/^(enum|set)\s*\((.*)\)/is', trim($type), $m)) return null;
    return array_map(fn($v) => str_replace("''", "'", trim($v, "'")), sqlSplitClauses($m[2]));
}
function schemaCharLength(string $type): ?int {
    return preg_match('/^(?:var)?char\s*\((\d+)\)/i', trim($type), $m) ? (int)$m[1] : null;
}

// ── Planning ───────────────────────────────────────────────

/**
 * What dbSync() would do: a list of ['sql' => …, 'desc' => …, 'kind' => table|column|index|widen].
 */
function schemaPlan(): array {
    $pdo = db();
    $have = [];
    foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) $have[strtolower($t)] = $t;
    $cols = [];
    foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()") as $r) $cols[strtolower($r['TABLE_NAME'])][strtolower($r['COLUMN_NAME'])] = $r['COLUMN_TYPE'];
    $idx = [];
    foreach ($pdo->query("SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()") as $r) $idx[strtolower($r['TABLE_NAME'])][strtolower($r['INDEX_NAME'])] = true;

    $plan = [];
    foreach (schemaDesired() as $table => $t) {
        $lt = strtolower($table);
        if (!isset($have[$lt])) { $plan[] = ['kind' => 'table', 'desc' => "Create table {$table}", 'sql' => $t['create']]; continue; }
        $prev = null;
        foreach ($t['order'] as $col) {
            $def = $t['columns'][$col];
            $current = $cols[$lt][strtolower($col)] ?? null;
            if ($current === null) {
                // Keep generated values sane on existing rows: AUTO_INCREMENT columns can't be added this way.
                $plan[] = ['kind' => 'column', 'desc' => "Add column {$table}.{$col}",
                           'sql' => "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" . ($prev ? " AFTER `{$prev}`" : ' FIRST')];
            } else {
                $want = schemaEnumValues($def); $got = schemaEnumValues($current);
                $wantLen = schemaCharLength($def); $gotLen = schemaCharLength($current);
                if (($want !== null && $got !== null && array_diff($want, $got)) || ($wantLen && $gotLen && $wantLen > $gotLen)) {
                    $plan[] = ['kind' => 'widen', 'desc' => "Widen {$table}.{$col}", 'sql' => "ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` {$def}"];
                }
            }
            $prev = $col;
        }
        foreach ($t['indexes'] as $name => $def) {
            if (!isset($idx[$lt][strtolower($name)])) $plan[] = ['kind' => 'index', 'desc' => "Add index {$table}.{$name}", 'sql' => "ALTER TABLE `{$table}` ADD {$def}"];
        }
    }
    return $plan;
}

// ── One-off data changes ───────────────────────────────────

function dbSyncTableEnsure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `file`       VARCHAR(190) NOT NULL,
        `checksum`   CHAR(40)     NOT NULL,
        `applied_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `statements` INT(11)      NOT NULL DEFAULT 0,
        `ms`         INT(11)      NOT NULL DEFAULT 0,
        PRIMARY KEY (`file`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** [name => callable(PDO): void] from database/changes.php. */
function dbChanges(): array {
    static $c = null;
    return $c ??= (is_file(DB_CHANGES_FILE) ? (array)require DB_CHANGES_FILE : []);
}

function dbPendingChanges(): array {
    $pdo = db();
    dbSyncTableEnsure($pdo);
    $done = array_flip($pdo->query("SELECT file FROM schema_migrations WHERE file LIKE 'change:%'")->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter(array_keys(dbChanges()), fn($n) => !isset($done['change:' . $n])));
}

// ── Sync ───────────────────────────────────────────────────

/**
 * Brings the database in line with schema.sql, then seed.sql and pending changes.
 * Returns ['ok' => bool, 'applied' => [desc…], 'seeded' => int, 'ms' => int, 'error' => ?string, 'sql' => ?string].
 */
function dbSync(): array {
    $pdo = db();
    $t0 = microtime(true);
    $res = ['ok' => true, 'applied' => [], 'seeded' => 0, 'ms' => 0, 'error' => null, 'sql' => null];
    if (!is_file(DB_SCHEMA_FILE)) return ['ok' => false, 'error' => 'database/schema.sql is missing.'] + $res;
    if ((int)$pdo->query("SELECT GET_LOCK('rarl_db_sync', 15)")->fetchColumn() !== 1) {
        return ['ok' => false, 'error' => 'Another database update is running. Try again in a minute.'] + $res;
    }
    try {
        dbSyncTableEnsure($pdo);
        foreach (schemaPlan() as $step) {
            $res['sql'] = $step['sql'];
            $pdo->exec($step['sql']);
            $res['applied'][] = $step['desc'];
        }
        foreach (sqlSplitStatements((string)@file_get_contents(DB_SEED_FILE)) as $stmt) {
            $res['sql'] = $stmt;
            $res['seeded'] += $pdo->exec($stmt) > 0 ? 1 : 0;
        }
        foreach (dbPendingChanges() as $name) {
            $res['sql'] = 'change: ' . $name;
            (dbChanges()[$name])($pdo);
            $pdo->prepare("INSERT INTO schema_migrations (file, checksum) VALUES (?, '') ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP")->execute(['change:' . $name]);
            $res['applied'][] = 'Data change: ' . $name;
        }
        $res['sql'] = null;
        $res['ms'] = (int)round((microtime(true) - $t0) * 1000);
        $pdo->prepare("INSERT INTO schema_migrations (file, checksum, statements, ms) VALUES ('sync', ?, ?, ?)
                       ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), statements = VALUES(statements), ms = VALUES(ms), applied_at = CURRENT_TIMESTAMP")
            ->execute([sha1_file(DB_SCHEMA_FILE), count($res['applied']), $res['ms']]);
    } catch (Throwable $e) {
        $res['ok'] = false;
        $res['error'] = $e->getMessage();
        $res['sql'] = $res['sql'] ? mb_strimwidth(preg_replace('/\s+/', ' ', $res['sql']), 0, 300, '…') : null;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('rarl_db_sync')");
    }
    return $res;
}

/** Descriptions of what a sync would change (cached per request; empty = up to date). Used for admin badges. */
function pendingMigrations(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = array_column(schemaPlan(), 'desc');
        foreach (dbPendingChanges() as $n) $cache[] = 'Data change: ' . $n;
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/** When the database was last synced: ['at' => ?string, 'ms' => int]. */
function dbLastSync(): array {
    try {
        $r = db()->query("SELECT applied_at, ms FROM schema_migrations WHERE file = 'sync'")->fetch();
        return ['at' => $r['applied_at'] ?? null, 'ms' => (int)($r['ms'] ?? 0)];
    } catch (Throwable $e) {
        return ['at' => null, 'ms' => 0];
    }
}
