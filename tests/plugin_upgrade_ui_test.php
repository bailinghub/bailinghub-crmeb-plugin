<?php

namespace think {
    // 独立测试不加载 ThinkPHP，只提供服务类继承所需的最小桩。
    class Service
    {
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/BailingSpec.php';
    require_once dirname(__DIR__) . '/src/PluginInfo.php';
    require_once dirname(__DIR__) . '/src/BailingService.php';

    use app\bailing\BailingService;

    $failed = 0;
    $source = (string)file_get_contents(dirname(__DIR__) . '/src/BailingService.php');
    $method = new ReflectionMethod(BailingService::class, 'pluginUpgradeManagerJs');
    $method->setAccessible(true);
    $js = (string)$method->invoke(null);

    $contracts = array(
        'adapter version comes from the package source of truth' => 'const ADAPTER_VERSION = PluginInfo::PLUGIN_VERSION',
        'config schema version comes from the package source of truth' => 'const CONFIG_SCHEMA_VERSION = PluginInfo::CONFIG_SCHEMA_VERSION',
        'upgrade UI is part of the same-origin admin bundle' => 'self::adminSettingsAndWidgetJs() . "\\n" . self::pluginUpgradeManagerJs()',
        'status is same-origin and authenticated' => "apiRequest('/bailing/plugin-upgrade/status'",
        'stage endpoint exists' => "apiRequest('/bailing/plugin-upgrade/stage'",
        'stage sends raw selected ZIP' => 'body:selectedFile',
        'stage sends one-time nonce header' => "'X-Bailing-Upgrade-Nonce':upgradeNonce",
        'stage sends encoded package name' => "'X-Bailing-Package-Name':encodeURIComponent(selectedFile.name",
        'apply endpoint exists' => "apiRequest('/bailing/plugin-upgrade/apply'",
        'apply body binds staged id and apply nonce' => 'JSON.stringify({staged_id:stagedId,nonce:applyNonce})',
        'all requests use same-origin credentials' => "options.credentials='same-origin'",
        'CRMEB authorization header spelling is preserved' => "'Authori-zation':'Bearer '+token",
        'user must confirm apply' => "window.confirm('确认升级到 '",
        'upgrade card is nested under maintenance details' => "document.querySelector('[data-bailing-upgrade-host]')",
        'upgrade permission is resolved before file selection' => 'setButtonDisabled(chooseButton,true)',
        'server messages are rendered as text' => "operation.textContent='升级失败：'+errorMessage(e)",
        'rollback result is visible' => "'；已自动回滚到升级前版本'",
        'non-super administrators remain read-only' => "只有超级管理员可以执行升级",
        'read-only status does not require a nonce' => "if(canUpgrade&&!upgradeNonce)",
        'read-only accounts cannot stage a package' => "if(!canUpgrade) throw new Error",
        'history displays the backend target version' => 'value.to_version||value.version||value.adapter_version',
        'history displays the backend timestamp' => 'value.timestamp||value.time||value.updated_at||value.finished_at',
        'no automatic remote update copy' => '不会自动检查或下载新版本',
    );

    foreach ($contracts as $name => $needle) {
        $haystack = strpos($needle, 'const ') === 0 || strpos($needle, 'self::adminSettingsAndWidgetJs()') !== false ? $source : $js;
        if (strpos($haystack, $needle) === false) {
            fwrite(STDERR, "FAIL {$name}\n");
            $failed++;
        }
    }

    if (strpos($js, '__BAILING_PLUGIN_VERSIONS__') !== false) {
        fwrite(STDERR, "FAIL embedded version placeholder was not replaced\n");
        $failed++;
    }
    if (strpos($js, '"adapter_version":"' . \app\bailing\PluginInfo::PLUGIN_VERSION . '"') === false
        || strpos($js, '"tool_spec_version":"' . \app\bailing\PluginInfo::TOOL_SPEC_VERSION . '"') === false) {
        fwrite(STDERR, "FAIL embedded adapter/tool versions are missing or conflated\n");
        $failed++;
    }

    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        fwrite(STDERR, "FAIL node is required for injected JavaScript syntax validation\n");
        $failed++;
    } else {
        $tmp = tempnam(sys_get_temp_dir(), 'bailing-upgrade-ui-');
        file_put_contents($tmp, $js);
        $output = array();
        $status = 0;
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $output, $status);
        @unlink($tmp);
        if ($status !== 0) {
            fwrite(STDERR, "FAIL injected JavaScript syntax\n" . implode("\n", $output) . "\n");
            $failed++;
        }
    }

    if ($failed > 0) {
        exit(1);
    }

    echo "Plugin upgrade UI: " . (count($contracts) + 3) . " checks passed\n";
}
