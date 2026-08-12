<?php
namespace app\bailing\upgrade;

/**
 * 文件型一次性 nonce。只保存哈希，不把 nonce 明文落盘。
 */
final class NonceStore
{
    private $directory;
    private $ttl;

    public function __construct($directory, $ttl = 600)
    {
        $this->directory = rtrim((string)$directory, DIRECTORY_SEPARATOR);
        $this->ttl = max(60, (int)$ttl);
    }

    public function issue($adminId, $scope)
    {
        $this->ensureDirectory();
        $nonce = bin2hex(random_bytes(24));
        $record = [
            'admin_id' => (int)$adminId,
            'scope' => self::normalizeScope($scope),
            'hash' => hash('sha256', $nonce),
            'expires_at' => time() + $this->ttl,
        ];
        $this->writeRecord($adminId, $scope, $record);
        return $nonce;
    }

    public function consume($adminId, $scope, $nonce)
    {
        $nonce = trim((string)$nonce);
        if ($nonce === '') {
            return false;
        }
        $this->ensureDirectory();
        $lock = fopen($this->directory . DIRECTORY_SEPARATOR . '.nonce.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            return false;
        }

        $file = $this->recordPath($adminId, $scope);
        $record = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_file($file)) {
            @unlink($file);
        }
        flock($lock, LOCK_UN);
        fclose($lock);

        if (!is_array($record)
            || (int)(isset($record['admin_id']) ? $record['admin_id'] : 0) !== (int)$adminId
            || (string)(isset($record['scope']) ? $record['scope'] : '') !== self::normalizeScope($scope)
            || (int)(isset($record['expires_at']) ? $record['expires_at'] : 0) < time()
            || empty($record['hash'])) {
            return false;
        }
        return hash_equals((string)$record['hash'], hash('sha256', $nonce));
    }

    private function writeRecord($adminId, $scope, array $record)
    {
        $file = $this->recordPath($adminId, $scope);
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, json_encode($record, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new UpgradeException('无法写入升级安全凭据');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new UpgradeException('无法保存升级安全凭据');
        }
    }

    private function recordPath($adminId, $scope)
    {
        return $this->directory . DIRECTORY_SEPARATOR . (int)$adminId . '-' . self::normalizeScope($scope) . '.json';
    }

    private static function normalizeScope($scope)
    {
        $scope = strtolower(trim((string)$scope));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $scope)) {
            throw new UpgradeException('无效的 nonce scope');
        }
        return $scope;
    }

    private function ensureDirectory()
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new UpgradeException('无法创建升级安全目录');
        }
        @chmod($this->directory, 0700);
    }
}
