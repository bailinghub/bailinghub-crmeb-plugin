<?php

require_once dirname(__DIR__) . '/src/settings/SecretStore.php';

use app\bailing\settings\SecretStore;

$failed = 0;
$checks = 0;
$tempRoot = sys_get_temp_dir() . '/bailing-secret-store-' . bin2hex(random_bytes(6));

function secretStoreCheck($condition, $message)
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
        $failed++;
    }
}

function secretStoreThrows($callback, $message)
{
    try {
        $callback();
        secretStoreCheck(false, $message);
    } catch (Throwable $e) {
        secretStoreCheck(true, $message);
    }
}

function secretStoreRemove($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

try {
    $root = $tempRoot . '/normal';
    mkdir($root . '/runtime', 0755, true);
    $store = new SecretStore($root);
    $empty = $store->status();
    secretStoreCheck($empty === array(
        'access_token_configured' => false,
        'sign_secret_configured' => false,
    ), 'new private store reports only empty configured flags');

    $tokenA = 'private-token-value-123456';
    $signA = 'private-sign-value-123456';
    $status = $store->update(array(
        SecretStore::ACCESS_TOKEN => $tokenA,
        SecretStore::SIGN_SECRET => $signA,
    ));
    secretStoreCheck($status['access_token_configured'] && $status['sign_secret_configured'], 'save returns actual configured flags');
    secretStoreCheck(strpos(json_encode($status), $tokenA) === false
        && strpos(json_encode($status), $signA) === false, 'status never contains secret values');

    $directory = SecretStore::directoryPath($root);
    $file = $directory . '/secrets.json';
    $lock = $directory . '/.lock';
    clearstatcache(true, $directory);
    clearstatcache(true, $file);
    clearstatcache(true, $lock);
    secretStoreCheck((fileperms($directory) & 0777) === 0700, 'private store directory mode is 0700');
    secretStoreCheck((fileperms($file) & 0777) === 0600, 'private store file mode is 0600');
    secretStoreCheck((fileperms($lock) & 0777) === 0600, 'private store lock mode is 0600');
    secretStoreCheck(glob($directory . '/.secrets-*.tmp') === array(), 'atomic save leaves no temporary file');

    $beforeHash = hash_file('sha256', $file);
    $store->update(array(
        SecretStore::ACCESS_TOKEN => null,
        SecretStore::SIGN_SECRET => null,
    ));
    secretStoreCheck(hash_equals($beforeHash, hash_file('sha256', $file)), 'null values preserve existing secrets without rewriting');

    secretStoreThrows(function () use ($store) {
        $store->update(array(SecretStore::ACCESS_TOKEN => 'rollback-token-value-123456'), function () {
            throw new RuntimeException('simulated database failure');
        });
    }, 'failed companion transaction rejects the save');
    secretStoreCheck($store->get(SecretStore::ACCESS_TOKEN) === $tokenA, 'failed companion transaction atomically restores old secret');

    // 两个独立 PHP worker 同时更新不同字段，最终不得丢失其中一个写入。
    $worker = $tempRoot . '/worker.php';
    $storeSource = dirname(__DIR__) . '/src/settings/SecretStore.php';
    file_put_contents($worker, '<?php require_once ' . var_export($storeSource, true) . '; '
        . '$store = new \\app\\bailing\\settings\\SecretStore($argv[1]); '
        . '$store->update(array($argv[2] => $argv[3]));');
    $commands = array(
        array(SecretStore::ACCESS_TOKEN, 'parallel-token-value-123456'),
        array(SecretStore::SIGN_SECRET, 'parallel-sign-value-123456'),
    );
    $processes = array();
    foreach ($commands as $command) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' '
            . escapeshellarg($root) . ' ' . escapeshellarg($command[0]) . ' ' . escapeshellarg($command[1]);
        $pipes = array();
        $process = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $processes[] = array($process, $pipes);
    }
    $workersOk = true;
    foreach ($processes as $processInfo) {
        list($process, $pipes) = $processInfo;
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $workersOk = false;
        }
    }
    secretStoreCheck($workersOk, 'parallel worker writes complete');
    secretStoreCheck($store->get(SecretStore::ACCESS_TOKEN) === 'parallel-token-value-123456'
        && $store->get(SecretStore::SIGN_SECRET) === 'parallel-sign-value-123456', 'flock prevents lost updates across workers');

    // 私密文件和专用目录均拒绝符号链接。
    $linkRoot = $tempRoot . '/file-link';
    mkdir($linkRoot . '/runtime/bailinghub-secrets', 0700, true);
    $outside = $tempRoot . '/outside-secret.json';
    file_put_contents($outside, '{"schema_version":2}');
    symlink($outside, $linkRoot . '/runtime/bailinghub-secrets/secrets.json');
    $linkStore = new SecretStore($linkRoot);
    secretStoreThrows(function () use ($linkStore) {
        $linkStore->status();
    }, 'secret file symlink is rejected');

    $lockLinkRoot = $tempRoot . '/lock-link';
    mkdir($lockLinkRoot . '/runtime/bailinghub-secrets', 0700, true);
    symlink($outside, $lockLinkRoot . '/runtime/bailinghub-secrets/.lock');
    $lockLinkStore = new SecretStore($lockLinkRoot);
    secretStoreThrows(function () use ($lockLinkStore) {
        $lockLinkStore->status();
    }, 'private store lock symlink is rejected');

    $directoryLinkRoot = $tempRoot . '/directory-link';
    mkdir($directoryLinkRoot . '/runtime', 0755, true);
    $outsideDirectory = $tempRoot . '/outside-directory';
    mkdir($outsideDirectory, 0700, true);
    symlink($outsideDirectory, $directoryLinkRoot . '/runtime/bailinghub-secrets');
    $directoryLinkStore = new SecretStore($directoryLinkRoot);
    secretStoreThrows(function () use ($directoryLinkStore) {
        $directoryLinkStore->status();
    }, 'private store directory symlink is rejected');

    $nonFileRoot = $tempRoot . '/non-file';
    mkdir($nonFileRoot . '/runtime/bailinghub-secrets/secrets.json', 0700, true);
    $nonFileStore = new SecretStore($nonFileRoot);
    secretStoreThrows(function () use ($nonFileStore) {
        $nonFileStore->status();
    }, 'non-regular secret path is rejected');

    $largeRoot = $tempRoot . '/large-file';
    mkdir($largeRoot . '/runtime/bailinghub-secrets', 0700, true);
    file_put_contents($largeRoot . '/runtime/bailinghub-secrets/secrets.json', str_repeat('x', SecretStore::MAX_FILE_BYTES + 1));
    chmod($largeRoot . '/runtime/bailinghub-secrets/secrets.json', 0600);
    $largeStore = new SecretStore($largeRoot);
    secretStoreThrows(function () use ($largeStore) {
        $largeStore->status();
    }, 'oversized secret file is rejected before unbounded reading');

    // 卸载只删除插件专用私密目录，保留 runtime 中任何相邻业务文件。
    $uninstallRoot = $tempRoot . '/uninstall';
    $packageDirectory = $uninstallRoot . '/vendor/crmeb/bailinghub';
    mkdir($packageDirectory . '/scripts', 0755, true);
    mkdir($uninstallRoot . '/app/bailing', 0755, true);
    mkdir($uninstallRoot . '/runtime/bailinghub-secrets', 0700, true);
    file_put_contents($uninstallRoot . '/runtime/bailinghub-secrets/secrets.json', '{"schema_version":2}');
    $uninstallOutside = $tempRoot . '/uninstall-outside';
    mkdir($uninstallOutside, 0700, true);
    file_put_contents($uninstallOutside . '/keep', 'keep');
    symlink($uninstallOutside, $uninstallRoot . '/runtime/bailinghub-secrets/linked-directory');
    file_put_contents($uninstallRoot . '/runtime/keep-business-file', 'keep');
    copy(dirname(__DIR__) . '/scripts/uninstall.php', $packageDirectory . '/scripts/uninstall.php');
    $output = array();
    $exitCode = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($packageDirectory . '/scripts/uninstall.php') . ' 2>&1', $output, $exitCode);
    secretStoreCheck($exitCode === 0, 'uninstaller completes in a minimal CRMEB tree');
    secretStoreCheck(!file_exists($uninstallRoot . '/runtime/bailinghub-secrets'), 'uninstaller removes the private store');
    secretStoreCheck(is_file($uninstallRoot . '/runtime/keep-business-file'), 'uninstaller preserves adjacent runtime business files');
    secretStoreCheck(is_file($uninstallOutside . '/keep'), 'uninstaller unlinks nested directory symlinks without touching their targets');
} finally {
    secretStoreRemove($tempRoot);
}

if ($failed > 0) {
    exit(1);
}
echo 'Private secret store: ' . $checks . " checks passed\n";
