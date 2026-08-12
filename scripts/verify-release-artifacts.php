<?php
// +----------------------------------------------------------------------
// | CRMEB BailingHub release artifact verifier
// | Cross-checks the signed plugin ZIP, web installer ZIP and source tree.
// +----------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI only.\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    failVerification('PHP ZipArchive extension is required');
}

$root = dirname(__DIR__);
$pluginPackage = isset($argv[1]) ? $argv[1] : '';
$webPackage = isset($argv[2]) ? $argv[2] : '';
$sha256File = isset($argv[3]) ? $argv[3] : '';

if ($pluginPackage === '' || $webPackage === '' || isset($argv[4])) {
    fwrite(STDERR, "Usage: php scripts/verify-release-artifacts.php PLUGIN.zip WEB-INSTALL.zip [SHA256SUMS.txt]\n");
    exit(1);
}

foreach (array($pluginPackage, $webPackage) as $package) {
    if (!is_file($package) || is_link($package) || !is_readable($package)) {
        failVerification('artifact must be a readable regular file and not a symlink: ' . $package);
    }
}
if ($sha256File !== '' && (!is_file($sha256File) || is_link($sha256File) || !is_readable($sha256File))) {
    failVerification('SHA256SUMS file must be a readable regular file and not a symlink: ' . $sha256File);
}

$expectedPayload = sourcePayload($root);
$pluginZip = openZip($pluginPackage, 'plugin package');
$pluginEntries = zipEntries($pluginZip);
$checksumsRaw = $pluginZip->getFromName('checksums.json');
$checksums = is_string($checksumsRaw) ? json_decode($checksumsRaw, true) : null;
if (!is_array($checksums)
    || !isset($checksums['algorithm'], $checksums['files'])
    || $checksums['algorithm'] !== 'sha256'
    || !is_array($checksums['files'])) {
    $pluginZip->close();
    failVerification('plugin checksums.json must contain a sha256 files map');
}

$signedFiles = array_keys($checksums['files']);
sort($signedFiles, SORT_STRING);
$expectedFiles = array_keys($expectedPayload);
sort($expectedFiles, SORT_STRING);
if ($signedFiles !== $expectedFiles) {
    $pluginZip->close();
    failVerification(describeSetMismatch(
        'signed payload does not exactly match the current source release set',
        $expectedFiles,
        $signedFiles
    ));
}

$expectedPluginEntries = $expectedFiles;
$expectedPluginEntries[] = 'checksums.json';
$expectedPluginEntries[] = 'plugin.sig';
sort($expectedPluginEntries, SORT_STRING);
if ($pluginEntries !== $expectedPluginEntries) {
    $pluginZip->close();
    failVerification(describeSetMismatch(
        'plugin ZIP entries do not exactly match the signed source release set',
        $expectedPluginEntries,
        $pluginEntries
    ));
}

foreach ($expectedPayload as $relative => $absolute) {
    $sourceBytes = file_get_contents($absolute);
    $artifactBytes = $pluginZip->getFromName($relative);
    if (!is_string($sourceBytes) || !is_string($artifactBytes) || !hash_equals($sourceBytes, $artifactBytes)) {
        $pluginZip->close();
        failVerification('plugin artifact differs byte-for-byte from current source: ' . $relative);
    }
    $declaredHash = isset($checksums['files'][$relative]) ? strtolower((string)$checksums['files'][$relative]) : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $declaredHash)
        || !hash_equals(hash('sha256', $sourceBytes), $declaredHash)) {
        $pluginZip->close();
        failVerification('signed checksum does not match current source: ' . $relative);
    }
}
$pluginZip->close();

$pluginBytes = file_get_contents($pluginPackage);
if (!is_string($pluginBytes) || $pluginBytes === '') {
    failVerification('cannot read plugin artifact bytes');
}

$webZip = openZip($webPackage, 'web installer package');
$webEntries = zipEntries($webZip);
$expectedWebEntries = array('crmeb-bailinghub.zip', 'public/bailinghub-setup.php');
sort($expectedWebEntries, SORT_STRING);
if ($webEntries !== $expectedWebEntries) {
    $webZip->close();
    failVerification(describeSetMismatch(
        'web installer ZIP must contain only the signed plugin ZIP and setup page',
        $expectedWebEntries,
        $webEntries
    ));
}

$innerBytes = $webZip->getFromName('crmeb-bailinghub.zip');
if (!is_string($innerBytes) || !hash_equals($pluginBytes, $innerBytes)) {
    $webZip->close();
    failVerification('web installer inner plugin ZIP is not byte-for-byte identical to the standalone plugin ZIP');
}

$setupSource = file_get_contents($root . '/webinstaller/bailinghub-setup.php');
$setupArtifact = $webZip->getFromName('public/bailinghub-setup.php');
if (!is_string($setupSource) || !is_string($setupArtifact) || !hash_equals($setupSource, $setupArtifact)) {
    $webZip->close();
    failVerification('web installer setup page differs byte-for-byte from current source');
}
$webZip->close();

$artifactHashes = array(
    basename($pluginPackage) => hash_file('sha256', $pluginPackage),
    basename($webPackage) => hash_file('sha256', $webPackage),
);
ksort($artifactHashes, SORT_STRING);
foreach ($artifactHashes as $name => $hash) {
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
        failVerification('failed to calculate SHA256 for ' . $name);
    }
}

if ($sha256File !== '') {
    $declared = parseSha256File($sha256File);
    foreach ($artifactHashes as $name => $hash) {
        if (!isset($declared[$name]) || !hash_equals($hash, $declared[$name])) {
            failVerification('SHA256SUMS mismatch or missing entry: ' . $name);
        }
    }
    if (array_keys($declared) !== array_keys($artifactHashes)) {
        failVerification('SHA256SUMS must contain exactly the two release artifacts');
    }
}

echo "Release artifact cross-checks passed\n";
foreach ($artifactHashes as $name => $hash) {
    echo $hash . '  ' . $name . PHP_EOL;
}

function sourcePayload($root)
{
    $payload = array();
    foreach (array('CHANGELOG.md', 'LICENSE', 'README.md', 'composer.json', 'plugin.json') as $relative) {
        addSourceFile($payload, $root, $relative);
    }
    foreach (array(
        'scripts/build-upgrade-package.php',
        'scripts/build-web-install-package.php',
        'scripts/install.php',
        'scripts/uninstall.php',
    ) as $relative) {
        addSourceFile($payload, $root, $relative);
    }
    foreach (array('config', 'src') as $directory) {
        $absoluteDirectory = $root . '/' . $directory;
        if (!is_dir($absoluteDirectory) || is_link($absoluteDirectory)) {
            failVerification('source payload directory is missing or is a symlink: ' . $directory);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                failVerification('source payload may contain regular files only: ' . $file->getPathname());
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (basename($relative) === '.DS_Store'
                || basename($relative) === 'checksums.json'
                || basename($relative) === 'plugin.sig') {
                continue;
            }
            addSourceFile($payload, $root, $relative);
        }
    }
    ksort($payload, SORT_STRING);
    return $payload;
}

function addSourceFile(array &$payload, $root, $relative)
{
    $absolute = $root . '/' . $relative;
    if (!is_file($absolute) || is_link($absolute) || !is_readable($absolute)) {
        failVerification('source payload file must be readable and not a symlink: ' . $relative);
    }
    $payload[$relative] = $absolute;
}

function openZip($path, $label)
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        failVerification($label . ' cannot be opened: ' . $path);
    }
    return $zip;
}

function zipEntries(ZipArchive $zip)
{
    $entries = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
        if (!is_array($stat) || !isset($stat['name'])) {
            failVerification('cannot inspect ZIP entry at index ' . $i);
        }
        $entries[] = (string)$stat['name'];
    }
    sort($entries, SORT_STRING);
    return $entries;
}

function describeSetMismatch($message, array $expected, array $actual)
{
    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));
    return $message
        . ($missing ? '; missing: ' . implode(', ', $missing) : '')
        . ($extra ? '; extra: ' . implode(', ', $extra) : '');
}

function parseSha256File($path)
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        failVerification('cannot read SHA256SUMS file');
    }
    $result = array();
    foreach ($lines as $line) {
        if (!preg_match('/^([a-fA-F0-9]{64})[ \t]+[* ]?(.+)$/', trim($line), $matches)) {
            failVerification('invalid SHA256SUMS line: ' . $line);
        }
        $name = basename(trim($matches[2]));
        if ($name === '' || isset($result[$name])) {
            failVerification('duplicate or empty SHA256SUMS filename: ' . $name);
        }
        $result[$name] = strtolower($matches[1]);
    }
    ksort($result, SORT_STRING);
    return $result;
}

function failVerification($message)
{
    fwrite(STDERR, '[bailinghub-release] ' . $message . PHP_EOL);
    exit(1);
}
