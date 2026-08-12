<?php
namespace app\bailing\upgrade;

use app\bailing\PluginInfo;

/**
 * 对离线升级包做完整、可重复的验证。
 *
 * ZIP 根目录必须恰好等于：checksums.files 的所有文件 + checksums.json + plugin.sig。
 * plugin.sig 是对 plugin.json 原始字节、一个 LF、checksums.json 原始字节的
 * RSA-SHA256 签名。checksums.json 又精确绑定每一个 payload 文件。
 */
final class PackageValidator
{
    const MAX_ZIP_BYTES = 20971520;
    const MAX_UNCOMPRESSED_BYTES = 52428800;
    const MAX_FILE_BYTES = 10485760;
    const MAX_METADATA_BYTES = 1048576;
    const MAX_ENTRIES = 2000;

    private $publicKeyPath;

    public function __construct($publicKeyPath)
    {
        $this->publicKeyPath = (string)$publicKeyPath;
    }

    /**
     * 验证并把已签名 payload 解压到一个全新的目录。
     */
    public function validateAndExtract($zipPath, $extractDir, $rootPath)
    {
        if (!class_exists('\ZipArchive')) {
            throw new UpgradeException('服务器未安装 ZIP 扩展，无法验证升级包');
        }
        if (!extension_loaded('openssl')) {
            throw new UpgradeException('服务器未安装 OpenSSL 扩展，无法验证升级包签名');
        }
        if (!is_file($zipPath)) {
            throw new UpgradeException('升级包不存在');
        }
        $zipSize = (int)filesize($zipPath);
        if ($zipSize < 1 || $zipSize > self::MAX_ZIP_BYTES) {
            throw new UpgradeException('升级包大小必须在 1 字节到 20MB 之间');
        }
        if (file_exists($extractDir)) {
            throw new UpgradeException('升级包解压目标必须是全新目录');
        }

        $zip = new \ZipArchive();
        // 不传 CREATE/OVERWRITE 标志时默认只读；这也兼容未暴露 RDONLY 常量的 PHP 7.1。
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new UpgradeException('无法打开升级 ZIP 包');
        }

        try {
            $entries = $this->inspectEntries($zip);
            $pluginRaw = $this->readMetadata($zip, $entries, 'plugin.json');
            $checksumsRaw = $this->readMetadata($zip, $entries, 'checksums.json');
            $signatureRaw = $this->readMetadata($zip, $entries, 'plugin.sig');

            $manifest = json_decode($pluginRaw, true);
            if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
                throw new UpgradeException('plugin.json 不是有效 JSON');
            }
            $checksumDocument = json_decode($checksumsRaw, true);
            if (!is_array($checksumDocument) || json_last_error() !== JSON_ERROR_NONE) {
                throw new UpgradeException('checksums.json 不是有效 JSON');
            }

            $files = $this->validateChecksumDocument($checksumDocument);
            $this->validateExactFileSet($entries, $files);
            $this->verifyPayloadChecksums($zip, $entries, $files);
            $this->verifySignature($pluginRaw . "\n" . $checksumsRaw, $signatureRaw);
            $this->validateEmbeddedVersionSources($zip, $entries, $manifest);
            $candidate = $this->validateManifest($manifest);
            $compatibility = $this->checkCompatibility($manifest, $rootPath, $zipSize);

            if (!@mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
                throw new UpgradeException('无法创建升级包暂存目录');
            }
            @chmod($extractDir, 0700);
            try {
                $this->extractPayload($zip, $entries, $files, $extractDir);
            } catch (\Throwable $e) {
                FileSystem::removeTree($extractDir);
                throw $e;
            }

            return [
                'candidate' => $candidate,
                'compatibility' => $compatibility,
                'checks' => [
                    ['name' => 'exact_file_set', 'ok' => true, 'detail' => count($files) . ' 个已签名文件'],
                    ['name' => 'sha256', 'ok' => true, 'detail' => '所有文件哈希匹配'],
                    ['name' => 'rsa_signature', 'ok' => true, 'detail' => 'RSA-SHA256 签名有效'],
                    ['name' => 'version_upgrade', 'ok' => true, 'detail' => PluginInfo::PLUGIN_VERSION . ' -> ' . $candidate['adapter_version']],
                    ['name' => 'compatibility', 'ok' => true, 'detail' => 'PHP 与 CRMEB 版本兼容'],
                ],
                'manifest' => $manifest,
                'package_sha256' => hash_file('sha256', $zipPath),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * 纯函数：ZIP 路径必须是相对、规范、无反斜杠的普通文件路径。
     */
    public static function isSafeEntryName($name)
    {
        $name = (string)$name;
        if ($name === '' || strlen($name) > 512 || strpos($name, "\0") !== false
            || strpos($name, '\\') !== false || $name[0] === '/' || substr($name, -1) === '/'
            || strpos($name, ':') !== false || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return false;
        }
        $parts = explode('/', $name);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * 纯函数：插件版本只接受严格 SemVer，并且必须严格高于当前版本。
     */
    public static function isStrictUpgradeVersion($candidate, $current)
    {
        $pattern = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/';
        return is_string($candidate) && preg_match($pattern, $candidate)
            && is_string($current) && preg_match($pattern, $current)
            && version_compare($candidate, $current, '>');
    }

    private function inspectEntries(\ZipArchive $zip)
    {
        if ($zip->numFiles < 3 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new UpgradeException('升级包文件数量异常');
        }
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new UpgradeException('无法读取 ZIP 文件项');
            }
            $name = (string)$stat['name'];
            if (!self::isSafeEntryName($name)) {
                throw new UpgradeException('升级包包含不安全路径: ' . self::safeLabel($name));
            }
            if (isset($entries[$name])) {
                throw new UpgradeException('升级包包含重复文件: ' . self::safeLabel($name));
            }
            if ($this->isSymlink($zip, $i)) {
                throw new UpgradeException('升级包禁止包含符号链接: ' . self::safeLabel($name));
            }
            $size = (int)(isset($stat['size']) ? $stat['size'] : 0);
            if ($size < 0 || $size > self::MAX_FILE_BYTES) {
                throw new UpgradeException('升级包单个文件超过 10MB: ' . self::safeLabel($name));
            }
            $total += $size;
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new UpgradeException('升级包解压后总大小超过 50MB');
            }
            $entries[$name] = ['index' => $i, 'size' => $size];
        }
        return $entries;
    }

    private function isSymlink(\ZipArchive $zip, $index)
    {
        $opsys = 0;
        $attributes = 0;
        if (!method_exists($zip, 'getExternalAttributesIndex')
            || !$zip->getExternalAttributesIndex($index, $opsys, $attributes, \ZipArchive::FL_UNCHANGED)) {
            return false;
        }
        if (defined('ZipArchive::OPSYS_UNIX') && $opsys === \ZipArchive::OPSYS_UNIX) {
            return (($attributes >> 16) & 0170000) === 0120000;
        }
        return false;
    }

    private function readMetadata(\ZipArchive $zip, array $entries, $name)
    {
        if (!isset($entries[$name])) {
            throw new UpgradeException('升级包缺少 ' . $name);
        }
        if ((int)$entries[$name]['size'] > self::MAX_METADATA_BYTES) {
            throw new UpgradeException($name . ' 超过 1MB');
        }
        $content = $zip->getFromIndex($entries[$name]['index'], 0, \ZipArchive::FL_UNCHANGED);
        if ($content === false) {
            throw new UpgradeException('无法读取 ' . $name);
        }
        return (string)$content;
    }

    private function validateChecksumDocument(array $document)
    {
        if (!isset($document['algorithm']) || $document['algorithm'] !== 'sha256'
            || !isset($document['files']) || !is_array($document['files']) || !$document['files']) {
            throw new UpgradeException('checksums.json 必须使用 sha256 并包含 files 对象');
        }
        $files = [];
        foreach ($document['files'] as $path => $checksum) {
            if (!is_string($path) || !self::isSafeEntryName($path)
                || !is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
                throw new UpgradeException('checksums.json 包含无效文件项');
            }
            if ($path === 'checksums.json' || $path === 'plugin.sig') {
                throw new UpgradeException('checksums.files 不能包含签名元文件');
            }
            if (!self::isAllowedPayloadPath($path)) {
                throw new UpgradeException('升级包包含插件边界之外的文件: ' . self::safeLabel($path));
            }
            $files[$path] = $checksum;
        }
        if (!isset($files['plugin.json'])) {
            throw new UpgradeException('checksums.files 必须覆盖 plugin.json');
        }
        foreach (['composer.json', 'scripts/install.php', 'src/PluginInfo.php', 'src/BailingSpec.php',
                     'src/BailingService.php', 'src/route/route.php',
                     'src/controller/AdminAssetController.php',
                     'src/controller/BailingSettingsController.php',
                     'src/settings/SettingsException.php',
                     'src/settings/SettingsInput.php',
                     'src/settings/SettingsRepository.php',
                     'src/settings/SecretStore.php'] as $required) {
            if (!isset($files[$required])) {
                throw new UpgradeException('升级包缺少必要插件文件: ' . $required);
            }
        }
        return $files;
    }

    public static function isAllowedPayloadPath($path)
    {
        $rootFiles = ['plugin.json', 'composer.json', 'README.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE'];
        if (in_array($path, $rootFiles, true)) {
            return true;
        }
        foreach (['config/', 'scripts/', 'src/'] as $prefix) {
            if (strncmp($path, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    private function validateExactFileSet(array $entries, array $files)
    {
        $expected = array_keys($files);
        $expected[] = 'checksums.json';
        $expected[] = 'plugin.sig';
        sort($expected, SORT_STRING);
        $actual = array_keys($entries);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            $extra = array_values(array_diff($actual, $expected));
            $missing = array_values(array_diff($expected, $actual));
            $detail = $extra ? '，额外: ' . implode(', ', array_slice($extra, 0, 5)) : '';
            $detail .= $missing ? '，缺少: ' . implode(', ', array_slice($missing, 0, 5)) : '';
            throw new UpgradeException('ZIP 文件集与已签名清单不一致' . $detail);
        }
    }

    private function verifyPayloadChecksums(\ZipArchive $zip, array $entries, array $files)
    {
        foreach ($files as $path => $expected) {
            $stream = $zip->getStream($path);
            if (!is_resource($stream)) {
                throw new UpgradeException('无法读取已签名文件: ' . self::safeLabel($path));
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    fclose($stream);
                    throw new UpgradeException('无法读取已签名文件: ' . self::safeLabel($path));
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_FILE_BYTES || $bytes > (int)$entries[$path]['size']) {
                    fclose($stream);
                    throw new UpgradeException('ZIP 文件实际大小超过已声明值: ' . self::safeLabel($path));
                }
                hash_update($hash, $chunk);
            }
            fclose($stream);
            if ($bytes !== (int)$entries[$path]['size']) {
                throw new UpgradeException('ZIP 文件实际大小与已声明值不符: ' . self::safeLabel($path));
            }
            if (!hash_equals($expected, hash_final($hash))) {
                throw new UpgradeException('文件哈希不匹配: ' . self::safeLabel($path));
            }
        }
    }

    private function verifySignature($signedData, $signatureText)
    {
        if (!is_file($this->publicKeyPath)) {
            throw new UpgradeException('插件未内置发行公钥，禁止升级');
        }
        $signature = base64_decode(trim((string)$signatureText), true);
        if ($signature === false || $signature === '') {
            throw new UpgradeException('plugin.sig 不是有效 Base64 签名');
        }
        $publicKey = openssl_pkey_get_public((string)file_get_contents($this->publicKeyPath));
        if ($publicKey === false) {
            throw new UpgradeException('插件发行公钥无效');
        }
        $verified = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if (is_resource($publicKey)) {
            openssl_free_key($publicKey);
        }
        if ($verified !== 1) {
            throw new UpgradeException('升级包发行签名验证失败');
        }
    }

    /**
     * 发行签名通过后仍要核对包内所有版本来源，防止一个合法签名包内部自相矛盾。
     * 候选 PHP 不能被 require；这里只对约定的字面量常量做严格解析。
     */
    private function validateEmbeddedVersionSources(\ZipArchive $zip, array $entries, array $manifest)
    {
        $composer = json_decode($this->readMetadata($zip, $entries, 'composer.json'), true);
        $composerPlugin = is_array($composer) && isset($composer['extra']['bailinghub-plugin'])
            && is_array($composer['extra']['bailinghub-plugin']) ? $composer['extra']['bailinghub-plugin'] : [];
        if (!isset($composerPlugin['plugin-version'], $composerPlugin['tool-spec-version'], $composerPlugin['schema-version'])
            || $composerPlugin['plugin-version'] !== (isset($manifest['plugin_version']) ? $manifest['plugin_version'] : null)
            || $composerPlugin['tool-spec-version'] !== (isset($manifest['spec_version']) ? $manifest['spec_version'] : null)
            || (int)$composerPlugin['schema-version'] !== (isset($manifest['schema_version']) ? (int)$manifest['schema_version'] : -1)) {
            throw new UpgradeException('composer.json 插件版本元数据与 plugin.json 不一致');
        }

        $pluginInfo = $this->readMetadata($zip, $entries, 'src/PluginInfo.php');
        $expectedConstants = [
            'PLUGIN_VERSION' => isset($manifest['plugin_version']) ? $manifest['plugin_version'] : null,
            'TOOL_SPEC_VERSION' => isset($manifest['spec_version']) ? $manifest['spec_version'] : null,
            'CONFIG_SCHEMA_VERSION' => isset($manifest['schema_version']) ? (string)$manifest['schema_version'] : null,
        ];
        foreach ($expectedConstants as $constant => $expected) {
            $pattern = $constant === 'CONFIG_SCHEMA_VERSION'
                ? '/\bconst\s+' . $constant . '\s*=\s*([0-9]+)\s*;/'
                : '/\bconst\s+' . $constant . '\s*=\s*([\'\"])([^\'\"]+)\1\s*;/';
            if (!preg_match($pattern, $pluginInfo, $matches)) {
                throw new UpgradeException('src/PluginInfo.php 缺少字面量常量 ' . $constant);
            }
            $actual = $constant === 'CONFIG_SCHEMA_VERSION' ? $matches[1] : $matches[2];
            if ((string)$actual !== (string)$expected) {
                throw new UpgradeException('src/PluginInfo.php 的 ' . $constant . ' 与 plugin.json 不一致');
            }
        }

        $bailingSpec = $this->readMetadata($zip, $entries, 'src/BailingSpec.php');
        if (!preg_match('/\bconst\s+SPEC_VERSION\s*=\s*([\'\"])([^\'\"]+)\1\s*;/', $bailingSpec, $matches)
            || !isset($manifest['spec_version']) || $matches[2] !== $manifest['spec_version']) {
            throw new UpgradeException('src/BailingSpec.php 的 SPEC_VERSION 与 plugin.json 不一致');
        }
    }

    private function validateManifest(array $manifest)
    {
        $required = ['format_version', 'name', 'plugin_version', 'spec_version', 'schema_version', 'php_min',
            'crmeb_edition', 'bailinghub_min', 'release_notes', 'migrations'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $manifest)) {
                throw new UpgradeException('plugin.json 缺少字段: ' . $key);
            }
        }
        if ((int)$manifest['format_version'] !== PluginInfo::PACKAGE_FORMAT_VERSION
            || $manifest['name'] !== PluginInfo::PACKAGE_NAME) {
            throw new UpgradeException('升级包格式或插件名称不匹配');
        }
        if (!self::isStrictUpgradeVersion($manifest['plugin_version'], PluginInfo::PLUGIN_VERSION)) {
            throw new UpgradeException('目标版本必须是严格高于 ' . PluginInfo::PLUGIN_VERSION . ' 的 SemVer 版本');
        }
        foreach (['spec_version', 'php_min', 'bailinghub_min'] as $semverField) {
            if (!is_string($manifest[$semverField]) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $manifest[$semverField])) {
                throw new UpgradeException('plugin.json 字段格式无效: ' . $semverField);
            }
        }
        if (!is_int($manifest['schema_version']) || $manifest['schema_version'] < PluginInfo::CONFIG_SCHEMA_VERSION) {
            throw new UpgradeException('配置结构版本不能倒退');
        }
        if ($manifest['schema_version'] !== PluginInfo::CONFIG_SCHEMA_VERSION) {
            throw new UpgradeException('当前升级器只支持配置结构版本 ' . PluginInfo::CONFIG_SCHEMA_VERSION);
        }
        if (version_compare($manifest['spec_version'], PluginInfo::TOOL_SPEC_VERSION, '<')) {
            throw new UpgradeException('工具清单版本不能倒退');
        }
        if ($manifest['crmeb_edition'] !== PluginInfo::CRMEB_EDITION) {
            throw new UpgradeException('升级包不适用于当前 CRMEB 版本');
        }
        if (!is_array($manifest['release_notes']) || !is_array($manifest['migrations'])) {
            throw new UpgradeException('release_notes 与 migrations 必须是数组');
        }
        if ($manifest['migrations']) {
            throw new UpgradeException('当前升级器不接受未定义的迁移脚本');
        }
        return [
            'adapter_version' => $manifest['plugin_version'],
            'tool_spec_version' => $manifest['spec_version'],
            'config_schema_version' => $manifest['schema_version'],
            'php_min' => $manifest['php_min'],
            'crmeb_edition' => $manifest['crmeb_edition'],
            'bailinghub_min' => $manifest['bailinghub_min'],
            'release_notes' => array_values($manifest['release_notes']),
        ];
    }

    private function checkCompatibility(array $manifest, $rootPath, $zipSize)
    {
        $rootPath = rtrim((string)$rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $phpOk = version_compare(PHP_VERSION, $manifest['php_min'], '>=');
        if (!$phpOk) {
            throw new UpgradeException('当前 PHP ' . PHP_VERSION . ' 低于升级包要求的 ' . $manifest['php_min']);
        }
        if (!is_dir($rootPath . 'app') || !is_dir($rootPath . 'vendor')) {
            throw new UpgradeException('无法确认 CRMEB 项目根目录');
        }
        $versionFile = $rootPath . '.version';
        $versionText = is_file($versionFile) ? (string)file_get_contents($versionFile) : '';
        if (!preg_match('/^version\s*=\s*CRMEB-KY\s+v6(?:\.|\s|$)/mi', $versionText)) {
            throw new UpgradeException('只允许在 CRMEB-KY v6 上执行此升级');
        }
        foreach ([$rootPath . 'runtime', $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'crmeb', $rootPath . 'app'] as $directory) {
            if (!is_dir($directory) || !is_writable($directory)) {
                throw new UpgradeException('升级所需目录不可写: ' . basename($directory));
            }
        }
        $free = @disk_free_space($rootPath);
        if ($free !== false && $free < max(104857600, (int)$zipSize * 8)) {
            throw new UpgradeException('磁盘剩余空间不足以安全备份和升级');
        }
        return [
            'compatible' => true,
            'php' => ['current' => PHP_VERSION, 'minimum' => $manifest['php_min'], 'ok' => true],
            'crmeb' => ['required' => PluginInfo::CRMEB_EDITION, 'ok' => true],
            'schema' => ['current' => PluginInfo::CONFIG_SCHEMA_VERSION, 'target' => $manifest['schema_version'], 'ok' => true],
            'disk' => ['ok' => true],
        ];
    }

    private function extractPayload(\ZipArchive $zip, array $entries, array $files, $extractDir)
    {
        foreach ($files as $path => $checksum) {
            $target = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new UpgradeException('无法创建升级暂存子目录');
            }
            $source = $zip->getStream($path);
            $destination = @fopen($target, 'xb');
            if (!is_resource($source) || !is_resource($destination)) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($destination)) {
                    fclose($destination);
                }
                throw new UpgradeException('无法解压已签名文件: ' . self::safeLabel($path));
            }
            $copied = stream_copy_to_stream($source, $destination);
            fclose($source);
            fclose($destination);
            if ($copied === false || $copied !== (int)$entries[$path]['size']) {
                throw new UpgradeException('升级文件解压不完整: ' . self::safeLabel($path));
            }
            @chmod($target, substr($path, -4) === '.php' ? 0644 : 0640);
        }
    }

    private static function safeLabel($value)
    {
        return substr(preg_replace('/[^\x20-\x7E]/', '?', (string)$value), 0, 180);
    }
}
