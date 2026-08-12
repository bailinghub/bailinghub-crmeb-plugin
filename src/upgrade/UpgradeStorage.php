<?php
namespace app\bailing\upgrade;

final class UpgradeStorage
{
    private $baseDirectory;

    public function __construct($runtimePath)
    {
        $this->baseDirectory = rtrim((string)$runtimePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'bailinghub-updates';
    }

    public function baseDirectory()
    {
        return $this->baseDirectory;
    }

    public function nonceDirectory()
    {
        return $this->baseDirectory . DIRECTORY_SEPARATOR . 'nonces';
    }

    public function createStage($rawZip, $adminId)
    {
        if (!is_string($rawZip) || $rawZip === '' || strlen($rawZip) > PackageValidator::MAX_ZIP_BYTES) {
            throw new UpgradeException('升级包大小必须在 1 字节到 20MB 之间');
        }
        $this->pruneExpiredStages(86400);
        $id = bin2hex(random_bytes(16));
        $directory = $this->stageDirectory($id);
        FileSystem::ensureDirectory($directory, 0700);
        $packagePath = $directory . DIRECTORY_SEPARATOR . 'package.zip';
        if (file_put_contents($packagePath, $rawZip, LOCK_EX) !== strlen($rawZip)) {
            FileSystem::removeTree($directory);
            throw new UpgradeException('无法保存上传的升级包');
        }
        @chmod($packagePath, 0600);
        return [
            'id' => $id,
            'directory' => $directory,
            'package_path' => $packagePath,
            'payload_path' => $directory . DIRECTORY_SEPARATOR . 'payload',
            'admin_id' => (int)$adminId,
        ];
    }

    public function saveStageMeta($id, array $meta)
    {
        $meta['staged_id'] = $id;
        FileSystem::atomicJsonWrite($this->stageDirectory($id) . DIRECTORY_SEPARATOR . 'meta.json', $meta);
    }

    public function loadStage($id)
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new UpgradeException('无效的 staged_id');
        }
        $directory = $this->stageDirectory($id);
        $metaFile = $directory . DIRECTORY_SEPARATOR . 'meta.json';
        $package = $directory . DIRECTORY_SEPARATOR . 'package.zip';
        $meta = is_file($metaFile) ? json_decode((string)file_get_contents($metaFile), true) : null;
        if (!is_array($meta) || !is_file($package)) {
            throw new UpgradeException('暂存升级包不存在或已过期');
        }
        return [
            'id' => $id,
            'directory' => $directory,
            'package_path' => $package,
            'meta' => $meta,
        ];
    }

    public function appendHistory(array $event)
    {
        FileSystem::ensureDirectory($this->baseDirectory, 0700);
        $file = $this->baseDirectory . DIRECTORY_SEPARATOR . 'history.jsonl';
        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false || file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new UpgradeException('无法写入升级历史');
        }
        @chmod($file, 0600);
    }

    public function lastHistory()
    {
        $file = $this->baseDirectory . DIRECTORY_SEPARATOR . 'history.jsonl';
        if (!is_file($file)) {
            return null;
        }
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return null;
        }
        $last = '';
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $last = $line;
            }
        }
        fclose($handle);
        $decoded = $last !== '' ? json_decode($last, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    public function acquireLock()
    {
        FileSystem::ensureDirectory($this->baseDirectory, 0700);
        $handle = @fopen($this->baseDirectory . DIRECTORY_SEPARATOR . 'upgrade.lock', 'c+');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }
            throw new UpgradeException('已有插件升级正在执行，请稍后再试');
        }
        return $handle;
    }

    public static function releaseLock($handle)
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function backupDirectory($id)
    {
        $path = $this->baseDirectory . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR
            . gmdate('Ymd-His') . '-' . $id;
        FileSystem::ensureDirectory($path, 0700);
        return $path;
    }

    /**
     * 暂存包只用于一次升级，过期后删除；备份最多保留最近 5 份且不超过 30 天。
     */
    public function pruneExpiredStages($maxAge)
    {
        $directory = $this->baseDirectory . DIRECTORY_SEPARATOR . 'staged';
        try {
            $this->pruneDirectories($directory, max(3600, (int)$maxAge), 0);
        } catch (\Throwable $ignored) {
            // 保留策略是 best-effort，绝不能阻断一次受保护的升级。
        }
    }

    public function pruneBackups($keep = 5, $maxAge = 2592000)
    {
        $directory = $this->baseDirectory . DIRECTORY_SEPARATOR . 'backups';
        try {
            $this->pruneDirectories($directory, max(86400, (int)$maxAge), max(1, (int)$keep));
        } catch (\Throwable $ignored) {
            // 升级已经提交后，清理失败只意味着稍后再清理，不能改写成功事实。
        }
    }

    private function pruneDirectories($directory, $maxAge, $keep)
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = [];
        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot() || !$item->isDir() || $item->isLink()) {
                continue;
            }
            $items[] = ['path' => $item->getPathname(), 'mtime' => $item->getMTime()];
        }
        usort($items, function ($left, $right) {
            return $right['mtime'] - $left['mtime'];
        });
        $now = time();
        foreach ($items as $index => $item) {
            if ($now - $item['mtime'] <= $maxAge && ($keep === 0 || $index < $keep)) {
                continue;
            }
            try {
                FileSystem::removeTree($item['path']);
            } catch (\Throwable $ignored) {
                // 清理失败不影响新的升级；目录仍受 runtime 权限保护。
            }
        }
    }

    private function stageDirectory($id)
    {
        return $this->baseDirectory . DIRECTORY_SEPARATOR . 'staged' . DIRECTORY_SEPARATOR . $id;
    }
}
