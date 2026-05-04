<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Ephemeral SQLite + PHP built-in server (mirrors tests/conftest.py php_server).
 */
final class PhpBuiltInServer
{
    private Process $process;

    private function __construct(
        Process $process,
        public readonly string $baseUrl,
        public readonly string $dbPath,
        public readonly string $apiKey,
        public readonly string $adminUsername,
        public readonly string $adminPassword,
        private readonly string $tempDir,
    ) {
        $this->process = $process;
    }

    public static function start(array $envOverrides = []): self
    {
        $repoRoot = dirname(__DIR__, 3);
        $tmpDir = sys_get_temp_dir() . '/sanctum-php-int-' . uniqid('', true);
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Cannot create temp dir for PHP integration tests');
        }

        $dbPath = $tmpDir . '/tasks.db';
        $adminUser = $envOverrides['TASKS_BOOTSTRAP_ADMIN_USERNAME'] ?? 'admin';
        $adminPass = $envOverrides['TASKS_BOOTSTRAP_ADMIN_PASSWORD'] ?? 'AdminPass123!';
        $apiKey = $envOverrides['TASKS_BOOTSTRAP_API_KEY'] ?? str_repeat('a', 64);

        $env = array_merge($_ENV, [
            'TASKS_DB_PATH' => $dbPath,
            'TASKS_BOOTSTRAP_ADMIN_USERNAME' => $adminUser,
            'TASKS_BOOTSTRAP_ADMIN_PASSWORD' => $adminPass,
            'TASKS_BOOTSTRAP_API_KEY' => $apiKey,
            'TASKS_PASSWORD_COST' => '8',
            'TASKS_APP_DEBUG' => '1',
            'TASKS_SESSION_COOKIE_SECURE' => '0',
            'TASKS_API_RATE_LIMIT_REQUESTS' => '10000',
            'TASKS_LOGIN_LOCK_THRESHOLD' => '50',
        ], $envOverrides);

        $php = self::findPhpBinary();
        $port = self::freeTcpPort();
        $host = '127.0.0.1:' . $port;
        $docroot = $repoRoot . '/public';

        $process = new Process([$php, '-S', $host, '-t', $docroot], $repoRoot, $env);
        $process->start();

        $baseUrl = 'http://127.0.0.1:' . $port;
        $deadline = microtime(true) + 15;
        $last = null;
        while (microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                break;
            }
            try {
                // health.php returns 401 without a key; we only need the server to answer.
                $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
                $r = @file_get_contents($baseUrl . '/api/health.php', false, $ctx);
                if ($r !== false) {
                    return new self($process, $baseUrl, $dbPath, $apiKey, $adminUser, $adminPass, $tmpDir);
                }
            } catch (\Throwable $e) {
                $last = $e;
            }
            usleep(100000);
        }

        $process->stop(3);
        self::rmTree($tmpDir);
        throw new \RuntimeException('PHP built-in server failed to become ready: ' . ($last ? $last->getMessage() : 'process exited'));
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(5);
        }
        self::rmTree($this->tempDir);
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private static function findPhpBinary(): string
    {
        foreach (['php8.3', 'php8.2', 'php8.1', 'php'] as $bin) {
            $path = shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
            $path = $path !== null ? trim($path) : '';
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }
        if (is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }
        throw new \RuntimeException('php binary not found for integration tests');
    }

    private static function freeTcpPort(): int
    {
        $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($s === false) {
            return random_int(49152, 65535);
        }
        $name = stream_socket_get_name($s, false);
        fclose($s);
        if ($name !== false && str_contains($name, ':')) {
            return (int) substr($name, strrpos($name, ':') + 1);
        }

        return random_int(49152, 65535);
    }
}
