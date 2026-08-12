<?php

$installerSource = dirname(__DIR__) . '/webinstaller/bailinghub-setup.php';
$source = (string)file_get_contents($installerSource);
$uninstallSource = (string)file_get_contents(dirname(__DIR__) . '/scripts/uninstall.php');
$failed = 0;
$checks = 0;

function check($condition, $message)
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        $failed++;
    }
}

function makeFixture($installerSource, $key)
{
    $root = sys_get_temp_dir() . '/bailinghub-webinstaller-' . bin2hex(random_bytes(6));
    foreach (array(
        '/public',
        '/app',
        '/config',
        '/runtime',
        '/vendor/crmeb/bailinghub/src/controller',
        '/vendor/crmeb/bailinghub/config',
    ) as $dir) {
        mkdir($root . $dir, 0755, true);
    }
    copy($installerSource, $root . '/public/bailinghub-setup.php');
    file_put_contents($root . '/runtime/bailinghub_install.key', $key . "\n");
    chmod($root . '/runtime/bailinghub_install.key', 0600);
    file_put_contents($root . '/vendor/services.php', "<?php\nreturn array (\n);\n");
    file_put_contents($root . '/vendor/crmeb/bailinghub/src/BailingService.php', "<?php\nnamespace app\\bailing; class BailingService {}\n");
    file_put_contents($root . '/vendor/crmeb/bailinghub/src/controller/BailingController.php', "<?php\nnamespace app\\bailing\\controller; class BailingController {}\n");
    file_put_contents($root . '/vendor/crmeb/bailinghub/config/bailing.php', "<?php\nreturn array();\n");
    return $root;
}

function writeRunner($root, array $post)
{
    $runner = $root . '/run-installer.php';
    $code = "<?php\n"
        . '$_POST = ' . var_export($post, true) . ";\n"
        . '$_SERVER[\'HTTP_HOST\'] = \'installer.test\';' . "\n"
        . "ob_start();\nrequire __DIR__ . '/public/bailinghub-setup.php';\nob_end_clean();\n";
    file_put_contents($runner, $code);
    return $runner;
}

function inventory($root)
{
    $out = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($root));
        if ($item->isDir()) {
            $out[$relative] = 'dir';
        } else {
            $out[$relative] = 'file:' . hash_file('sha256', $item->getPathname()) . ':' . ($item->getPerms() & 0777);
        }
    }
    ksort($out);
    return $out;
}

function runFixture($runner)
{
    $output = array();
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $code);
    return array($code, implode("\n", $output));
}

function removeFixture($root)
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

check(strpos($source, 'hash_equals($serverKey, $submittedKey)') !== false, 'installer must compare the key in constant time');
check(strpos($source, "@unlink(\$installKeyFile)") !== false, 'installer must destroy the one-time key after locking');
check(strpos($source, 'data-ticket(?=\\s|=|>)') !== false, 'installer parser must reject data-ticket even without a value');
check(strpos($uninstallSource, '/runtime/bailinghub_install.key') !== false, 'uninstaller must remove an abandoned one-time key');

$validKey = str_repeat('a1', 32);

// 未创建服务器口令文件时同样不得执行任何写入。
$missingKeyRoot = makeFixture($installerSource, $validKey);
unlink($missingKeyRoot . '/runtime/bailinghub_install.key');
$missingKeyRunner = writeRunner($missingKeyRoot, array(
    'action' => 'install',
    'install_key' => $validKey,
));
$before = inventory($missingKeyRoot);
list($exitCode, $output) = runFixture($missingKeyRunner);
$after = inventory($missingKeyRoot);
check($exitCode === 0, 'missing-key request should return normally');
check($before === $after, 'missing-key request must not change any file or directory');
check(!is_file($missingKeyRoot . '/runtime/bailinghub_install.lock'), 'missing-key request must not create a lock');
removeFixture($missingKeyRoot);

// 错误口令必须在解析 embed、解压、复制和任何配置写入之前失败。
$unauthorizedRoot = makeFixture($installerSource, $validKey);
$unauthorizedRunner = writeRunner($unauthorizedRoot, array(
    'action' => 'install',
    'install_key' => str_repeat('b2', 32),
    'embed_code' => '<script src="https://attacker.example/widget.js" data-entry="pub_attack" data-ticket="secret"></script>',
));
$before = inventory($unauthorizedRoot);
list($exitCode, $output) = runFixture($unauthorizedRunner);
$after = inventory($unauthorizedRoot);
check($exitCode === 0, 'unauthorized request should return normally');
check($before === $after, 'unauthorized request must not change any file or directory');
check(!is_file($unauthorizedRoot . '/runtime/bailinghub_install.lock'), 'unauthorized request must not create a lock');
check(is_file($unauthorizedRoot . '/runtime/bailinghub_install.key'), 'unauthorized request must not consume the key');
removeFixture($unauthorizedRoot);

// 正确口令允许一次安装；成功后 lock 存在而 key 被销毁。
$authorizedRoot = makeFixture($installerSource, $validKey);
$authorizedRunner = writeRunner($authorizedRoot, array(
    'action' => 'install',
    'install_key' => strtoupper($validKey),
    'embed_code' => '<script src="https://hub.example.com/widget.js" data-entry="pub_store1" async></script>',
));
list($exitCode, $output) = runFixture($authorizedRunner);
check($exitCode === 0, 'authorized installation should return normally: ' . $output);
check(is_file($authorizedRoot . '/runtime/bailinghub_install.lock'), 'authorized installation must create the lock');
check(!file_exists($authorizedRoot . '/runtime/bailinghub_install.key'), 'authorized installation must destroy the one-time key');
check(is_file($authorizedRoot . '/app/bailing/BailingService.php'), 'authorized installation must deploy the application code');
$preset = json_decode((string)@file_get_contents($authorizedRoot . '/runtime/bailinghub_preset.json'), true);
check(is_array($preset)
    && isset($preset['hub_url'], $preset['chat_entry'])
    && $preset['hub_url'] === 'https://hub.example.com'
    && $preset['chat_entry'] === 'pub_store1', 'authorized installation must save only normalized embed fields');
removeFixture($authorizedRoot);

// 单文件解析器本身必须拒绝有值、空值和布尔形态的临时票据属性。
$_POST = array();
$_SERVER['HTTP_HOST'] = 'installer.test';
ob_start();
require $installerSource;
ob_end_clean();
foreach (array(
    '<script src="https://hub.example.com/widget.js" data-entry="pub_store1" data-ticket="v1.secret"></script>',
    '<script src="https://hub.example.com/widget.js" data-entry="pub_store1" data-ticket=""></script>',
    '<script src="https://hub.example.com/widget.js" data-entry="pub_store1" data-ticket></script>',
) as $embed) {
    try {
        parseChatEmbedForInstaller($embed);
        check(false, 'installer parser must reject every data-ticket form');
    } catch (InvalidArgumentException $e) {
        check(strpos($e->getMessage(), 'data-ticket') !== false, 'data-ticket rejection must give an actionable message');
    }
}

if ($failed > 0) {
    exit(1);
}

echo "Web installer authorization: {$checks} checks passed\n";
