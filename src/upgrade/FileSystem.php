<?php
namespace app\bailing\upgrade;

final class FileSystem
{
    public static function ensureDirectory($directory, $mode = 0700)
    {
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new UpgradeException('无法创建目录: ' . basename((string)$directory));
        }
        @chmod($directory, $mode);
    }

    public static function copyTree($source, $destination)
    {
        if (!is_dir($source) || is_link($source)) {
            throw new UpgradeException('升级源目录无效: ' . basename((string)$source));
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new UpgradeException('升级目标目录已经存在: ' . basename((string)$destination));
        }
        self::ensureDirectory($destination, 0700);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new UpgradeException('插件目录禁止包含符号链接');
            }
            $relative = substr($item->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
            $target = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                self::ensureDirectory($target, 0700);
            } elseif ($item->isFile()) {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    self::ensureDirectory($parent, 0700);
                }
                if (!@copy($item->getPathname(), $target)) {
                    throw new UpgradeException('无法复制插件文件: ' . basename($relative));
                }
                @chmod($target, 0640);
            } else {
                throw new UpgradeException('插件目录包含不支持的文件类型');
            }
        }
    }

    public static function copyFile($source, $destination)
    {
        if (!is_file($source) || is_link($source)) {
            throw new UpgradeException('备份源文件无效: ' . basename((string)$source));
        }
        self::ensureDirectory(dirname($destination), 0700);
        if (!@copy($source, $destination)) {
            throw new UpgradeException('无法备份文件: ' . basename((string)$source));
        }
        @chmod($destination, 0600);
    }

    public static function atomicJsonWrite($path, array $data)
    {
        self::ensureDirectory(dirname($path), 0700);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new UpgradeException('无法保存升级状态');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new UpgradeException('无法提交升级状态');
        }
    }

    public static function removeTree($path)
    {
        $path = rtrim((string)$path, DIRECTORY_SEPARATOR);
        if ($path === '' || strlen($path) < 10 || dirname($path) === $path) {
            throw new UpgradeException('拒绝清理不安全路径');
        }
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path) && file_exists($path)) {
                throw new UpgradeException('无法清理升级文件');
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            $ok = $item->isDir() && !$item->isLink() ? @rmdir($itemPath) : @unlink($itemPath);
            if (!$ok && file_exists($itemPath)) {
                throw new UpgradeException('无法清理升级目录');
            }
        }
        if (!@rmdir($path) && is_dir($path)) {
            throw new UpgradeException('无法清理升级目录');
        }
    }

    public static function phpFiles($directory)
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink() && strtolower($item->getExtension()) === 'php') {
                $files[] = $item->getPathname();
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }
}
