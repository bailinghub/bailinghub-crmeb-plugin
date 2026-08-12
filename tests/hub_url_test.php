<?php

require_once dirname(__DIR__) . '/src/HubUrl.php';

use app\bailing\HubUrl;

$cases = array(
    'standalone root stays valid' => array(
        'https://hub.example.com/',
        'https://hub.example.com',
    ),
    'standalone host gets https' => array(
        'hub.example.com',
        'https://hub.example.com',
    ),
    'standalone console URL becomes root' => array(
        'https://hub.example.com/console/chat-entries?tab=embed#latest',
        'https://hub.example.com',
    ),
    'reverse proxy widget URL preserves mount' => array(
        'https://example.com/bailing/widget.js?cache=1',
        'https://example.com/bailing',
    ),
    'trial tenant base stays mounted' => array(
        'https://trial.bailinghub.com/tenant/tenant_123/',
        'https://trial.bailinghub.com/tenant/tenant_123',
    ),
    'trial console URL becomes tenant base' => array(
        'https://trial.bailinghub.com/tenant/tenant_123/console/chat-entries#embed',
        'https://trial.bailinghub.com/tenant/tenant_123',
    ),
    'trial widget URL becomes tenant base' => array(
        'https://trial.bailinghub.com/tenant/tenant_123/widget.js',
        'https://trial.bailinghub.com/tenant/tenant_123',
    ),
);

$failed = 0;
foreach ($cases as $name => $case) {
    $actual = HubUrl::normalize($case[0]);
    if ($actual !== $case[1]) {
        fwrite(STDERR, "FAIL {$name}: expected {$case[1]}, got {$actual}\n");
        $failed++;
    }
}

$invalidCases = array(
    'trial root is ambiguous' => 'https://trial.bailinghub.com',
    'trial root widget is still ambiguous' => 'https://trial.bailinghub.com/widget.js',
    'trial unrelated path is invalid' => 'https://trial.bailinghub.com/console',
    'trial encoded traversal is invalid' => 'https://trial.bailinghub.com/tenant/%2e%2e',
    'trial invalid tenant id is rejected' => 'https://trial.bailinghub.com/tenant/tenant%2Fother',
    'unsupported scheme is invalid' => 'ftp://hub.example.com',
    'credentials are invalid' => 'https://user:pass@hub.example.com',
);
foreach ($invalidCases as $name => $input) {
    try {
        HubUrl::normalize($input);
        fwrite(STDERR, "FAIL {$name}: expected InvalidArgumentException\n");
        $failed++;
    } catch (InvalidArgumentException $e) {
        // expected
    }
}

// 网页安装器必须保持单文件可用，因此自带同等逻辑；同时验证两处行为没有漂移。
$_POST = array();
$_SERVER['HTTP_HOST'] = 'installer.test';
ob_start();
require dirname(__DIR__) . '/webinstaller/bailinghub-setup.php';
ob_end_clean();
foreach ($cases as $name => $case) {
    $actual = normalizeHubUrlForInstaller($case[0]);
    if ($actual !== $case[1]) {
        fwrite(STDERR, "FAIL installer {$name}: expected {$case[1]}, got {$actual}\n");
        $failed++;
    }
}
foreach ($invalidCases as $name => $input) {
    try {
        normalizeHubUrlForInstaller($input);
        fwrite(STDERR, "FAIL installer {$name}: expected InvalidArgumentException\n");
        $failed++;
    } catch (InvalidArgumentException $e) {
        // expected
    }
}

if ($failed > 0) {
    exit(1);
}

echo 'HubUrl normalization: ' . (2 * (count($cases) + count($invalidCases))) . " checks passed\n";
