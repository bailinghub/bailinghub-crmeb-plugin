<?php

$root = dirname(__DIR__);
foreach (array(
    '/src/HubUrl.php',
    '/src/ChatEmbed.php',
    '/src/settings/SettingsException.php',
    '/src/settings/SettingsInput.php',
    '/src/settings/SecretStore.php',
    '/src/settings/SettingsRepository.php',
) as $file) {
    require_once $root . $file;
}

use app\bailing\settings\SettingsException;
use app\bailing\settings\SettingsInput;
use app\bailing\settings\SettingsRepository;

$failed = 0;
$checks = 0;

function settingsCheck($condition, $message)
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        $failed++;
    }
}

function settingsThrows($payload, $message, $forbiddenEcho = '')
{
    try {
        SettingsInput::normalize($payload);
        settingsCheck(false, $message);
    } catch (SettingsException $e) {
        settingsCheck($forbiddenEcho === '' || strpos($e->getMessage(), $forbiddenEcho) === false, $message);
    }
}

$embed = '<script src="https://trial.bailinghub.com/tenant/tenant_123/widget.js" data-entry="pub_12345678"></script>';
$normalized = SettingsInput::normalize(array(
    'nonce' => str_repeat('a', 48),
    'embed_code' => $embed,
    'access_token' => '',
    'sign_secret' => '',
));
settingsCheck($normalized['chat'] === array(
    'hub_url' => 'https://trial.bailinghub.com/tenant/tenant_123',
    'entry_key' => 'pub_12345678',
), 'full embed normalizes to hub and entry');
settingsCheck($normalized['access_token'] === null && $normalized['sign_secret'] === null, 'empty secrets preserve existing values');

$normalized = SettingsInput::normalize(array(
    'hub_url' => 'hub.example.com/console/settings',
    'entry_key' => 'pub_store-1',
    'access_token' => '1234567890abcdef',
));
settingsCheck($normalized['chat'] === array(
    'hub_url' => 'https://hub.example.com',
    'entry_key' => 'pub_store-1',
), 'advanced hub and entry are normalized as a pair');
settingsCheck($normalized['access_token'] === '1234567890abcdef' && $normalized['sign_secret'] === null, 'token can be replaced independently');

$normalized = SettingsInput::normalize(array('sign_secret' => 'abcdef0123456789'));
settingsCheck($normalized['chat'] === null && $normalized['access_token'] === null
    && $normalized['sign_secret'] === 'abcdef0123456789', 'tool-source-only secret update is supported');

settingsThrows(array(), 'reject an empty no-op save');
settingsThrows(array('access_token' => '', 'sign_secret' => ''), 'reject an all-empty no-op save');
settingsThrows(array('hub_url' => 'https://hub.example.com'), 'advanced chat fields must be paired');
settingsThrows(array('entry_key' => 'pub_12345678'), 'entry alone cannot replace chat settings');
settingsThrows(array('embed_code' => $embed, 'hub_url' => 'https://hub.example.com', 'entry_key' => 'pub_other'), 'embed and advanced mode are mutually exclusive');
settingsThrows(array('embed_code' => 'pub_12345678'), 'embed mode requires a complete script tag');
settingsThrows(array('access_token' => 'too-short'), 'short access token is rejected');
settingsThrows(array('sign_secret' => array('not', 'a', 'string')), 'non-string settings are rejected');
settingsThrows(array('source_type' => 'commercial', 'sign_secret' => 'abcdef0123456789'), 'deployment source is not persisted as configuration');
$secretMarker = 'abcdef0123456789 hidden marker';
settingsThrows(array('sign_secret' => $secretMarker), 'validation errors never echo submitted secrets', $secretMarker);

$tokenMarker = 'TOP_SECRET_ACCESS_TOKEN_MARKER';
$signMarker = 'TOP_SECRET_SIGNING_MARKER';
$summary = SettingsRepository::summarize(array(
    SettingsRepository::HUB_URL => 'https://hub.example.com',
    SettingsRepository::CHAT_ENTRY => 'pub_12345678',
    SettingsRepository::ACCESS_TOKEN => $tokenMarker,
    SettingsRepository::SIGN_SECRET => $signMarker,
));
settingsCheck(array_keys($summary) === array(
    'hub_url',
    'entry_key',
    'access_token_configured',
    'sign_secret_configured',
), 'status repository exposes only the four allowed configuration facts');
settingsCheck($summary['access_token_configured'] === true && $summary['sign_secret_configured'] === true, 'status reports configured flags');
$encodedSummary = json_encode($summary);
settingsCheck(strpos($encodedSummary, $tokenMarker) === false && strpos($encodedSummary, $signMarker) === false, 'status never returns secret plaintext');

$routeSource = (string)file_get_contents($root . '/src/route/route.php');
$settingsController = (string)file_get_contents($root . '/src/controller/BailingSettingsController.php');
$assetController = (string)file_get_contents($root . '/src/controller/AdminAssetController.php');
$repositorySource = (string)file_get_contents($root . '/src/settings/SettingsRepository.php');
$secretStoreSource = (string)file_get_contents($root . '/src/settings/SecretStore.php');
$serviceSource = (string)file_get_contents($root . '/src/BailingService.php');
$bailingControllerSource = (string)file_get_contents($root . '/src/controller/BailingController.php');
$configSource = (string)file_get_contents($root . '/config/bailing.php');

$sourceContracts = array(
    'authenticated status route exists' => strpos($routeSource, "Route::get('settings/status', 'BailingSettingsController/status')") !== false,
    'protected save route exists' => strpos($routeSource, "Route::post('settings/save', 'BailingSettingsController/save')") !== false,
    'public same-origin admin bundle route exists' => strpos($routeSource, "Route::get('admin-bundle', 'AdminAssetController/javascript')") !== false,
    'admin bundle route avoids static extension interception' => strpos($routeSource, "Route::get('admin.js'") === false,
    'settings status uses CRMEB login parser' => strpos($settingsController, 'AdminAuthServices::class') !== false,
    'settings save requires super administrator' => strpos($settingsController, '$this->requireSuperAdmin($admin)') !== false,
    'settings save requires same origin' => strpos($settingsController, 'OriginGuard::isSameOrigin') !== false,
    'settings save consumes a scoped one-time nonce' => strpos($settingsController, "const NONCE_SCOPE = 'settings_save'") !== false
        && strpos($settingsController, '$nonces->consume') !== false,
    'settings save requires JSON' => strpos($settingsController, 'application/json') !== false,
    'settings responses disable caching' => strpos($settingsController, 'no-store, no-cache, must-revalidate') !== false,
    'settings writes use a transaction' => strpos($repositorySource, 'Db::transaction') !== false,
    'settings writes clear CRMEB config caches' => strpos($repositorySource, "CacheService::delete('system_config_' . \$menuName)") !== false,
    'settings repository uses private secret store' => strpos($repositorySource, '$this->secretStore->update') !== false
        && strpos($settingsController, 'new SecretStore($root)') !== false,
    'admin bundle delegates to package static JS' => strpos($assetController, 'BailingService::adminBundleJs()') !== false,
    'admin bundle is JavaScript and no-store' => strpos($assetController, 'application/javascript; charset=utf-8') !== false
        && strpos($assetController, 'no-store, no-cache, must-revalidate') !== false,
    'generic access token row is never registered' => !preg_match("/'bailing_access_token'\\s*=>\\s*\\[/", $serviceSource),
    'generic signing secret row is never registered' => !preg_match("/'bailing_sign_secret'\\s*=>\\s*\\[/", $serviceSource),
    'boot deletes generic secret rows without migration' => strpos($serviceSource, "array('bailing_access_token', 'bailing_sign_secret')") !== false
        && strpos($serviceSource, "where('menu_name', \$menuName)->delete()") !== false
        && strpos($serviceSource, 'CacheService::delete($cacheKey)') !== false
        && strpos($serviceSource, 'CacheService::has($cacheKey)') !== false
        && strpos($serviceSource, '阻止当前请求继续进入 generic 配置 API') !== false,
    'runtime controller reads only private secret store' => strpos($bailingControllerSource, 'new SecretStore()') !== false
        && strpos($bailingControllerSource, "sys_config('bailing_access_token'") === false
        && strpos($bailingControllerSource, "sys_config('bailing_sign_secret'") === false,
    'config file contains no secret lookup or value' => strpos($configSource, 'bailing_access_token') === false
        && strpos($configSource, 'bailing_sign_secret') === false
        && !preg_match("/'(?:access_token|sign_secret)'\\s*=>/", $configSource),
    'private store uses flock and atomic same-directory rename' => strpos($secretStoreSource, 'flock($handle') !== false
        && strpos($secretStoreSource, "DIRECTORY_SEPARATOR . '.secrets-'") !== false
        && strpos($secretStoreSource, 'rename($temp, $this->file)') !== false,
    'private store requires restrictive modes' => strpos($secretStoreSource, 'chmod($this->directory, 0700)') !== false
        && strpos($secretStoreSource, 'umask(0077)') !== false
        && strpos($secretStoreSource, "& 0777) === 0600") !== false,
    'private store bounds persisted JSON reads' => strpos($secretStoreSource, 'const MAX_FILE_BYTES = 8192') !== false
        && strpos($secretStoreSource, 'stream_get_contents($handle, self::MAX_FILE_BYTES + 1)') !== false,
    'legacy route row is cleaned' => strpos($serviceSource, "where('menu_name', 'bailing_route')->delete()") !== false,
    'legacy route is no longer registered' => !preg_match("/'bailing_route'\\s*=>\\s*\\[/", $serviceSource),
    'legacy route is no longer read from config' => strpos($configSource, "sys_config('bailing_route'") === false,
);
foreach ($sourceContracts as $name => $condition) {
    settingsCheck($condition, $name);
}

if ($failed > 0) {
    exit(1);
}

echo 'Settings security: ' . $checks . " checks passed\n";
