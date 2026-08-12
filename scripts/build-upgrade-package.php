<?php
// +----------------------------------------------------------------------
// | CRMEB 百灵中枢适配器升级包构建器
// | 只从受控 allowlist 取文件，生成 checksums.json 并以独立发布私钥签名。
// +----------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/PluginInfo.php';
$output = $root . '/../crmeb-bailinghub.zip';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--output' && isset($argv[$i + 1])) {
        $output = $argv[++$i];
        continue;
    }
    fwrite(STDERR, "Usage: php scripts/build-upgrade-package.php [--output /absolute/path.zip]\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    failBuild('PHP ZipArchive extension is required');
}
if (!function_exists('openssl_sign')) {
    failBuild('PHP OpenSSL extension is required');
}

$keyPath = getenv('BAILINGHUB_PLUGIN_SIGNING_KEY');
if (!is_string($keyPath) || trim($keyPath) === '') {
    failBuild('BAILINGHUB_PLUGIN_SIGNING_KEY must point to the release private key');
}
$keyPath = trim($keyPath);
if (!is_file($keyPath) || is_link($keyPath) || !is_readable($keyPath)) {
    failBuild('release private key must be a readable regular file and not a symlink');
}
if (DIRECTORY_SEPARATOR === '/' && ((fileperms($keyPath) & 0777) !== 0600)) {
    failBuild('release private key permissions must be exactly 0600');
}

$manifestPath = $root . '/plugin.json';
$manifestBytes = readRequiredFile($manifestPath);
$manifest = json_decode($manifestBytes, true);
if (!is_array($manifest) || ($manifest['name'] ?? '') !== 'crmeb-bailinghub') {
    failBuild('plugin.json is invalid');
}
$composer = json_decode(readRequiredFile($root . '/composer.json'), true);
if (!is_array($composer)) {
    failBuild('composer.json is invalid');
}
$expected = \app\bailing\PluginInfo::current();
$composerPlugin = isset($composer['extra']['bailinghub-plugin']) && is_array($composer['extra']['bailinghub-plugin'])
    ? $composer['extra']['bailinghub-plugin'] : [];
if (($manifest['plugin_version'] ?? '') !== $expected['adapter_version']
    || ($manifest['spec_version'] ?? '') !== $expected['tool_spec_version']
    || (int)($manifest['schema_version'] ?? 0) !== (int)$expected['config_schema_version']
    || ($composerPlugin['plugin-version'] ?? '') !== $expected['adapter_version']
    || ($composerPlugin['tool-spec-version'] ?? '') !== $expected['tool_spec_version']
    || (int)($composerPlugin['schema-version'] ?? 0) !== (int)$expected['config_schema_version']) {
    failBuild('plugin.json, composer.json and PluginInfo versions must match');
}

$payload = [];
foreach (['CHANGELOG.md', 'LICENSE', 'README.md', 'composer.json', 'plugin.json'] as $relative) {
    addPayloadFile($payload, $root, $relative);
}
foreach (['config', 'scripts', 'src'] as $directory) {
    addPayloadDirectory($payload, $root, $directory);
}
ksort($payload, SORT_STRING);

$checksums = [];
foreach ($payload as $relative => $absolute) {
    $checksums[$relative] = hash_file('sha256', $absolute);
}
$checksumBytes = json_encode([
    'algorithm' => 'sha256',
    'files' => $checksums,
], JSON_UNESCAPED_SLASHES) . "\n";
if ($checksumBytes === false) {
    failBuild('failed to encode checksums.json');
}

$privateKeyBytes = readRequiredFile($keyPath);
$privateKey = openssl_pkey_get_private($privateKeyBytes);
if ($privateKey === false) {
    failBuild('release private key cannot be parsed');
}
$signature = '';
$signedBytes = $manifestBytes . "\n" . $checksumBytes;
if (!openssl_sign($signedBytes, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    failBuild('failed to sign package');
}
if (is_resource($privateKey)) {
    openssl_free_key($privateKey);
}
$signatureBytes = base64_encode($signature) . "\n";

$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
    failBuild('cannot create output directory');
}
$temporary = $output . '.tmp-' . getmypid();
@unlink($temporary);
$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    failBuild('cannot create ZIP archive');
}
foreach ($payload as $relative => $absolute) {
    if (!$zip->addFile($absolute, $relative)) {
        $zip->close();
        @unlink($temporary);
        failBuild('failed to add ' . $relative);
    }
}
$zip->addFromString('checksums.json', $checksumBytes);
$zip->addFromString('plugin.sig', $signatureBytes);
if (!$zip->close()) {
    @unlink($temporary);
    failBuild('failed to finalize ZIP archive');
}
if (!@rename($temporary, $output)) {
    @unlink($temporary);
    failBuild('failed to move completed ZIP archive');
}

echo json_encode([
    'ok' => true,
    'output' => realpath($output),
    'plugin_version' => $manifest['plugin_version'],
    'spec_version' => $manifest['spec_version'],
    'files' => count($payload),
    'sha256' => hash_file('sha256', $output),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

function addPayloadDirectory(array &$payload, $root, $relativeDirectory)
{
    $absoluteDirectory = $root . '/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) || is_link($absoluteDirectory)) {
        failBuild('payload directory is missing or is a symlink: ' . $relativeDirectory);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            failBuild('payload may contain regular files only: ' . $file->getPathname());
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (shouldExclude($relative)) {
            continue;
        }
        addPayloadFile($payload, $root, $relative);
    }
}

function addPayloadFile(array &$payload, $root, $relative)
{
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || $relative[0] === '/' || strpos($relative, '../') !== false || shouldExclude($relative)) {
        failBuild('unsafe payload path: ' . $relative);
    }
    $absolute = $root . '/' . $relative;
    if (!is_file($absolute) || is_link($absolute)) {
        failBuild('payload file is missing or is a symlink: ' . $relative);
    }
    rejectPrivateKeyMaterial($relative, $absolute);
    $payload[$relative] = $absolute;
}

function shouldExclude($relative)
{
    $base = basename($relative);
    if ($base === '.DS_Store' || $base === 'checksums.json' || $base === 'plugin.sig') {
        return true;
    }
    if (strpos($relative, 'tests/') === 0 || strpos($relative, 'webinstaller/') === 0) {
        return true;
    }
    if (preg_match('/(?:private|secret|signing)[-_]key/i', $relative)) {
        failBuild('private-key-like file is forbidden in payload: ' . $relative);
    }
    return false;
}

function readRequiredFile($path)
{
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        failBuild('cannot read required file: ' . $path);
    }
    return $bytes;
}

function rejectPrivateKeyMaterial($relative, $absolute)
{
    $content = @file_get_contents($absolute);
    if ($content === false) {
        failBuild('cannot inspect payload file: ' . $relative);
    }
    foreach (['', 'RSA ', 'EC ', 'ENCRYPTED ', 'OPENSSH '] as $kind) {
        $marker = '-----BEGIN ' . $kind . 'PRIVATE KEY-----';
        if (strpos($content, $marker) !== false) {
            failBuild('private key material is forbidden in payload: ' . $relative);
        }
    }
}

function failBuild($message)
{
    fwrite(STDERR, '[bailinghub-package] ' . $message . PHP_EOL);
    exit(1);
}
