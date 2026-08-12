<?php

$sourceRoot = dirname(__DIR__);
$tempRoot = sys_get_temp_dir() . '/bailing-install-test-' . bin2hex(random_bytes(6));
$failed = 0;
$checks = 0;

function installAssert($condition, $message)
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
        $failed++;
    }
}

function testCopyTree($source, $destination)
{
    @mkdir($destination, 0755, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $target = $destination . '/' . $relative;
        if ($item->isDir()) {
            @mkdir($target, 0755, true);
        } else {
            @mkdir(dirname($target), 0755, true);
            copy($item->getPathname(), $target);
        }
    }
}

function testRemoveTree($path)
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

try {
    $crmRoot = $tempRoot . '/success';
    $package = $crmRoot . '/vendor/crmeb/bailinghub';
    @mkdir($crmRoot . '/app/bailing', 0755, true);
    @mkdir($crmRoot . '/config', 0755, true);
    @mkdir($crmRoot . '/vendor', 0755, true);
    file_put_contents($crmRoot . '/app/bailing/old.php', '<?php // old');
    file_put_contents($crmRoot . '/config/bailing.php', "<?php return ['access_token' => sys_config('bailing_access_token'), 'sign_secret' => sys_config('bailing_sign_secret')];");
    file_put_contents($crmRoot . '/vendor/services.php', "<?php return array (0 => 'Existing\\Service',);\n");
    @mkdir($package . '/scripts', 0755, true);
    copy($sourceRoot . '/scripts/install.php', $package . '/scripts/install.php');
    testCopyTree($sourceRoot . '/src', $package . '/src');
    testCopyTree($sourceRoot . '/config', $package . '/config');

    $output = [];
    $code = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($package . '/scripts/install.php') . ' 2>&1', $output, $code);
    installAssert($code === 0, 'installer must succeed in a valid CRMEB tree');
    installAssert(strpos(implode("\n", $output), 'PHP-FPM') !== false, 'manual install output must require reloading the web PHP-FPM runtime');
    installAssert(is_file($crmRoot . '/app/bailing/PluginInfo.php'), 'new runtime copy must be installed');
    installAssert(!file_exists($crmRoot . '/app/bailing/old.php'), 'removed old plugin files must not survive');
    $installedConfig = (string)file_get_contents($crmRoot . '/config/bailing.php');
    installAssert(strpos($installedConfig, 'bailing_access_token') === false
        && strpos($installedConfig, 'bailing_sign_secret') === false, 'installer replaces legacy generated secret lookups');
    installAssert(strpos($installedConfig, 'bailing_hub_url') !== false
        && strpos($installedConfig, 'bailing_chat_entry') !== false, 'installer keeps only current non-secret config lookups');
    $services = (string)file_get_contents($crmRoot . '/vendor/services.php');
    installAssert(strpos($services, 'Existing\\Service') !== false, 'existing service registration must remain');
    installAssert(strpos($services, 'BailingService') !== false, 'BailingService must be registered');
    installAssert(count(glob($crmRoot . '/app/.bailing-*')) === 0, 'installer must not leave staging or backup directories');
    installAssert(count(glob($crmRoot . '/config/.bailing-config-*')) === 0, 'installer must not leave a config candidate file');

    $badRoot = $tempRoot . '/failure';
    $badPackage = $badRoot . '/vendor/crmeb/bailinghub';
    @mkdir($badRoot . '/app/bailing', 0755, true);
    @mkdir($badRoot . '/vendor/crmeb/bailinghub/scripts', 0755, true);
    file_put_contents($badRoot . '/app/bailing/keep.php', '<?php // keep');
    copy($sourceRoot . '/scripts/install.php', $badPackage . '/scripts/install.php');
    $badOutput = [];
    $badCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($badPackage . '/scripts/install.php') . ' 2>&1', $badOutput, $badCode);
    installAssert($badCode !== 0, 'installer must fail when signed package src is missing');
    installAssert(is_file($badRoot . '/app/bailing/keep.php'), 'failed install must not delete current runtime copy');
} finally {
    testRemoveTree($tempRoot);
}

if ($failed > 0) {
    exit(1);
}
echo 'Atomic install: ' . $checks . " checks passed\n";
