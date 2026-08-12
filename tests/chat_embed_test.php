<?php

require_once dirname(__DIR__) . '/src/HubUrl.php';
require_once dirname(__DIR__) . '/src/ChatEmbed.php';

use app\bailing\ChatEmbed;

$cases = array(
    'trial full embed' => array(
        '<script src="https://trial.bailinghub.com/tenant/tenant_123/widget.js" data-entry="pub_12345678" async></script>',
        '',
        array('hub_url' => 'https://trial.bailinghub.com/tenant/tenant_123', 'entry_key' => 'pub_12345678'),
    ),
    'full embed is source of truth over stale advanced hub' => array(
        '<script src="https://hub.example.com/widget.js" data-entry="pub_12345678"></script>',
        'ftp://stale.invalid',
        array('hub_url' => 'https://hub.example.com', 'entry_key' => 'pub_12345678'),
    ),
    'self hosted attributes in any order' => array(
        "<script async data-entry='pub_store-1' src='https://hub.example.com/bailing/widget.js?version=1'></script>",
        '',
        array('hub_url' => 'https://hub.example.com/bailing', 'entry_key' => 'pub_store-1'),
    ),
    'html escaped embed' => array(
        '&lt;script src=&quot;https://hub.example.com/widget.js&quot; data-entry=&quot;pub_demo1234&quot; async&gt;&lt;/script&gt;',
        '',
        array('hub_url' => 'https://hub.example.com', 'entry_key' => 'pub_demo1234'),
    ),
    'surrounding unrelated script is ignored' => array(
        '<script src="https://cdn.example.com/app.js"></script>'
        . '<script src="https://hub.example.com/widget.js" data-entry="pub_demo1234"></script>',
        '',
        array('hub_url' => 'https://hub.example.com', 'entry_key' => 'pub_demo1234'),
    ),
    'bare key with existing hub' => array(
        'pub_12345678',
        'https://hub.example.com/console/chat-entries',
        array('hub_url' => 'https://hub.example.com', 'entry_key' => 'pub_12345678'),
    ),
);

$invalid = array(
    'bare key cannot guess hub' => array('pub_12345678', ''),
    'missing entry' => array('<script src="https://hub.example.com/widget.js"></script>', ''),
    'non widget script' => array('<script src="https://hub.example.com/app.js" data-entry="pub_12345678"></script>', ''),
    'uppercase entry rejected like widget runtime' => array('<script src="https://hub.example.com/widget.js" data-entry="PUB_12345678"></script>', ''),
    'trial root embed remains ambiguous' => array('<script src="https://trial.bailinghub.com/widget.js" data-entry="pub_12345678"></script>', ''),
    'multiple distinct entries are ambiguous' => array(
        '<script src="https://hub.example.com/widget.js" data-entry="pub_12345678"></script>'
        . '<script src="https://hub.example.com/widget.js" data-entry="pub_87654321"></script>',
        '',
    ),
    'temporary ticket must never be persisted' => array(
        '<script src="https://hub.example.com/widget.js" data-entry="pub_12345678" data-ticket="temporary-ticket"></script>',
        '',
    ),
    'empty ticket attribute is also rejected' => array(
        '<script src="https://hub.example.com/widget.js" data-entry="pub_12345678" data-ticket></script>',
        '',
    ),
);

$failed = 0;
foreach ($cases as $name => $case) {
    $actual = ChatEmbed::parse($case[0], $case[1]);
    if ($actual !== $case[2]) {
        fwrite(STDERR, 'FAIL ' . $name . ': expected ' . json_encode($case[2]) . ', got ' . json_encode($actual) . "\n");
        $failed++;
    }
}
foreach ($invalid as $name => $case) {
    try {
        ChatEmbed::parse($case[0], $case[1]);
        fwrite(STDERR, "FAIL {$name}: expected InvalidArgumentException\n");
        $failed++;
    } catch (InvalidArgumentException $e) {
        // expected
    }
}

$serviceSource = (string)file_get_contents(dirname(__DIR__) . '/src/BailingService.php');
if (substr_count($serviceSource, "where('menu_name', 'bailing_embed_code')->update") < 2) {
    fwrite(STDERR, "FAIL server must clear raw embed on both success and rejection\n");
    $failed++;
}

// 网页安装器必须保持单文件可上传，验证它自带的解析器与插件类契约一致。
$_POST = array();
$_SERVER['HTTP_HOST'] = 'installer.test';
ob_start();
require dirname(__DIR__) . '/webinstaller/bailinghub-setup.php';
ob_end_clean();
foreach ($cases as $name => $case) {
    $actual = parseChatEmbedForInstaller($case[0], $case[1]);
    if ($actual !== $case[2]) {
        fwrite(STDERR, 'FAIL installer ' . $name . ': expected ' . json_encode($case[2]) . ', got ' . json_encode($actual) . "\n");
        $failed++;
    }
}
foreach ($invalid as $name => $case) {
    try {
        parseChatEmbedForInstaller($case[0], $case[1]);
        fwrite(STDERR, "FAIL installer {$name}: expected InvalidArgumentException\n");
        $failed++;
    } catch (InvalidArgumentException $e) {
        // expected
    }
}

if ($failed > 0) {
    exit(1);
}

echo 'Chat embed parsing and persistence: ' . (2 * (count($cases) + count($invalid)) + 1) . " checks passed\n";
