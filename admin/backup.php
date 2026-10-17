<?php
/**
 * RARL Admin — Backup.
 * Self-contained: downloads the whole database as a gzipped .sql file
 * (restore with phpMyAdmin → Import, or `mysql db < file.sql`) and the
 * uploaded files (photos, certificates, designs, CVs…) as a .zip.
 * Works on its own, without the schema/migration code.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

const BACKUP_ROWS_PER_INSERT = 200;

/** Writes a full SQL dump of the current database to $path (gzip). Returns [tables, rows]. */
function backupDatabase(PDO $pdo, string $path): array {
    $gz = gzopen($path, 'wb6');
    if (!$gz) throw new RuntimeException('Could not create a temporary file for the backup.');
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $server = $pdo->query('SELECT VERSION()')->fetchColumn();
    gzwrite($gz, "-- RARL Community database backup\n-- Database: {$dbName}\n-- Server: {$server}\n-- Created: " . date('c') . "\n"
        . "-- Restore: phpMyAdmin → Import this file (gzip is fine), or: gunzip -c file.sql.gz | mysql dbname\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");
    $pdo->exec("SET time_zone = '+00:00'"); // timestamps are written exactly as stored

    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $totalRows = 0;
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "-- ----------------------------------------\n-- Table `{$t}`\n-- ----------------------------------------\n"
            . "DROP TABLE IF EXISTS `{$t}`;\n{$create};\n\n");
        // Unbuffered, so big tables never have to fit in memory.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $pdo->query("SELECT * FROM `{$t}`");
        $batch = []; $cols = null;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols ??= '`' . implode('`, `', array_keys($row)) . '`';
            $batch[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
            if (count($batch) >= BACKUP_ROWS_PER_INSERT) {
                gzwrite($gz, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $totalRows += count($batch); $batch = [];
            }
        }
        $st->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        if ($batch) { gzwrite($gz, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n"); $totalRows += count($batch); }
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
    gzclose($gz);
    return [count($tables), $totalRows];
}

/** Zips the uploads folder to $path. Returns the number of files. */
function backupUploads(string $path): int {
    if (!class_exists('ZipArchive')) throw new RuntimeException('The server is missing PHP\'s Zip extension.');
    $root = realpath(UPLOADS_PATH);
    if (!$root) throw new RuntimeException('The uploads folder was not found.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the zip file.');
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $rel = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $zip->addFile($file->getPathname(), $rel);
        $zip->setCompressionName($rel, preg_match('/\.(jpe?g|png|webp|gif|pdf|zip|gz)$/i', $rel) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
        $n++;
    }
    if (!$n) $zip->addFromString('uploads/README.txt', "No uploaded files yet.\n");
    $zip->close();
    return $n;
}

function backupSend(string $file, string $name, string $type): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminCsrfOk()) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $what = $_POST['what'] ?? '';
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(false);
        session_write_close();
        $stamp = date('Y-m-d_His');
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string)parse_url(SITE_URL, PHP_URL_HOST)) ?: 'rarl';
        $tmp = tempnam(sys_get_temp_dir(), 'rbk');
        try {
            if ($what === 'database') {
                [$tables, $rows] = backupDatabase(db(), $tmp);
                try { settingSet('last_backup_db', date('c') . " ({$tables} tables, {$rows} rows)"); } catch (Throwable $e) {}
                backupSend($tmp, "{$host}-database-{$stamp}.sql.gz", 'application/gzip');
                @unlink($tmp); exit;
            }
            if ($what === 'files') {
                $n = backupUploads($tmp);
                try { settingSet('last_backup_files', date('c') . " ({$n} files)"); } catch (Throwable $e) {}
                backupSend($tmp, "{$host}-uploads-{$stamp}.zip", 'application/zip');
                @unlink($tmp); exit;
            }
            $error = 'Choose what to download.';
        } catch (Throwable $e) {
            error_log('Backup failed: ' . $e->getMessage());
            $error = 'The backup could not be created: ' . $e->getMessage();
        }
        @unlink($tmp);
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
}

$pdo = db();
$dbKb = (int)$pdo->query("SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
$tableCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
$uploadsBytes = 0; $uploadsFiles = 0;
if ($root = realpath(UPLOADS_PATH)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) { $uploadsBytes += $f->getSize(); $uploadsFiles++; }
    }
}
$lastDb = setting('last_backup_db', '');
$lastFiles = setting('last_backup_files', '');

adminWrap(function () use ($error, $dbKb, $tableCount, $uploadsBytes, $uploadsFiles, $lastDb, $lastFiles) {
    $size = fn($kb) => $kb > 1024 ? number_format($kb / 1024, 1) . ' MB' : max(1, $kb) . ' KB';
    $when = function (string $s) {
        if ($s === '') return 'Never from this page';
        [$iso] = explode(' ', $s, 2);
        return date('d M Y, H:i', strtotime($iso)) . ' ' . substr($s, strlen($iso));
    };
?>
<div class="rarl-page-head">
  <div>
    <h1>Backup</h1>
    <p>Download a full copy of the site's data. Keep it somewhere safe, such as your computer or cloud drive, especially before big changes.</p>
  </div>
</div>

<?php if ($error): ?><div class="rarl-card p-4 mb-5 border-red-200 bg-red-50 text-sm text-red-700"><i class="fa-solid fa-triangle-exclamation"></i> <?= ex($error) ?></div><?php endif; ?>

<div class="grid md:grid-cols-2 gap-5">
  <section class="rarl-card p-5 flex flex-col">
    <h2 class="font-semibold text-gray-900 flex items-center gap-2"><i class="fa-solid fa-database text-rarl-red"></i> Database</h2>
    <p class="text-sm text-gray-600 mt-1">Members, events, certificates, settings and everything else: <?= $tableCount ?> tables, about <?= $size($dbKb) ?>.</p>
    <p class="text-xs text-gray-500 mt-2">Last download: <?= ex($when($lastDb)) ?></p>
    <form method="POST" class="mt-auto pt-4" data-no-loading>
      <?= acsrfField() ?><input type="hidden" name="what" value="database">
      <button class="rarl-btn rarl-btn-primary w-full justify-center"><i class="fa-solid fa-download"></i> Download database (.sql.gz)</button>
    </form>
  </section>

  <section class="rarl-card p-5 flex flex-col">
    <h2 class="font-semibold text-gray-900 flex items-center gap-2"><i class="fa-solid fa-folder-open text-rarl-red"></i> Uploaded files</h2>
    <p class="text-sm text-gray-600 mt-1">Photos, certificates, ID cards, designs and CVs: <?= number_format($uploadsFiles) ?> files, about <?= $size((int)round($uploadsBytes / 1024)) ?>.</p>
    <p class="text-xs text-gray-500 mt-2">Last download: <?= ex($when($lastFiles)) ?></p>
    <form method="POST" class="mt-auto pt-4" data-no-loading>
      <?= acsrfField() ?><input type="hidden" name="what" value="files">
      <button class="rarl-btn w-full justify-center"><i class="fa-solid fa-file-zipper"></i> Download files (.zip)</button>
    </form>
  </section>
</div>

<section class="rarl-card p-5 mt-5 text-sm text-gray-700">
  <h2 class="font-semibold text-gray-900 mb-2">How to restore</h2>
  <ol class="list-decimal pl-5 space-y-1.5">
    <li><b>Database:</b> in cPanel open <b>phpMyAdmin</b>, select the site's database, choose <b>Import</b> and pick the <code>.sql.gz</code> file. It replaces every table with the copy in the backup.</li>
    <li><b>Files:</b> in cPanel <b>File Manager</b>, upload the <code>.zip</code> to the site folder and <b>Extract</b> it. It recreates the <code>uploads</code> folder.</li>
    <li>Restore both from the same day so certificates and photos match the database.</li>
  </ol>
  <p class="text-xs text-gray-500 mt-3">Backups contain personal data (names, emails, password hashes). Store them privately and delete old copies you no longer need.</p>
</section>
<?php }, 'backup', 'Backup');
