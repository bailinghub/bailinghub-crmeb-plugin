<?php

require_once dirname(__DIR__) . '/src/connect/Verify.php';
require_once dirname(__DIR__) . '/src/connect/SpecServer.php';

use Bailing\Connect\SpecServer;

$secret = 'test-only-provider-secret';
$path = '/bailing/tools.json';
$timestamp = (string) time();
$bodyHash = hash('sha256', '');
$base = $timestamp . '.GET.' . $path . '.' . $bodyHash;
$signature = 'sha256=' . hash_hmac('sha256', $base . '..', $secret);
$spec = array('openapi' => '3.0.3', 'info' => array('title' => 'test', 'version' => '1.0.0'), 'paths' => array());
$failed = 0;

$cases = array(
    'signed request is accepted' => array(
        array('x-bailing-timestamp' => $timestamp, 'x-bailing-signature' => $signature),
        200,
    ),
    'unsigned request is rejected' => array(array(), 401),
    'invalid signature is rejected' => array(
        array('x-bailing-timestamp' => $timestamp, 'x-bailing-signature' => 'sha256=' . str_repeat('0', 64)),
        401,
    ),
);

foreach ($cases as $name => $case) {
    list($status) = SpecServer::handle($spec, $secret, 'GET', $path, $case[0]);
    if ($status !== $case[1]) {
        fwrite(STDERR, "FAIL {$name}: expected {$case[1]}, got {$status}\n");
        $failed++;
    }
}

$protectedHeaders = SpecServer::responseHeaders($secret);
if (!isset($protectedHeaders['Cache-Control']) || $protectedHeaders['Cache-Control'] !== 'private, no-store') {
    fwrite(STDERR, "FAIL protected response must disable caching\n");
    $failed++;
}
$publicHeaders = SpecServer::responseHeaders(null);
if (isset($publicHeaders['Cache-Control'])) {
    fwrite(STDERR, "FAIL public response must not pretend to be protected\n");
    $failed++;
}

list($publicStatus) = SpecServer::handlePublic($spec, 'GET', $path);
if ($publicStatus !== 200) {
    fwrite(STDERR, "FAIL explicit public helper: expected 200, got {$publicStatus}\n");
    $failed++;
}

if ($failed > 0) {
    exit(1);
}

echo "Spec access policy: 6 checks passed\n";
