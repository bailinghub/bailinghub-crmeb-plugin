<?php

$root = dirname(__DIR__);
$verifier = $root . '/scripts/verify-release-artifacts.php';
$failed = 0;
$checks = 0;

function releaseVerifierCheck($condition, $message)
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        $failed++;
    }
}

function releasePayload($root)
{
    $payload = array();
    foreach (array(
        'CHANGELOG.md',
        'LICENSE',
        'README.md',
        'composer.json',
        'plugin.json',
        'scripts/build-upgrade-package.php',
        'scripts/build-web-install-package.php',
        'scripts/install.php',
        'scripts/uninstall.php',
    ) as $relative) {
        $payload[$relative] = (string)file_get_contents($root . '/' . $relative);
    }
    foreach (array('config', 'src') as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getBasename() === '.DS_Store') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $payload[$relative] = (string)file_get_contents($file->getPathname());
        }
    }
    ksort($payload, SORT_STRING);
    return $payload;
}

function buildReleasePluginFixture($path, array $payload)
{
    $files = array();
    foreach ($payload as $relative => $bytes) {
        $files[$relative] = hash('sha256', $bytes);
    }
    ksort($files, SORT_STRING);
    $checksums = json_encode(array('algorithm' => 'sha256', 'files' => $files), JSON_UNESCAPED_SLASHES);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create plugin fixture');
    }
    foreach ($payload as $relative => $bytes) {
        $zip->addFromString($relative, $bytes);
    }
    $zip->addFromString('checksums.json', $checksums);
    $zip->addFromString('plugin.sig', "test-only-signature\n");
    $zip->close();
}

function buildReleaseWebFixture($path, $pluginPath, $setupPath)
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create web fixture');
    }
    $zip->addFile($pluginPath, 'crmeb-bailinghub.zip');
    $zip->addFile($setupPath, 'public/bailinghub-setup.php');
    $zip->close();
}

function runReleaseVerifier($verifier, $plugin, $web)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($verifier)
        . ' ' . escapeshellarg($plugin) . ' ' . escapeshellarg($web) . ' 2>&1';
    $output = array();
    $status = 0;
    exec($command, $output, $status);
    return array($status, implode("\n", $output));
}

function removeReleaseFixture($root)
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

releaseVerifierCheck(class_exists('ZipArchive'), 'ZipArchive is available for artifact verifier tests');
releaseVerifierCheck(is_file($verifier), 'release verifier exists');

$payload = releasePayload($root);
releaseVerifierCheck(isset($payload['scripts/install.php']), 'install script belongs to installation payload');
releaseVerifierCheck(isset($payload['scripts/uninstall.php']), 'uninstall script belongs to installation payload');
releaseVerifierCheck(!isset($payload['scripts/check-release-artifacts.sh']), 'repository shell verifier stays outside installation payload');
releaseVerifierCheck(!isset($payload['scripts/verify-release-artifacts.php']), 'repository PHP verifier stays outside installation payload');
releaseVerifierCheck(count(array_filter(array_keys($payload), function ($path) {
    return strpos($path, 'tests/') === 0 || strpos($path, '.github/') === 0 || strpos($path, 'webinstaller/') === 0;
})) === 0, 'tests, CI and web installer source stay outside standalone plugin payload');

$fixtureRoot = sys_get_temp_dir() . '/bailinghub-release-verifier-' . bin2hex(random_bytes(6));
mkdir($fixtureRoot, 0755, true);
$plugin = $fixtureRoot . '/plugin.zip';
$web = $fixtureRoot . '/web.zip';
buildReleasePluginFixture($plugin, $payload);
buildReleaseWebFixture($web, $plugin, $root . '/webinstaller/bailinghub-setup.php');
list($status, $output) = runReleaseVerifier($verifier, $plugin, $web);
releaseVerifierCheck($status === 0, 'repository-only files do not invalidate installation artifacts: ' . $output);

$drifted = $payload;
$drifted['scripts/install.php'] .= "\n// deliberate test drift\n";
$driftPlugin = $fixtureRoot . '/plugin-drift.zip';
$driftWeb = $fixtureRoot . '/web-drift.zip';
buildReleasePluginFixture($driftPlugin, $drifted);
buildReleaseWebFixture($driftWeb, $driftPlugin, $root . '/webinstaller/bailinghub-setup.php');
list($status, $output) = runReleaseVerifier($verifier, $driftPlugin, $driftWeb);
releaseVerifierCheck($status === 1, 'byte drift in installation payload fails verification');
releaseVerifierCheck(strpos($output, 'differs byte-for-byte from current source: scripts/install.php') !== false,
    'byte drift failure identifies the changed installation file');

removeReleaseFixture($fixtureRoot);

if ($failed > 0) {
    exit(1);
}

echo "Release artifact verifier: {$checks} checks passed\n";
