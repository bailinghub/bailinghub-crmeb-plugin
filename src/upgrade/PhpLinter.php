<?php
namespace app\bailing\upgrade;

final class PhpLinter
{
    public static function lintTree($directory)
    {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            throw new UpgradeException('服务器禁用了 exec，无法执行升级前 PHP 语法检查');
        }
        $phpBinary = defined('PHP_BINDIR') ? rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'php' : PHP_BINARY;
        if (!is_file($phpBinary) || !is_executable($phpBinary)) {
            throw new UpgradeException('服务器缺少可执行的 PHP CLI，无法执行升级前语法检查');
        }
        foreach (FileSystem::phpFiles($directory) as $file) {
            $output = [];
            $code = 1;
            @exec(escapeshellarg($phpBinary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            if ($code !== 0) {
                throw new UpgradeException('PHP 语法检查失败: ' . basename($file));
            }
        }
        return true;
    }
}
