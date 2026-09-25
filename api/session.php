<?php
// Sessions live in MySQL so every App Service instance and worker shares them.
// File sessions under Azure's /home share can't be opened by PHP (the mounted
// files aren't owned by the PHP user), which silently broke every login.
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/db-connect.php';

    final class PandapesaDbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
    {
        private PDO $pdo;
        private int $lifetime;

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
            $this->lifetime = max(60, (int)ini_get('session.gc_maxlifetime'));
        }

        // Runs a query, creating the sessions table once if the migration
        // hasn't been applied yet.
        private function run(string $sql, array $params): ?PDOStatement
        {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                if ($e->getCode() === '42S02') {
                    try {
                        $this->createTable();
                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute($params);
                        return $stmt;
                    } catch (PDOException $retry) {
                        $e = $retry;
                    }
                }
                error_log('Session storage query failed: ' . $e->getMessage());
                return null;
            }
        }

        private function createTable(): void
        {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS php_sessions (
                    id VARCHAR(128) NOT NULL,
                    data MEDIUMBLOB NOT NULL,
                    expires_at INT UNSIGNED NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_php_sessions_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        public function open(string $path, string $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string|false
        {
            $stmt = $this->run('SELECT data FROM php_sessions WHERE id = ? AND expires_at > ?', [$id, time()]);
            if ($stmt === null) {
                return false;
            }
            $data = $stmt->fetchColumn();
            return $data === false ? '' : (string)$data;
        }

        public function write(string $id, string $data): bool
        {
            return $this->run(
                'INSERT INTO php_sessions (id, data, expires_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)',
                [$id, $data, time() + $this->lifetime]
            ) !== null;
        }

        public function destroy(string $id): bool
        {
            return $this->run('DELETE FROM php_sessions WHERE id = ?', [$id]) !== null;
        }

        public function gc(int $max_lifetime): int|false
        {
            $stmt = $this->run('DELETE FROM php_sessions WHERE expires_at < ?', [time()]);
            return $stmt === null ? false : $stmt->rowCount();
        }

        public function validateId(string $id): bool
        {
            $stmt = $this->run('SELECT 1 FROM php_sessions WHERE id = ? AND expires_at > ?', [$id, time()]);
            return $stmt !== null && $stmt->fetchColumn() !== false;
        }

        public function updateTimestamp(string $id, string $data): bool
        {
            return $this->run(
                'UPDATE php_sessions SET expires_at = ? WHERE id = ?',
                [time() + $this->lifetime, $id]
            ) !== null;
        }
    }

    session_set_save_handler(new PandapesaDbSessionHandler($pdo), true);
    // Some PHP images disable probabilistic GC; keep expired rows from piling up.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    // Keep the login cookie valid across the whole site and both custom-domain
    // hostnames. Honor Azure's forwarded HTTPS header behind its proxy.
    session_name('PANDAPESA_SESSID');
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    $cookieDomain = ($host === 'pandapesa.me' || substr($host, -strlen('.pandapesa.me')) === '.pandapesa.me')
        ? '.pandapesa.me'
        : '';
    $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
