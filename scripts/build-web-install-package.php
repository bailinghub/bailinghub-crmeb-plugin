<?php
// +----------------------------------------------------------------------
// | 组合“网页首次安装整包”：已签名插件 ZIP + public/bailinghub-setup.php
// +----------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI only.\n");
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/PluginInfo.php';
$inner = $root . '/../crmeb-bailinghub.zip';
$output = $root . '/../crmeb-bailinghub-web-install.zip';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--inner' && isset($argv[$i + 1])) {
        $inner = $argv[++$i];
    } elseif ($argv[$i] === '--output' && isset($argv[$i + 1])) {
        $output = $argv[++$i];
    } else {
        fwrite(STDERR, "Usage: php scripts/build-web-install-package.php [--inner plugin.zip] [--output web-install.zip]\n");
        exit(1);
    }
}

if (!is_file($inner) || !is_readable($inner)) {
    failWebPackage('signed inner plugin ZIP does not exist');
}
$setup = $root . '/webinstaller/bailinghub-setup.php';
if (!is_file($setup)) {
    failWebPackage('web installer source is missing');
}

$probe = new ZipArchive();
if ($probe->open($inner, ZipArchive::RDONLY) !== true) {
    failWebPackage('inner plugin ZIP cannot be opened');
}
$pluginRaw = $probe->getFromName('plugin.json');
$checksumsRaw = $probe->getFromName('checksums.json');
$signatureRaw = $probe->getFromName('plugin.sig');
$probe->close();
$manifest = is_string($pluginRaw) ? json_decode($pluginRaw, true) : null;
if (!is_array($manifest) || ($manifest['plugin_version'] ?? '') !== \app\bailing\PluginInfo::PLUGIN_VERSION) {
    failWebPackage('inner plugin version does not match PluginInfo');
}
$signature = is_string($signatureRaw) ? base64_decode(trim($signatureRaw), true) : false;
$publicKey = openssl_pkey_get_public((string)file_get_contents($root . '/src/upgrade/release-public.pem'));
if (!is_string($checksumsRaw) || $signature === false || $publicKey === false
    || openssl_verify($pluginRaw . "\n" . $checksumsRaw, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
    failWebPackage('inner plugin signature is invalid');
}
if (is_resource($publicKey)) {
    openssl_free_key($publicKey);
}

$temporary = $output . '.tmp-' . getmypid();
@unlink($temporary);
$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
    || !$zip->addFile($inner, 'crmeb-bailinghub.zip')
    || !$zip->addFile($setup, 'public/bailinghub-setup.php')
    || !$zip->close()) {
    @unlink($temporary);
    failWebPackage('failed to create web install ZIP');
}
if (!@rename($temporary, $output)) {
    @unlink($temporary);
    failWebPackage('failed to publish web install ZIP');
}

echo json_encode([
    'ok' => true,
    'output' => realpath($output),
    'plugin_version' => $manifest['plugin_version'],
    'sha256' => hash_file('sha256', $output),
    'inner_sha256' => hash_file('sha256', $inner),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

function failWebPackage($message)
{
    fwrite(STDERR, '[bailinghub-web-package] ' . $message . PHP_EOL);
    exit(1);
}
