<?php

require_once dirname(__DIR__) . '/src/PluginInfo.php';
require_once dirname(__DIR__) . '/src/BailingSpec.php';
require_once dirname(__DIR__) . '/src/settings/SecretStore.php';

use app\bailing\BailingSpec;
use app\bailing\PluginInfo;
use app\bailing\settings\SecretStore;

$root = dirname(__DIR__);
$failed = 0;
$checks = 0;

function packageAssert($condition, $message)
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
        $failed++;
    }
}

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true);
$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
packageAssert(is_array($manifest), 'plugin.json must be valid JSON');
packageAssert(is_array($composer), 'composer.json must be valid JSON');
packageAssert(($manifest['name'] ?? '') === PluginInfo::PACKAGE_NAME, 'package name must match PluginInfo');
packageAssert(($manifest['plugin_version'] ?? '') === PluginInfo::PLUGIN_VERSION, 'plugin version must match PluginInfo');
packageAssert(($manifest['spec_version'] ?? '') === PluginInfo::TOOL_SPEC_VERSION, 'manifest spec version must match PluginInfo');
packageAssert((int)($manifest['schema_version'] ?? 0) === PluginInfo::CONFIG_SCHEMA_VERSION, 'schema version must match PluginInfo');
packageAssert(BailingSpec::SPEC_VERSION === PluginInfo::TOOL_SPEC_VERSION, 'BailingSpec must match PluginInfo tool version');
packageAssert(SecretStore::SCHEMA_VERSION === PluginInfo::CONFIG_SCHEMA_VERSION, 'private store schema must match PluginInfo schema');
packageAssert(($composer['extra']['bailinghub-plugin']['plugin-version'] ?? '') === PluginInfo::PLUGIN_VERSION, 'Composer plugin metadata must match');
packageAssert(($composer['extra']['bailinghub-plugin']['tool-spec-version'] ?? '') === PluginInfo::TOOL_SPEC_VERSION, 'Composer tool metadata must match');
packageAssert((int)($composer['extra']['bailinghub-plugin']['schema-version'] ?? 0) === PluginInfo::CONFIG_SCHEMA_VERSION, 'Composer schema metadata must match');
packageAssert(($composer['authors'][0]['name'] ?? '') === '百灵中枢', 'public Composer author must use the product identity');

$readme = (string)file_get_contents($root . '/README.md');
foreach (['任意 CRMEB 商城一键接入', '有数据的站点可直接装', '37 个开箱工具', '现有站点数据零风险', '装过和没装过一样', 'WorkBuddy AI'] as $forbiddenPublicClaim) {
    packageAssert(strpos($readme, $forbiddenPublicClaim) === false, 'README must not contain overbroad or internal wording: ' . $forbiddenPublicClaim);
}
packageAssert(strpos($readme, 'CRMEB-KY v6') !== false, 'README must identify the verified CRMEB product line');
packageAssert(strpos($readme, '不代表 CRMEB 官方出品、合作或背书') !== false, 'README must state the independent adapter boundary');
packageAssert(strpos($readme, 'https://www.bailinghub.com/') !== false, 'README must link to the public BailingHub website');
packageAssert(strpos($readme, '尚未发布到 Packagist') !== false, 'README must disclose the current Composer distribution boundary');
packageAssert(strpos($readme, "\ncomposer require crmeb/bailinghub\n") === false, 'README must not present unavailable Packagist installation as a public command');
packageAssert(strpos($readme, 'Composer 不会执行依赖包自己的') !== false, 'README must describe dependency script semantics correctly');
packageAssert(strpos($readme, 'composer config repositories.bailinghub composer') !== false, 'README controlled Composer flow must configure its repository explicitly');
packageAssert(strpos($readme, '--repository=') === false, 'README must not use unsupported composer require options');
packageAssert(strpos($readme, 'php vendor/crmeb/bailinghub/scripts/install.php') !== false, 'README controlled Composer flow must explicitly run the installer');
packageAssert(is_file($root . '/LICENSE'), 'source tree must include the declared Apache-2.0 license text');
packageAssert(strpos((string)file_get_contents($root . '/LICENSE'), 'Apache License') === 0, 'LICENSE must contain the Apache-2.0 text');

$requiredRuntimeFiles = [
    'src/controller/AdminAssetController.php',
    'src/controller/BailingSettingsController.php',
    'src/settings/SettingsException.php',
    'src/settings/SettingsInput.php',
    'src/settings/SettingsRepository.php',
    'src/settings/SecretStore.php',
];
foreach ($requiredRuntimeFiles as $requiredRuntimeFile) {
    packageAssert(is_file($root . '/' . $requiredRuntimeFile), 'source tree must contain ' . $requiredRuntimeFile);
}
$routeSource = (string)file_get_contents($root . '/src/route/route.php');
foreach (['admin-bundle', 'settings/status', 'settings/save'] as $requiredRoute) {
    packageAssert(strpos($routeSource, $requiredRoute) !== false, 'source route must contain ' . $requiredRoute);
}

$publicKeyPath = $root . '/src/upgrade/release-public.pem';
$publicKey = openssl_pkey_get_public((string)file_get_contents($publicKeyPath));
$details = $publicKey ? openssl_pkey_get_details($publicKey) : false;
packageAssert($publicKey !== false, 'release public key must parse');
packageAssert(is_array($details) && (int)($details['bits'] ?? 0) >= 3072, 'release public key must be at least 3072 bits');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$privateKeyFound = false;
foreach ($iterator as $file) {
    if ($file->isFile() && !$file->isLink()
        && preg_match('/-----BEGIN (?:RSA |EC |ENCRYPTED |OPENSSH )?PRIVATE KEY-----/', (string)file_get_contents($file->getPathname()))) {
        $privateKeyFound = true;
        break;
    }
}
packageAssert(!$privateKeyFound, 'release private key must not be present in source tree');

$packagePath = getenv('BAILINGHUB_PLUGIN_PACKAGE');
if (is_string($packagePath) && $packagePath !== '') {
    packageAssert(class_exists('ZipArchive'), 'ZipArchive is required for artifact verification');
    $zip = new ZipArchive();
    packageAssert($zip->open($packagePath, ZipArchive::RDONLY) === true, 'built package must open');
    if ($zip->numFiles > 0) {
        $actual = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
            $actual[] = (string)$stat['name'];
        }
        $pluginRaw = (string)$zip->getFromName('plugin.json');
        $checksumsRaw = (string)$zip->getFromName('checksums.json');
        $signatureRaw = (string)$zip->getFromName('plugin.sig');
        $document = json_decode($checksumsRaw, true);
        $files = is_array($document) && isset($document['files']) && is_array($document['files']) ? $document['files'] : [];
        packageAssert(($document['algorithm'] ?? '') === 'sha256' && $files, 'checksums.json must be an sha256 file map');
        $expected = array_keys($files);
        $expected[] = 'checksums.json';
        $expected[] = 'plugin.sig';
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        packageAssert($actual === $expected, 'ZIP entries must exactly match signed file set');
        foreach ($files as $path => $checksum) {
            $bytes = $zip->getFromName($path);
            packageAssert($bytes !== false && hash('sha256', $bytes) === $checksum, 'artifact checksum must match: ' . $path);
        }
        $signature = base64_decode(trim($signatureRaw), true);
        packageAssert($signature !== false && openssl_verify($pluginRaw . "\n" . $checksumsRaw, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1, 'artifact signature must verify');
        packageAssert(!in_array('webinstaller/bailinghub-setup.php', $actual, true), 'upgrade package must exclude web installer');
        packageAssert(count(array_filter($actual, function ($path) {
            return strpos($path, 'tests/') === 0;
        })) === 0, 'upgrade package must exclude tests');
        foreach ($requiredRuntimeFiles as $requiredRuntimeFile) {
            packageAssert(in_array($requiredRuntimeFile, $actual, true), 'upgrade package must contain ' . $requiredRuntimeFile);
        }
        packageAssert(in_array('LICENSE', $actual, true), 'upgrade package must distribute the declared license text');
        $artifactRoute = (string)$zip->getFromName('src/route/route.php');
        foreach (['admin-bundle', 'settings/status', 'settings/save'] as $requiredRoute) {
            packageAssert(strpos($artifactRoute, $requiredRoute) !== false, 'artifact route must contain ' . $requiredRoute);
        }
    }
    $zip->close();
}

if (is_resource($publicKey)) {
    openssl_free_key($publicKey);
}

if ($failed > 0) {
    exit(1);
}
echo 'Package contract: ' . $checks . " checks passed\n";
