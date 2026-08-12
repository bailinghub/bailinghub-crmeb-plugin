<?php

$root = dirname(__DIR__);
foreach (array(
    '/src/PluginInfo.php',
    '/src/upgrade/UpgradeException.php',
    '/src/upgrade/OriginGuard.php',
    '/src/upgrade/NonceStore.php',
    '/src/upgrade/FileSystem.php',
    '/src/upgrade/PackageValidator.php',
    '/src/upgrade/PhpLinter.php',
    '/src/upgrade/UpgradeStorage.php',
    '/src/upgrade/UpgradeManager.php',
) as $file) {
    require_once $root . $file;
}

use app\bailing\PluginInfo;
use app\bailing\upgrade\FileSystem;
use app\bailing\upgrade\NonceStore;
use app\bailing\upgrade\OriginGuard;
use app\bailing\upgrade\PackageValidator;
use app\bailing\upgrade\UpgradeException;
use app\bailing\upgrade\UpgradeManager;
use app\bailing\upgrade\UpgradeStorage;

$failed = 0;
$checks = 0;

function upgradeCheck($condition, $message)
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        $failed++;
    }
}

function upgradeThrows($callback, $message)
{
    try {
        $callback();
        upgradeCheck(false, $message);
    } catch (UpgradeException $e) {
        upgradeCheck(true, $message);
    }
}

function upgradeFixtureRoot()
{
    $root = sys_get_temp_dir() . '/bailinghub-upgrade-' . bin2hex(random_bytes(6));
    foreach (array('/app/bailing/route', '/vendor/crmeb/bailinghub/src', '/config', '/runtime') as $dir) {
        mkdir($root . $dir, 0755, true);
    }
    file_put_contents($root . '/.version', "version=CRMEB-KY v6.0.0\nversion_code=600\n");
    file_put_contents($root . '/app/bailing/old.txt', 'old-app');
    file_put_contents($root . '/app/bailing/route/route.php', "<?php // old route\n");
    file_put_contents($root . '/vendor/crmeb/bailinghub/old.txt', 'old-vendor');
    file_put_contents($root . '/vendor/crmeb/bailinghub/src/Old.php', "<?php class OldPlugin {}\n");
    file_put_contents($root . '/config/bailing.php', "<?php return ['secret' => 'preserved'];\n");
    file_put_contents($root . '/vendor/services.php', "<?php return ['app\\\\bailing\\\\BailingService'];\n");
    return $root;
}

function upgradeKeys($directory)
{
    $key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);
    $publicPath = $directory . '/release-public.pem';
    file_put_contents($publicPath, $details['key']);
    return array($private, $publicPath);
}

function upgradePayload($version, $validRoute)
{
    $manifest = array(
        'format_version' => 1,
        'name' => 'crmeb-bailinghub',
        'plugin_version' => $version,
        'spec_version' => '2.3.0',
        'schema_version' => 2,
        'php_min' => '7.1.0',
        'crmeb_edition' => 'CRMEB-KY-v6',
        'bailinghub_min' => '0.2.0',
        'release_notes' => array('test release'),
        'migrations' => array(),
    );
    $route = "<?php\n";
    if ($validRoute) {
        $route .= "// tools.json chat-ticket admin-bundle settings/status settings/save plugin-upgrade/status plugin-upgrade/stage plugin-upgrade/apply\n";
    } else {
        $route .= "// deliberately incomplete route for rollback test\n";
    }
    return array(
        'plugin.json' => json_encode($manifest, JSON_UNESCAPED_SLASHES),
        'composer.json' => json_encode(array(
            'name' => 'crmeb/bailinghub',
            'extra' => array('bailinghub-plugin' => array(
                'plugin-version' => $version,
                'tool-spec-version' => '2.3.0',
                'schema-version' => 2,
            )),
        ), JSON_UNESCAPED_SLASHES),
        'scripts/install.php' => "<?php echo 'install';\n",
        'src/PluginInfo.php' => "<?php namespace app\\bailing; final class PluginInfo { const PLUGIN_VERSION = '" . $version . "'; const TOOL_SPEC_VERSION = '2.3.0'; const CONFIG_SCHEMA_VERSION = 2; }\n",
        'src/BailingSpec.php' => "<?php namespace app\\bailing; final class BailingSpec { const SPEC_VERSION = '2.3.0'; }\n",
        'src/BailingService.php' => "<?php namespace app\\bailing; class BailingService {}\n",
        'src/controller/AdminAssetController.php' => "<?php namespace app\\bailing\\controller; class AdminAssetController {}\n",
        'src/controller/BailingSettingsController.php' => "<?php namespace app\\bailing\\controller; class BailingSettingsController {}\n",
        'src/settings/SettingsException.php' => "<?php namespace app\\bailing\\settings; class SettingsException extends \\RuntimeException {}\n",
        'src/settings/SettingsInput.php' => "<?php namespace app\\bailing\\settings; class SettingsInput {}\n",
        'src/settings/SettingsRepository.php' => "<?php namespace app\\bailing\\settings; class SettingsRepository {}\n",
        'src/settings/SecretStore.php' => "<?php namespace app\\bailing\\settings; class SecretStore {}\n",
        'src/route/route.php' => $route,
    );
}

function upgradeBuildPackage($zipPath, $privateKey, array $payload, $extraFile = null, $badSignature = false)
{
    $files = array();
    foreach ($payload as $path => $content) {
        $files[$path] = hash('sha256', $content);
    }
    ksort($files, SORT_STRING);
    $checksums = json_encode(array('algorithm' => 'sha256', 'files' => $files), JSON_UNESCAPED_SLASHES);
    $signed = $payload['plugin.json'] . "\n" . $checksums;
    openssl_sign($signed, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if ($badSignature) {
        $signature[0] = chr(ord($signature[0]) ^ 1);
    }

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($payload as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->addFromString('checksums.json', $checksums);
    $zip->addFromString('plugin.sig', base64_encode($signature));
    if ($extraFile !== null) {
        $zip->addFromString($extraFile, 'not signed');
    }
    $zip->close();
}

// 版本维度必须分离且为当前约定值。
upgradeCheck(PluginInfo::PLUGIN_VERSION === '2.4.1', 'adapter version is 2.4.1');
upgradeCheck(PluginInfo::TOOL_SPEC_VERSION === '2.3.0', 'tool spec version stays 2.3.0');
upgradeCheck(PluginInfo::CONFIG_SCHEMA_VERSION === 2, 'config schema is 2');

// 路径和版本纯函数。
foreach (array('../evil.php', '/absolute.php', 'src\\evil.php', 'src/../evil.php', 'src//evil.php', 'src/file.php/') as $unsafe) {
    upgradeCheck(!PackageValidator::isSafeEntryName($unsafe), 'reject unsafe zip path ' . $unsafe);
}
upgradeCheck(PackageValidator::isSafeEntryName('src/upgrade/UpgradeManager.php'), 'accept normal plugin path');
upgradeCheck(!PackageValidator::isAllowedPayloadPath('tests/plugin_upgrade_test.php'), 'upgrade artifact excludes tests');
upgradeCheck(!PackageValidator::isAllowedPayloadPath('webinstaller/bailinghub-setup.php'), 'upgrade artifact excludes web installer');
upgradeCheck(!PackageValidator::isAllowedPayloadPath('migrations/arbitrary.php'), 'upgrade artifact excludes undeclared migration roots');
upgradeCheck(!PackageValidator::isAllowedPayloadPath('runtime/bailinghub-secrets/secrets.json'), 'upgrade artifact excludes runtime private secrets');
upgradeCheck(PackageValidator::isStrictUpgradeVersion('2.5.0', '2.4.1'), 'accept strict semver upgrade');
upgradeCheck(!PackageValidator::isStrictUpgradeVersion('2.4.1', '2.4.1'), 'reject replay version');
upgradeCheck(!PackageValidator::isStrictUpgradeVersion('2.4.0', '2.4.1'), 'reject downgrade version');
$managerSource = (string)file_get_contents(dirname(__DIR__) . '/src/upgrade/UpgradeManager.php');
$oldOpcachePos = strpos($managerSource, '$this->invalidatePluginOpcache($paths[\'vendor_target\']);');
$vendorRenamePos = strpos($managerSource, "rename(\$paths['vendor_target'], \$paths['vendor_old'])");
upgradeCheck($oldOpcachePos !== false && $vendorRenamePos !== false && $oldOpcachePos < $vendorRenamePos, 'invalidate old opcache paths before rename');

// 同源判断必须包含端口并拒绝带路径/凭据的 Origin。
upgradeCheck(OriginGuard::isSameOrigin('https://shop.example.com', 'shop.example.com'), 'same HTTPS origin');
upgradeCheck(OriginGuard::isSameOrigin('http://shop.example.com:8080', 'shop.example.com:8080'), 'same explicit port origin');
upgradeCheck(!OriginGuard::isSameOrigin('https://evil.example.com', 'shop.example.com'), 'reject foreign origin');
upgradeCheck(!OriginGuard::isSameOrigin('https://shop.example.com/path', 'shop.example.com'), 'reject origin with path');

// nonce 只允许成功消费一次，错误尝试同样会销毁当前 nonce。
$nonceRoot = sys_get_temp_dir() . '/bailinghub-nonce-' . bin2hex(random_bytes(6));
$nonces = new NonceStore($nonceRoot, 60);
$nonce = $nonces->issue(1, 'stage');
upgradeCheck($nonces->consume(1, 'stage', $nonce), 'consume valid nonce');
upgradeCheck(!$nonces->consume(1, 'stage', $nonce), 'nonce cannot be replayed');
$nonce = $nonces->issue(1, 'stage');
upgradeCheck(!$nonces->consume(1, 'stage', 'wrong'), 'wrong nonce is rejected');
upgradeCheck(!$nonces->consume(1, 'stage', $nonce), 'wrong attempt invalidates nonce');
FileSystem::removeTree($nonceRoot);

// 签名包验证、精确文件集、签名失败。
$fixture = upgradeFixtureRoot();
list($privateKey, $publicKeyPath) = upgradeKeys($fixture);
$validator = new PackageValidator($publicKeyPath);
$validZip = $fixture . '/valid.zip';
upgradeBuildPackage($validZip, $privateKey, upgradePayload('2.5.0', true));
$verified = $validator->validateAndExtract($validZip, $fixture . '/verified', $fixture);
upgradeCheck($verified['candidate']['adapter_version'] === '2.5.0', 'validate signed candidate version');
upgradeCheck(is_file($fixture . '/verified/src/BailingService.php'), 'extract only verified payload');
upgradeCheck(is_file($fixture . '/verified/src/controller/AdminAssetController.php'), 'extract required admin asset controller');
upgradeCheck(is_file($fixture . '/verified/src/settings/SettingsRepository.php'), 'extract required secure settings repository');
upgradeCheck(is_file($fixture . '/verified/src/settings/SecretStore.php'), 'extract required private secret store');

$missingSettingsPayload = upgradePayload('2.5.0', true);
unset($missingSettingsPayload['src/settings/SettingsRepository.php']);
$missingSettingsZip = $fixture . '/missing-settings.zip';
upgradeBuildPackage($missingSettingsZip, $privateKey, $missingSettingsPayload);
upgradeThrows(function () use ($validator, $missingSettingsZip, $fixture) {
    $validator->validateAndExtract($missingSettingsZip, $fixture . '/missing-settings-out', $fixture);
}, 'reject signed package missing secure settings runtime');

$extraZip = $fixture . '/extra.zip';
upgradeBuildPackage($extraZip, $privateKey, upgradePayload('2.5.0', true), 'src/extra.php');
upgradeThrows(function () use ($validator, $extraZip, $fixture) {
    $validator->validateAndExtract($extraZip, $fixture . '/extra-out', $fixture);
}, 'reject unsigned extra zip entry');

$badZip = $fixture . '/bad-signature.zip';
upgradeBuildPackage($badZip, $privateKey, upgradePayload('2.5.0', true), null, true);
upgradeThrows(function () use ($validator, $badZip, $fixture) {
    $validator->validateAndExtract($badZip, $fixture . '/bad-out', $fixture);
}, 'reject invalid RSA signature');

$mismatchPayload = upgradePayload('2.5.0', true);
$mismatchPayload['src/PluginInfo.php'] = str_replace("PLUGIN_VERSION = '2.5.0'", "PLUGIN_VERSION = '9.9.9'", $mismatchPayload['src/PluginInfo.php']);
$mismatchZip = $fixture . '/mismatch.zip';
upgradeBuildPackage($mismatchZip, $privateKey, $mismatchPayload);
upgradeThrows(function () use ($validator, $mismatchZip, $fixture) {
    $validator->validateAndExtract($mismatchZip, $fixture . '/mismatch-out', $fixture);
}, 'reject signed package with mismatched embedded version');

$composerMismatchPayload = upgradePayload('2.5.0', true);
$composerMismatch = json_decode($composerMismatchPayload['composer.json'], true);
$composerMismatch['extra']['bailinghub-plugin']['tool-spec-version'] = '9.9.9';
$composerMismatchPayload['composer.json'] = json_encode($composerMismatch, JSON_UNESCAPED_SLASHES);
$composerMismatchZip = $fixture . '/composer-mismatch.zip';
upgradeBuildPackage($composerMismatchZip, $privateKey, $composerMismatchPayload);
upgradeThrows(function () use ($validator, $composerMismatchZip, $fixture) {
    $validator->validateAndExtract($composerMismatchZip, $fixture . '/composer-mismatch-out', $fixture);
}, 'reject signed package with mismatched composer plugin metadata');

// 完整 apply：vendor 与 app 同时更新，CRMEB 配置不变，history 成功落盘。
$storage = new UpgradeStorage($fixture . '/runtime');
$manager = new UpgradeManager($fixture, $storage, $validator);
$configHash = hash_file('sha256', $fixture . '/config/bailing.php');
$servicesHash = hash_file('sha256', $fixture . '/vendor/services.php');
$secretDirectory = $fixture . '/runtime/bailinghub-secrets';
mkdir($secretDirectory, 0700, true);
$secretFile = $secretDirectory . '/secrets.json';
file_put_contents($secretFile, '{"schema_version":2,"access_token":"upgrade-preserve-marker"}');
chmod($secretFile, 0600);
$secretHash = hash_file('sha256', $secretFile);
$staged = $manager->stage(file_get_contents($validZip), 7);
$secondStage = $manager->stage(file_get_contents($validZip), 7);
upgradeCheck(is_file($fixture . '/runtime/bailinghub-updates/staged/' . $staged['staged_id'] . '/package.zip'), 'creating another stage keeps unexpired staged package');
$applied = $manager->apply($staged['staged_id'], 7);
upgradeCheck($applied['success'] === true && $applied['current']['adapter_version'] === '2.5.0', 'apply valid staged package');
upgradeCheck(is_file($fixture . '/vendor/crmeb/bailinghub/plugin.json'), 'replace canonical vendor package');
upgradeCheck(is_file($fixture . '/app/bailing/PluginInfo.php'), 'replace runtime app copy');
upgradeCheck(hash_equals($configHash, hash_file('sha256', $fixture . '/config/bailing.php')), 'preserve bailing config');
upgradeCheck(hash_equals($servicesHash, hash_file('sha256', $fixture . '/vendor/services.php')), 'preserve CRMEB services file');
upgradeCheck(hash_equals($secretHash, hash_file('sha256', $secretFile)), 'upgrade preserves private secret store in place');
$secretBackupCopies = glob($fixture . '/runtime/bailinghub-updates/backups/*/bailinghub-secrets');
upgradeCheck($secretBackupCopies === array(), 'upgrade backup never copies private secret store');
$last = $storage->lastHistory();
upgradeCheck($last['status'] === 'success' && $last['rolled_back'] === false, 'record successful upgrade history');

FileSystem::removeTree($fixture);

// 健康检查失败必须回滚两份运行代码并记录 rolled_back=true。
$rollbackRoot = upgradeFixtureRoot();
list($rollbackPrivate, $rollbackPublic) = upgradeKeys($rollbackRoot);
$rollbackValidator = new PackageValidator($rollbackPublic);
$rollbackZip = $rollbackRoot . '/rollback.zip';
upgradeBuildPackage($rollbackZip, $rollbackPrivate, upgradePayload('2.6.0', false));
$rollbackStorage = new UpgradeStorage($rollbackRoot . '/runtime');
$rollbackManager = new UpgradeManager($rollbackRoot, $rollbackStorage, $rollbackValidator);
$rollbackStage = $rollbackManager->stage(file_get_contents($rollbackZip), 9);
upgradeThrows(function () use ($rollbackManager, $rollbackStage) {
    $rollbackManager->apply($rollbackStage['staged_id'], 9);
}, 'health failure rejects apply');
upgradeCheck(file_get_contents($rollbackRoot . '/vendor/crmeb/bailinghub/old.txt') === 'old-vendor', 'rollback canonical vendor package');
upgradeCheck(file_get_contents($rollbackRoot . '/app/bailing/old.txt') === 'old-app', 'rollback runtime app copy');
$last = $rollbackStorage->lastHistory();
upgradeCheck($last['status'] === 'failed' && $last['rolled_back'] === true, 'record rollback in history');
FileSystem::removeTree($rollbackRoot);

// 即使 rename 用的 old 目录丢失，也必须从已核对备份恢复；并发修改的配置不得被旧备份覆盖。
$fallbackRoot = upgradeFixtureRoot();
$fallbackStorage = new UpgradeStorage($fallbackRoot . '/runtime');
$fallbackValidator = new PackageValidator($fallbackRoot . '/unused-public.pem');
$fallbackManager = new UpgradeManager($fallbackRoot, $fallbackStorage, $fallbackValidator);
$prepareMethod = new ReflectionMethod(UpgradeManager::class, 'preparePaths');
$prepareMethod->setAccessible(true);
$fallbackPaths = $prepareMethod->invoke($fallbackManager, str_repeat('a', 32));
$backupMethod = new ReflectionMethod(UpgradeManager::class, 'createBackup');
$backupMethod->setAccessible(true);
$fallbackBackup = $backupMethod->invoke($fallbackManager, str_repeat('a', 32), $fallbackPaths);
rename($fallbackPaths['vendor_target'], $fallbackPaths['vendor_old']);
rename($fallbackPaths['app_target'], $fallbackPaths['app_old']);
mkdir($fallbackPaths['vendor_target'], 0755, true);
mkdir($fallbackPaths['app_target'], 0755, true);
file_put_contents($fallbackPaths['vendor_target'] . '/new.txt', 'broken-new-vendor');
file_put_contents($fallbackPaths['app_target'] . '/new.txt', 'broken-new-app');
FileSystem::removeTree($fallbackPaths['vendor_old']);
FileSystem::removeTree($fallbackPaths['app_old']);
file_put_contents($fallbackPaths['config'], "<?php return ['concurrent' => 'config'];\n");
file_put_contents($fallbackPaths['services'], "<?php return ['concurrent' => 'services'];\n");
$rollbackMethod = new ReflectionMethod(UpgradeManager::class, 'rollbackSwitch');
$rollbackMethod->setAccessible(true);
$fallbackResult = $rollbackMethod->invoke($fallbackManager, $fallbackPaths, array(
    'vendor_old_moved' => true,
    'vendor_new_installed' => true,
    'app_old_moved' => true,
    'app_new_installed' => true,
), $fallbackBackup);
upgradeCheck($fallbackResult === true, 'backup fallback restores both plugin directories');
upgradeCheck(file_get_contents($fallbackPaths['vendor_target'] . '/old.txt') === 'old-vendor', 'backup fallback restores vendor contents');
upgradeCheck(file_get_contents($fallbackPaths['app_target'] . '/old.txt') === 'old-app', 'backup fallback restores app contents');
upgradeCheck(strpos(file_get_contents($fallbackPaths['config']), 'concurrent') !== false, 'rollback does not overwrite concurrent config change');
upgradeCheck(strpos(file_get_contents($fallbackPaths['services']), 'concurrent') !== false, 'rollback does not overwrite concurrent services change');
FileSystem::removeTree($fallbackRoot);

if ($failed > 0) {
    exit(1);
}

echo "Plugin upgrade security: {$checks} checks passed\n";
