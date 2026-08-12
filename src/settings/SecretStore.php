<?php

namespace app\bailing\settings;

/**
 * 插件自有的运行时私密存储。
 *
 * 凭据绝不进入 CRMEB system_config，也不会进入升级备份或发布包。目录和文件均
 * 位于 CRMEB 根目录的非 Web runtime 下；所有读写通过同一把文件锁串行化，写入
 * 使用同目录临时文件 + rename，避免多个 PHP worker 观察到半个 JSON 文档。
 */
final class SecretStore
{
    const DIRECTORY_NAME = 'bailinghub-secrets';
    const FILE_NAME = 'secrets.json';
    const LOCK_NAME = '.lock';
    const SCHEMA_VERSION = 2;
    const MAX_FILE_BYTES = 8192;
    const ACCESS_TOKEN = 'access_token';
    const SIGN_SECRET = 'sign_secret';

    private $directory;
    private $file;
    private $lockFile;

    /**
     * @param string|null $rootPath CRMEB 根目录；null 时从当前应用解析。
     */
    public function __construct($rootPath = null)
    {
        $rootPath = $rootPath === null ? $this->applicationRoot() : (string)$rootPath;
        $rootPath = rtrim($rootPath, '/\\');
        if ($rootPath === '') {
            throw new \RuntimeException('无法定位 CRMEB 项目根目录');
        }
        $runtime = $rootPath . DIRECTORY_SEPARATOR . 'runtime';
        if (is_link($runtime) || !is_dir($runtime)) {
            throw new \RuntimeException('CRMEB runtime 目录不存在或不是普通目录');
        }
        $this->directory = $runtime . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME;
        $this->file = $this->directory . DIRECTORY_SEPARATOR . self::FILE_NAME;
        $this->lockFile = $this->directory . DIRECTORY_SEPARATOR . self::LOCK_NAME;
    }

    /** 读取单个秘密。调用方不得记录或输出返回值。 */
    public function get($name)
    {
        $this->assertSecretName($name);
        return $this->withLock(LOCK_SH, function () use ($name) {
            $values = $this->readUnlocked();
            return isset($values[$name]) && is_string($values[$name]) ? $values[$name] : '';
        });
    }

    /** 只返回是否已配置，不返回秘密明文。 */
    public function status()
    {
        return $this->withLock(LOCK_SH, function () {
            return $this->summary($this->readUnlocked());
        });
    }

    /**
     * 在独占锁内替换非 null 的秘密，并可执行后续提交回调。
     *
     * 回调失败时会在释放锁前恢复旧文件，因此 SettingsRepository 可以把 CRMEB
     * 非秘密配置的数据库事务放进回调中，避免返回一个并未完整落盘的“成功”。
     */
    public function update(array $changes, $afterWrite = null)
    {
        foreach ($changes as $name => $value) {
            $this->assertSecretName($name);
            if ($value !== null && !is_string($value)) {
                throw new \RuntimeException('秘密配置值类型无效');
            }
        }
        if ($afterWrite !== null && !is_callable($afterWrite)) {
            throw new \RuntimeException('秘密配置提交回调无效');
        }

        return $this->withLock(LOCK_EX, function () use ($changes, $afterWrite) {
            $before = $this->readUnlocked();
            $after = $before;
            $changed = false;
            foreach ($changes as $name => $value) {
                if ($value === null) {
                    continue;
                }
                if (!isset($after[$name]) || !hash_equals((string)$after[$name], $value)) {
                    $after[$name] = $value;
                    $changed = true;
                }
            }

            if ($changed) {
                $this->writeUnlocked($after);
            }
            try {
                if ($afterWrite !== null) {
                    call_user_func($afterWrite);
                }
            } catch (\Throwable $e) {
                if ($changed) {
                    $this->writeUnlocked($before);
                }
                throw $e;
            }
            return $this->summary($after);
        });
    }

    /** 供卸载边界测试和脚本共享固定目录名，不含任何秘密。 */
    public static function directoryPath($rootPath)
    {
        return rtrim((string)$rootPath, '/\\') . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME;
    }

    private function applicationRoot()
    {
        if (function_exists('app')) {
            try {
                $application = app();
                if (is_object($application) && method_exists($application, 'getRootPath')) {
                    return (string)$application->getRootPath();
                }
            } catch (\Throwable $e) {
                // 继续使用 app/bailing/settings 的确定层级回退。
            }
        }
        return dirname(__DIR__, 3);
    }

    private function assertSecretName($name)
    {
        if ($name !== self::ACCESS_TOKEN && $name !== self::SIGN_SECRET) {
            throw new \RuntimeException('未知的秘密配置项');
        }
    }

    private function summary(array $values)
    {
        return array(
            'access_token_configured' => isset($values[self::ACCESS_TOKEN])
                && is_string($values[self::ACCESS_TOKEN]) && trim($values[self::ACCESS_TOKEN]) !== '',
            'sign_secret_configured' => isset($values[self::SIGN_SECRET])
                && is_string($values[self::SIGN_SECRET]) && trim($values[self::SIGN_SECRET]) !== '',
        );
    }

    private function withLock($operation, $callback)
    {
        $this->ensureDirectory();
        $handle = $this->openLockFile();
        if (!flock($handle, $operation)) {
            fclose($handle);
            throw new \RuntimeException('无法锁定百灵中枢私密配置');
        }
        // 获取锁后再次核对路径与已打开句柄仍是同一普通文件，拒绝换 inode 绕锁。
        if (!$this->isRegularHandle($handle, $this->lockFile)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new \RuntimeException('百灵中枢私密配置锁已被替换');
        }
        try {
            $result = call_user_func($callback);
            flock($handle, LOCK_UN);
            fclose($handle);
            return $result;
        } catch (\Throwable $e) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw $e;
        }
    }

    private function ensureDirectory()
    {
        if (is_link($this->directory)) {
            throw new \RuntimeException('百灵中枢私密配置目录不能是符号链接');
        }
        if (file_exists($this->directory) && !is_dir($this->directory)) {
            throw new \RuntimeException('百灵中枢私密配置路径不是目录');
        }
        if (!is_dir($this->directory)
            && !@mkdir($this->directory, 0700, false)
            && !is_dir($this->directory)) {
            throw new \RuntimeException('无法创建百灵中枢私密配置目录');
        }
        if (is_link($this->directory) || !is_dir($this->directory) || !@chmod($this->directory, 0700)) {
            throw new \RuntimeException('无法保护百灵中枢私密配置目录');
        }
    }

    private function openLockFile()
    {
        $this->rejectUnsafeFile($this->lockFile, true);
        if (!file_exists($this->lockFile)) {
            $created = $this->createPrivateFile($this->lockFile);
            if (is_resource($created)) {
                fclose($created);
            }
        }
        $this->rejectUnsafeFile($this->lockFile, false);
        // 只打开既有文件；c+b 会在竞态删除后重新创建并绕过受控 umask。
        $handle = @fopen($this->lockFile, 'r+b');
        if (!is_resource($handle) || !$this->isRegularHandle($handle, $this->lockFile)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('无法打开百灵中枢私密配置锁');
        }
        return $handle;
    }

    private function readUnlocked()
    {
        $this->rejectUnsafeFile($this->file, true);
        if (!file_exists($this->file)) {
            return array();
        }
        $handle = @fopen($this->file, 'rb');
        if (!is_resource($handle) || !$this->isRegularHandle($handle, $this->file)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('无法读取百灵中枢私密配置');
        }
        $stat = fstat($handle);
        if (!is_array($stat) || !isset($stat['size']) || (int)$stat['size'] < 0
            || (int)$stat['size'] > self::MAX_FILE_BYTES) {
            fclose($handle);
            throw new \RuntimeException('百灵中枢私密配置文件大小无效');
        }
        $raw = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
        fclose($handle);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('百灵中枢私密配置文件为空');
        }
        if (strlen($raw) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('百灵中枢私密配置文件过大');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int)(isset($decoded['schema_version']) ? $decoded['schema_version'] : 0) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('百灵中枢私密配置格式无效');
        }
        $values = array();
        foreach (array(self::ACCESS_TOKEN, self::SIGN_SECRET) as $name) {
            if (array_key_exists($name, $decoded)) {
                if (!is_string($decoded[$name])) {
                    throw new \RuntimeException('百灵中枢私密配置值类型无效');
                }
                $values[$name] = $decoded[$name];
            }
        }
        return $values;
    }

    private function writeUnlocked(array $values)
    {
        $document = array('schema_version' => self::SCHEMA_VERSION);
        foreach (array(self::ACCESS_TOKEN, self::SIGN_SECRET) as $name) {
            if (isset($values[$name]) && is_string($values[$name])) {
                $document[$name] = $values[$name];
            }
        }
        $encoded = json_encode($document, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('无法编码百灵中枢私密配置');
        }

        $this->rejectUnsafeFile($this->file, true);
        $temp = $this->directory . DIRECTORY_SEPARATOR . '.secrets-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = $this->createPrivateFile($temp);
        if (!is_resource($handle) || !$this->isRegularHandle($handle, $temp)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temp);
            throw new \RuntimeException('无法创建百灵中枢私密配置临时文件');
        }
        try {
            $written = 0;
            $length = strlen($encoded);
            while ($written < $length) {
                $bytes = fwrite($handle, substr($encoded, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new \RuntimeException('无法写入百灵中枢私密配置');
                }
                $written += $bytes;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('无法刷新百灵中枢私密配置');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new \RuntimeException('无法同步百灵中枢私密配置');
            }
            fclose($handle);
            $handle = null;
            $this->rejectUnsafeFile($this->file, true);
            if (!@rename($temp, $this->file)) {
                throw new \RuntimeException('无法原子切换百灵中枢私密配置');
            }
            clearstatcache(true, $this->file);
            $finalStat = @lstat($this->file);
            if (!is_array($finalStat)
                || !isset($finalStat['mode'])
                || (((int)$finalStat['mode'] & 0170000) !== 0100000)
                || (((int)$finalStat['mode'] & 0777) !== 0600)) {
                throw new \RuntimeException('百灵中枢私密配置落盘校验失败');
            }
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temp) || is_link($temp)) {
                @unlink($temp);
            }
            throw $e;
        }
    }

    private function rejectUnsafeFile($path, $allowMissing)
    {
        if (is_link($path)) {
            throw new \RuntimeException('百灵中枢私密配置不能使用符号链接');
        }
        if (file_exists($path) && !is_file($path)) {
            throw new \RuntimeException('百灵中枢私密配置路径不是普通文件');
        }
        if (!$allowMissing && !file_exists($path)) {
            throw new \RuntimeException('百灵中枢私密配置文件不存在');
        }
    }

    /**
     * PHP 7.1 没有可移植的 fchmod()。创建时临时收紧 umask，随后同时核对
     * 已打开句柄与路径 inode 及 0600 模式；既有宽权限文件一律拒绝而不修补。
     */
    private function createPrivateFile($path)
    {
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } catch (\Throwable $e) {
            umask($previousUmask);
            throw $e;
        }
        umask($previousUmask);
        if (is_resource($handle) && !$this->isRegularHandle($handle, $path)) {
            fclose($handle);
            @unlink($path);
            throw new \RuntimeException('无法创建受保护的百灵中枢私密配置文件');
        }
        return $handle;
    }

    private function isRegularHandle($handle, $path)
    {
        $stat = fstat($handle);
        $pathStat = @lstat($path);
        if (!is_array($stat) || !is_array($pathStat)
            || !isset($stat['mode'], $stat['dev'], $stat['ino'], $pathStat['mode'], $pathStat['dev'], $pathStat['ino'])) {
            return false;
        }
        return (((int)$stat['mode'] & 0170000) === 0100000)
            && (((int)$pathStat['mode'] & 0170000) === 0100000)
            && (((int)$stat['mode'] & 0777) === 0600)
            && (((int)$pathStat['mode'] & 0777) === 0600)
            && (string)$stat['dev'] === (string)$pathStat['dev']
            && (string)$stat['ino'] === (string)$pathStat['ino'];
    }
}
