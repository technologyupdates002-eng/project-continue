<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Produces and restores database dumps. Uses the native CLI clients
 * (mysqldump / mysql, or sqlite3) for reliability and streaming behaviour.
 *
 * Credentials are passed through a temporary --defaults-extra-file (0600)
 * so the database password never appears in the process argument list.
 */
class DatabaseBackupService
{
    public function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Dump the current database to a SQL file.
     *
     * @return array{path:string,bytes:int,checksum:string,tables:int,engine:string,version:string}
     */
    public function dumpToFile(string $targetSqlPath): array
    {
        $driver = $this->driver();

        if ($driver === 'mysql') {
            return $this->dumpMysql($targetSqlPath);
        }

        if ($driver === 'sqlite') {
            return $this->dumpSqlite($targetSqlPath);
        }

        throw new RuntimeException("Unsupported database driver for backup: {$driver}");
    }

    /**
     * Restore a database from a SQL dump file.
     */
    public function restoreFromFile(string $sqlPath): void
    {
        if (! is_readable($sqlPath)) {
            throw new RuntimeException("Database dump is not readable: {$sqlPath}");
        }

        $driver = $this->driver();

        if ($driver === 'mysql') {
            $this->restoreMysql($sqlPath);

            return;
        }

        if ($driver === 'sqlite') {
            $this->restoreSqlite($sqlPath);

            return;
        }

        throw new RuntimeException("Unsupported database driver for restore: {$driver}");
    }

    /**
     * Light structural validation of a SQL dump file.
     *
     * @return array{valid:bool,reason:?string,statements:int}
     */
    public function verifyDump(string $sqlPath): array
    {
        if (! is_file($sqlPath) || ! is_readable($sqlPath)) {
            return ['valid' => false, 'reason' => 'Dump file missing or unreadable.', 'statements' => 0];
        }

        $size = filesize($sqlPath);
        if ($size < 64) {
            return ['valid' => false, 'reason' => 'Dump file suspiciously small.', 'statements' => 0];
        }

        $head = file_get_contents($sqlPath, false, null, 0, 4096);
        if (stripos($head, 'INSERT INTO') === false && stripos($head, 'CREATE TABLE') === false) {
            return ['valid' => false, 'reason' => 'Dump does not look like a SQL database export.', 'statements' => 0];
        }

        $statements = substr_count($head, ';') + substr_count(file_get_contents($sqlPath), ";\n");

        return ['valid' => true, 'reason' => null, 'statements' => max($statements, 1)];
    }

    protected function dumpMysql(string $targetSqlPath): array
    {
        $cfg = DB::connection()->getConfig(null);
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 3306;
        $database = $cfg['database'];
        $username = $cfg['username'];
        $password = $cfg['password'] ?? '';
        $unixSocket = $cfg['unix_socket'] ?? null;

        $dumpBin = config('backup.database.dump_binary') ?: $this->which('mysqldump');
        if (! $dumpBin) {
            throw new RuntimeException('mysqldump binary not found. Set BACKUP_MYSQLDUMP_PATH.');
        }

        $defaultsFile = $this->writeDefaultsFile($host, $port, $username, $password, $unixSocket);

        $ssl = config('backup.database.ssl') ? [] : ['--ssl=0'];

        $cmd = [
            $dumpBin,
            "--defaults-extra-file={$defaultsFile}",
            '--single-transaction',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--hex-blob',
        ];

        // Exclude operational backup-subsystem tables so their records survive a restore.
        foreach (config('backup.database.ignore_tables', []) as $table) {
            $cmd[] = '--ignore-table='.$database.'.'.$table;
        }

        $cmd = array_merge($cmd, $ssl, [$database]);

        $exitCode = $this->run($cmd, $targetSqlPath, $stderr);

        @unlink($defaultsFile);

        if ($exitCode !== 0) {
            throw new RuntimeException("mysqldump failed (exit {$exitCode}): ".trim($stderr));
        }

        return $this->summariseDump($targetSqlPath, 'mysql');
    }

    protected function restoreMysql(string $sqlPath): void
    {
        $cfg = DB::connection()->getConfig(null);
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 3306;
        $database = $cfg['database'];
        $username = $cfg['username'];
        $password = $cfg['password'] ?? '';
        $unixSocket = $cfg['unix_socket'] ?? null;

        $mysqlBin = config('backup.database.restore_binary') ?: $this->which('mysql');
        if (! $mysqlBin) {
            throw new RuntimeException('mysql binary not found. Set BACKUP_MYSQL_PATH.');
        }

        $defaultsFile = $this->writeDefaultsFile($host, $port, $username, $password, $unixSocket);

        // Build a single SQL stream: disable FK/unique checks, inline the dump,
        // then re-enable. We pipe this through stdin rather than using SOURCE,
        // which avoids secure_file_priv / client path limitations.
        $tmpDir = storage_path('app/backups/_restore_'.uniqid('', true));
        mkdir($tmpDir, 0750, true);
        $combined = $tmpDir.'/restore.sql';

        $out = fopen($combined, 'w');
        fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
        $in = fopen($sqlPath, 'r');
        if ($in) {
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fwrite($out, "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        fclose($out);

        $ssl = config('backup.database.ssl') ? [] : ['--ssl=0'];

        $cmd = array_merge([
            $mysqlBin,
            "--defaults-extra-file={$defaultsFile}",
            '--default-character-set=utf8mb4',
            '--force',
        ], $ssl, [$database]);

        $log = $tmpDir.'/out.log';
        $exitCode = $this->run($cmd, $log, $stderr, $combined);

        @unlink($defaultsFile);
        $this->rmdir($tmpDir);

        if ($exitCode !== 0) {
            throw new RuntimeException("mysql restore failed (exit {$exitCode}): ".trim($stderr));
        }
    }

    protected function dumpSqlite(string $targetSqlPath): array
    {
        $db = DB::connection()->getConfig('database');
        $bin = $this->which('sqlite3');
        if (! $bin) {
            throw new RuntimeException('sqlite3 binary not found.');
        }

        $exitCode = $this->run([$bin, $db, '.dump'], $targetSqlPath, $stderr);
        if ($exitCode !== 0) {
            throw new RuntimeException("sqlite3 dump failed: ".trim($stderr));
        }

        return $this->summariseDump($targetSqlPath, 'sqlite');
    }

    protected function restoreSqlite(string $sqlPath): void
    {
        $db = DB::connection()->getConfig('database');
        $bin = $this->which('sqlite3');
        if (! $bin) {
            throw new RuntimeException('sqlite3 binary not found.');
        }

        // For SQLite we rebuild the file from the dump.
        @unlink($db);
        $exitCode = $this->run([$bin, $db], $sqlPath, $stderr);
        if ($exitCode !== 0) {
            throw new RuntimeException("sqlite3 restore failed: ".trim($stderr));
        }
    }

    protected function summariseDump(string $sqlPath, string $engine): array
    {
        $bytes = filesize($sqlPath);
        $checksum = hash_file('sha256', $sqlPath);
        $version = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        $tables = count(DB::select('SHOW TABLES'));

        return [
            'path' => $sqlPath,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'tables' => $tables,
            'engine' => $engine,
            'version' => $version,
        ];
    }

    protected function writeDefaultsFile(string $host, string|int $port, string $username, string $password, ?string $unixSocket = null): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $file = tempnam($dir, 'mycnf_');
        if ($file === false) {
            throw new RuntimeException('Unable to create temporary MySQL defaults file.');
        }

        $contents = "[client]\n";
        if ($unixSocket) {
            $contents .= "socket={$unixSocket}\n";
        } else {
            $contents .= "host={$host}\nport={$port}\n";
        }
        $contents .= "user={$username}\npassword={$password}\n";

        file_put_contents($file, $contents);
        chmod($file, 0600);

        return $file;
    }

    protected function which(string $binary): ?string
    {
        $path = trim((string) shell_exec("command -v ".escapeshellarg($binary)." 2>/dev/null"));

        return $path !== '' ? $path : null;
    }

    protected function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Run a command. stdout is written to $stdoutFile; if $stdinFile is set it
     * is fed to the process as stdin (used for SQL restore scripts).
     */
    protected function run(array $command, string $stdoutFile, ?string &$stderr, ?string $stdinFile = null): int
    {
        $spec = [
            0 => $stdinFile ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), $spec, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start database process.');
        }

        // Close stdin immediately when not feeding a file.
        if (! $stdinFile && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
