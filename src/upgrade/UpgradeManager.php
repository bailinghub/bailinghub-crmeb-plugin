<?php
namespace app\bailing\upgrade;

use app\bailing\PluginInfo;

/**
 * 只管理 crmeb-bailinghub 自己的文件，不调用 CRMEB 官方在线升级器，
 * 也不修改 CRMEB 核心代码。
 */
final class UpgradeManager
{
    private $rootPath;
    private $storage;
    private $validator;

    public function __construct($rootPath, UpgradeStorage $storage, PackageValidator $validator)
    {
        $this->rootPath = rtrim((string)$rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->storage = $storage;
        $this->validator = $validator;
    }

    public function status()
    {
        return [
            'current' => PluginInfo::current(),
            'last_upgrade' => $this->storage->lastHistory(),
        ];
    }

    public function stage($rawZip, $adminId)
    {
        $stage = $this->storage->createStage($rawZip, $adminId);
        try {
            $result = $this->validator->validateAndExtract(
                $stage['package_path'],
                $stage['payload_path'],
                $this->rootPath
            );
            PhpLinter::lintTree($stage['payload_path']);
            $result['checks'][] = ['name' => 'php_lint', 'ok' => true, 'detail' => 'PHP 文件语法检查通过'];
            $meta = [
                'admin_id' => (int)$adminId,
                'staged_at' => gmdate('c'),
                'staged_at_epoch' => time(),
                'applied' => false,
                'candidate' => $result['candidate'],
                'compatibility' => $result['compatibility'],
                'checks' => $result['checks'],
                'package_sha256' => $result['package_sha256'],
            ];
            $this->storage->saveStageMeta($stage['id'], $meta);
            return [
                'staged_id' => $stage['id'],
                'candidate' => $result['candidate'],
                'compatibility' => $result['compatibility'],
                'checks' => $result['checks'],
            ];
        } catch (\Throwable $e) {
            try {
                FileSystem::removeTree($stage['directory']);
            } catch (\Throwable $ignored) {
            }
            if ($e instanceof UpgradeException) {
                throw $e;
            }
            throw new UpgradeException('升级包暂存验证失败');
        }
    }

    public function apply($stagedId, $adminId)
    {
        $lock = $this->storage->acquireLock();
        $stage = null;
        $candidate = null;
        $packageSha = '';
        $backupDir = '';
        $rolledBack = false;
        $committed = false;
        $switch = [
            'vendor_old_moved' => false,
            'vendor_new_installed' => false,
            'app_old_moved' => false,
            'app_new_installed' => false,
        ];
        $paths = [];

        try {
            $stage = $this->storage->loadStage($stagedId);
            $meta = $stage['meta'];
            if ((int)(isset($meta['admin_id']) ? $meta['admin_id'] : 0) !== (int)$adminId) {
                throw new UpgradeException('只能应用由当前管理员暂存的升级包');
            }
            if (!empty($meta['applied'])) {
                throw new UpgradeException('该升级包已经应用，禁止重复执行');
            }
            if ((int)(isset($meta['staged_at_epoch']) ? $meta['staged_at_epoch'] : 0) < time() - 3600) {
                throw new UpgradeException('暂存升级包已超过 1 小时，请重新上传');
            }
            $packageSha = hash_file('sha256', $stage['package_path']);
            if (!$packageSha || !hash_equals((string)$meta['package_sha256'], $packageSha)) {
                throw new UpgradeException('暂存升级包在应用前发生变化');
            }

            $recheckDir = $stage['directory'] . DIRECTORY_SEPARATOR . 'verified-' . bin2hex(random_bytes(6));
            $verified = $this->validator->validateAndExtract($stage['package_path'], $recheckDir, $this->rootPath);
            $candidate = $verified['candidate'];
            if (!hash_equals(
                hash('sha256', json_encode($meta['candidate'], JSON_UNESCAPED_SLASHES)),
                hash('sha256', json_encode($candidate, JSON_UNESCAPED_SLASHES))
            )) {
                throw new UpgradeException('暂存版本信息在应用前发生变化');
            }
            PhpLinter::lintTree($recheckDir);

            $paths = $this->preparePaths($stagedId);
            $this->assertRuntimeTargets($paths);
            $backupDir = $this->createBackup($stagedId, $paths);
            FileSystem::copyTree($recheckDir, $paths['vendor_candidate']);
            FileSystem::copyTree($recheckDir . DIRECTORY_SEPARATOR . 'src', $paths['app_candidate']);

            // 必须在 rename 之前失效旧树：若新版本删除了 PHP 文件，切换后已无法
            // 通过遍历新树找到旧路径；validate_timestamps=0 时旧字节码可能继续命中。
            $this->invalidatePluginOpcache($paths['vendor_target']);
            $this->invalidatePluginOpcache($paths['app_target']);

            if (!@rename($paths['vendor_target'], $paths['vendor_old'])) {
                throw new UpgradeException('无法切换旧版 vendor 插件目录');
            }
            $switch['vendor_old_moved'] = true;
            if (!@rename($paths['vendor_candidate'], $paths['vendor_target'])) {
                throw new UpgradeException('无法启用新版 vendor 插件目录');
            }
            $switch['vendor_new_installed'] = true;

            if (!@rename($paths['app_target'], $paths['app_old'])) {
                throw new UpgradeException('无法切换旧版 app/bailing 目录');
            }
            $switch['app_old_moved'] = true;
            if (!@rename($paths['app_candidate'], $paths['app_target'])) {
                throw new UpgradeException('无法启用新版 app/bailing 目录');
            }
            $switch['app_new_installed'] = true;

            $this->verifyInstalledTree($recheckDir, $paths, $candidate, $backupDir);
            $this->invalidatePluginOpcache($paths['vendor_target']);
            $this->invalidatePluginOpcache($paths['app_target']);

            $event = $this->historyEvent($adminId, $stagedId, $candidate, $packageSha, 'success', false, '');
            $this->storage->appendHistory($event);
            $committed = true;
            $this->bestEffortRemove($paths['vendor_old']);
            $this->bestEffortRemove($paths['app_old']);
            $this->bestEffortRemove($recheckDir);
            $this->bestEffortRemove($stage['directory']);
            try {
                $this->storage->pruneBackups(5, 2592000);
            } catch (\Throwable $ignored) {
                // 成功事实已经写入；任何保留策略失败都只能留待后续清理。
            }

            return [
                'success' => true,
                'message' => '百灵中枢插件已升级到 ' . $candidate['adapter_version'],
                'rolled_back' => false,
                'current' => [
                    'adapter_version' => $candidate['adapter_version'],
                    'tool_spec_version' => $candidate['tool_spec_version'],
                    'config_schema_version' => $candidate['config_schema_version'],
                ],
            ];
        } catch (\Throwable $e) {
            if (!$committed && $paths) {
                $rolledBack = $this->rollbackSwitch($paths, $switch, $backupDir);
            }
            $fallbackCandidate = is_array($candidate) ? $candidate : [
                'adapter_version' => isset($stage['meta']['candidate']['adapter_version'])
                    ? $stage['meta']['candidate']['adapter_version'] : 'unknown',
            ];
            try {
                $this->storage->appendHistory($this->historyEvent(
                    $adminId,
                    (string)$stagedId,
                    $fallbackCandidate,
                    $packageSha,
                    'failed',
                    $rolledBack,
                    $e->getMessage()
                ));
            } catch (\Throwable $ignored) {
            }
            if ($e instanceof UpgradeException) {
                throw new UpgradeException($e->getMessage() . ($rolledBack ? '；已自动恢复旧版' : ''));
            }
            throw new UpgradeException('插件升级失败' . ($rolledBack ? '，已自动恢复旧版' : ''));
        } finally {
            UpgradeStorage::releaseLock($lock);
        }
    }

    private function preparePaths($id)
    {
        $vendorParent = $this->rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'crmeb';
        $appParent = $this->rootPath . 'app';
        return [
            'vendor_target' => $vendorParent . DIRECTORY_SEPARATOR . 'bailinghub',
            'vendor_candidate' => $vendorParent . DIRECTORY_SEPARATOR . '.bailinghub-upgrade-' . $id,
            'vendor_old' => $vendorParent . DIRECTORY_SEPARATOR . '.bailinghub-old-' . $id,
            'app_target' => $appParent . DIRECTORY_SEPARATOR . 'bailing',
            'app_candidate' => $appParent . DIRECTORY_SEPARATOR . '.bailing-upgrade-' . $id,
            'app_old' => $appParent . DIRECTORY_SEPARATOR . '.bailing-old-' . $id,
            'config' => $this->rootPath . 'config' . DIRECTORY_SEPARATOR . 'bailing.php',
            'services' => $this->rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'services.php',
        ];
    }

    private function assertRuntimeTargets(array $paths)
    {
        foreach (['vendor_target', 'app_target'] as $name) {
            if (!is_dir($paths[$name]) || is_link($paths[$name])) {
                throw new UpgradeException('当前插件安装不完整，缺少 ' . basename($paths[$name]));
            }
        }
        foreach (['vendor_candidate', 'vendor_old', 'app_candidate', 'app_old'] as $name) {
            if (file_exists($paths[$name]) || is_link($paths[$name])) {
                throw new UpgradeException('发现未收口的历史升级目录，请先排查');
            }
        }
    }

    private function createBackup($id, array $paths)
    {
        $backup = $this->storage->backupDirectory($id);
        FileSystem::copyTree($paths['vendor_target'], $backup . DIRECTORY_SEPARATOR . 'vendor-package');
        FileSystem::copyTree($paths['app_target'], $backup . DIRECTORY_SEPARATOR . 'app-bailing');
        if (!$this->treesMatch($paths['vendor_target'], $backup . DIRECTORY_SEPARATOR . 'vendor-package')
            || !$this->treesMatch($paths['app_target'], $backup . DIRECTORY_SEPARATOR . 'app-bailing')) {
            throw new UpgradeException('插件代码备份落盘校验失败');
        }
        $runtimeFiles = [];
        foreach (['config', 'services'] as $name) {
            $exists = is_file($paths[$name]) && !is_link($paths[$name]);
            $runtimeFiles[$name] = [
                'exists' => $exists,
                'sha256' => $exists ? hash_file('sha256', $paths[$name]) : null,
            ];
            if ($exists) {
                FileSystem::copyFile($paths[$name], $backup . DIRECTORY_SEPARATOR . $name . '.bak');
            }
        }
        FileSystem::atomicJsonWrite($backup . DIRECTORY_SEPARATOR . 'backup.json', [
            'created_at' => gmdate('c'),
            'runtime_files' => $runtimeFiles,
        ]);
        return $backup;
    }

    private function verifyInstalledTree($source, array $paths, array $candidate, $backupDir)
    {
        $manifestFile = $paths['vendor_target'] . DIRECTORY_SEPARATOR . 'plugin.json';
        $manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
        if (!is_array($manifest) || !isset($manifest['plugin_version'])
            || $manifest['plugin_version'] !== $candidate['adapter_version']) {
            throw new UpgradeException('新版插件版本自检失败');
        }
        foreach ([
            'PluginInfo.php',
            'BailingService.php',
            'route' . DIRECTORY_SEPARATOR . 'route.php',
            'controller' . DIRECTORY_SEPARATOR . 'AdminAssetController.php',
            'controller' . DIRECTORY_SEPARATOR . 'BailingSettingsController.php',
            'settings' . DIRECTORY_SEPARATOR . 'SettingsException.php',
            'settings' . DIRECTORY_SEPARATOR . 'SettingsInput.php',
            'settings' . DIRECTORY_SEPARATOR . 'SettingsRepository.php',
            'settings' . DIRECTORY_SEPARATOR . 'SecretStore.php',
        ] as $relative) {
            if (!is_file($paths['app_target'] . DIRECTORY_SEPARATOR . $relative)) {
                throw new UpgradeException('新版运行目录缺少必要文件');
            }
        }
        $route = (string)file_get_contents($paths['app_target'] . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR . 'route.php');
        foreach ([
            'plugin-upgrade/status',
            'plugin-upgrade/stage',
            'plugin-upgrade/apply',
            'tools.json',
            'chat-ticket',
            'admin-bundle',
            'settings/status',
            'settings/save',
        ] as $needle) {
            if (strpos($route, $needle) === false) {
                throw new UpgradeException('新版路由自检失败');
            }
        }
        $this->verifyCopiedHashes($source, $paths['vendor_target']);
        $this->verifyCopiedHashes($source . DIRECTORY_SEPARATOR . 'src', $paths['app_target']);
    }

    private function verifyCopiedHashes($source, $target)
    {
        $sourceLength = strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = substr($file->getPathname(), $sourceLength);
            $installed = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
            if (!is_file($installed)
                || !hash_equals((string)hash_file('sha256', $file->getPathname()), (string)hash_file('sha256', $installed))) {
                throw new UpgradeException('新版插件文件落盘校验失败');
            }
        }
    }

    private function rollbackSwitch(array $paths, array $switch, $backupDir)
    {
        $didSwitch = $switch['app_new_installed'] || $switch['app_old_moved']
            || $switch['vendor_new_installed'] || $switch['vendor_old_moved'];
        if (!$didSwitch) {
            $this->bestEffortRemove($paths['vendor_candidate']);
            $this->bestEffortRemove($paths['app_candidate']);
            return false;
        }
        $vendorOk = $this->restorePluginDirectory(
            $paths['vendor_target'],
            $paths['vendor_old'],
            $backupDir . DIRECTORY_SEPARATOR . 'vendor-package',
            $switch['vendor_new_installed'],
            $switch['vendor_old_moved']
        );
        $appOk = $this->restorePluginDirectory(
            $paths['app_target'],
            $paths['app_old'],
            $backupDir . DIRECTORY_SEPARATOR . 'app-bailing',
            $switch['app_new_installed'],
            $switch['app_old_moved']
        );
        $this->bestEffortRemove($paths['vendor_candidate']);
        $this->bestEffortRemove($paths['app_candidate']);
        if ($vendorOk) {
            $this->invalidatePluginOpcache($paths['vendor_target']);
        }
        if ($appOk) {
            $this->invalidatePluginOpcache($paths['app_target']);
        }
        return $vendorOk && $appOk;
    }

    private function restorePluginDirectory($target, $old, $backup, $newInstalled, $oldMoved)
    {
        try {
            if ($newInstalled && (is_dir($target) || is_link($target))) {
                FileSystem::removeTree($target);
            }
            if ($oldMoved && is_dir($old) && !file_exists($target) && !is_link($target)) {
                @rename($old, $target);
            }
            if ($this->treesMatch($backup, $target)) {
                $this->bestEffortRemove($old);
                return true;
            }

            // rename 恢复失败或恢复结果与备份不一致时，使用只读备份兜底重建。
            if (file_exists($target) || is_link($target)) {
                FileSystem::removeTree($target);
            }
            FileSystem::copyTree($backup, $target);
            $ok = $this->treesMatch($backup, $target);
            if ($ok) {
                $this->bestEffortRemove($old);
            }
            return $ok;
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    private function treesMatch($left, $right)
    {
        if (!is_dir($left) || !is_dir($right) || is_link($left) || is_link($right)) {
            return false;
        }
        try {
            return $this->treeHashes($left) === $this->treeHashes($right);
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    private function treeHashes($directory)
    {
        $result = [];
        $baseLength = strlen(rtrim($directory, DIRECTORY_SEPARATOR)) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                if ($item->isLink()) {
                    throw new UpgradeException('回滚目录包含符号链接');
                }
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), $baseLength));
            $result[$relative] = hash_file('sha256', $item->getPathname());
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function invalidatePluginOpcache($directory)
    {
        clearstatcache();
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        foreach (FileSystem::phpFiles($directory) as $file) {
            @opcache_invalidate($file, true);
        }
    }

    private function historyEvent($adminId, $stagedId, array $candidate, $packageSha, $status, $rolledBack, $error)
    {
        return [
            'timestamp' => gmdate('c'),
            'admin_id' => (int)$adminId,
            'from_version' => PluginInfo::PLUGIN_VERSION,
            'to_version' => isset($candidate['adapter_version']) ? (string)$candidate['adapter_version'] : 'unknown',
            'staged_id' => preg_match('/^[a-f0-9]{32}$/', (string)$stagedId) ? (string)$stagedId : 'invalid',
            'package_sha256' => preg_match('/^[a-f0-9]{64}$/', (string)$packageSha) ? (string)$packageSha : '',
            'status' => $status === 'success' ? 'success' : 'failed',
            'rolled_back' => (bool)$rolledBack,
            'error' => $status === 'success' ? '' : substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string)$error), 0, 300),
        ];
    }

    private function bestEffortRemove($path)
    {
        try {
            FileSystem::removeTree($path);
        } catch (\Throwable $ignored) {
        }
    }
}
