<?php

namespace think {
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
    $checks = 0;

    function adminUiCheck($condition, $message)
    {
        global $failed, $checks;
        $checks++;
        if (!$condition) {
            fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
            $failed++;
        }
    }

    $loaderMethod = new ReflectionMethod(BailingService::class, 'adminLoaderJs');
    $loaderMethod->setAccessible(true);
    $loader = (string)$loaderMethod->invoke(null);
    $bundleMethod = new ReflectionMethod(BailingService::class, 'adminBundleJs');
    $bundle = (string)$bundleMethod->invoke(null);
    $serviceSource = (string)file_get_contents(dirname(__DIR__) . '/src/BailingService.php');

    adminUiCheck($bundleMethod->isPublic() && $bundleMethod->isStatic(), 'adminBundleJs is public static');
    adminUiCheck(strlen($loader) < 5000, 'custom admin loader stays under 5KB');
    adminUiCheck(strpos($loader, '/bailing/admin-bundle?v=' . \app\bailing\PluginInfo::PLUGIN_VERSION) !== false, 'loader uses same-origin versioned extensionless admin bundle');
    adminUiCheck(strpos($loader, '/bailing/admin.js') === false, 'loader avoids static .js route interception');
    adminUiCheck(strpos($loader, '/bailing/settings/status') === false
        && strpos($loader, '/bailing/chat-ticket') === false
        && strpos($loader, '/bailing/plugin-upgrade/') === false, 'loader contains no settings, chat or upgrade implementation');

    $contracts = array(
        'settings status endpoint' => "apiRequest('/bailing/settings/status'",
        'settings save endpoint' => "apiRequest('/bailing/settings/save'",
        'settings save sends JSON nonce' => 'var payload={nonce:current.nonce}',
        'empty access token starts blank' => "access.control.value='';secret.control.value=''",
        'configured flags are the only secret facts' => 'access_token_configured:truthy(data.access_token_configured)',
        'signing configured flag is consumed' => 'sign_secret_configured:truthy(data.sign_secret_configured)',
        'open-source deployment source' => '我已经部署开源版',
        'self-hosted commercial tenant source' => '我自己部署了商业版，并已进入具体租户',
        'online trial fallback source' => '我还没有以上环境',
        'online trial registration link' => 'https://trial.bailinghub.com/register/',
        'trial is not misrepresented as the commercial product' => '在线体验只是体验环境，不等于商业版产品，也不是唯一接入地址',
        'all sources converge on full embed' => '最后都要从对应租户控制台复制完整聊天入口嵌入代码',
        'default form includes full embed' => '完整聊天入口嵌入代码',
        'default form includes access token' => '接入方 token',
        'default form includes signing secret' => '工具源签名密钥',
        'hub and entry live under advanced settings' => '高级设置：分别填写中枢地址和聊天入口 key',
        'ordinary administrators are read-only' => '普通管理员可查看状态，只有超级管理员可以修改',
        'connection status is itemized' => '当前连接配置',
        'save success summarizes changes' => "'保存成功：'+result.summary.join('、')",
        'tool-source next step is visible' => '下一步：把商城登记为工具源',
        'tool source is signed-required' => '访问策略：签名保护（signed_required）',
        'maintenance contains upgrade host' => "upgradeHost.setAttribute('data-bailing-upgrade-host','1')",
        'maintenance title matches documentation' => "element('summary','维护与升级'",
        'widget uses settings status facts' => "script.src=status.hub_url.replace(/\\/+$/,'')+'/widget.js'",
        'widget obtains an authenticated chat ticket' => "apiRequest('/bailing/chat-ticket'",
        'SPA mounting is idempotent' => "document.querySelector('[data-bailing-settings-card]')",
        'SPA changes are observed' => "new MutationObserver(function()",
        'native generic form hides only after card creation' => 'hideNativeForm(host,card)',
        'failed enhancement preserves fallback form' => '已保留 CRMEB 原生配置表单',
        'maintenance is collapsed by default' => "maintenance=document.createElement('details')",
    );
    foreach ($contracts as $name => $needle) {
        adminUiCheck(strpos($bundle, $needle) !== false, $name);
    }
    adminUiCheck(strpos($serviceSource, '自建商业版或在线体验必须填写具体租户地址。在线体验不等同于商业版') !== false,
        'native fallback explains all three address sources');

    adminUiCheck(strpos($bundle, 'bailing_route') === false && strpos($bundle, '路由 key') === false, 'legacy route never appears in dedicated UI');
    adminUiCheck(strpos($bundle, 'bailing_access_token') === false
        && strpos($bundle, 'bailing_sign_secret') === false, 'bundle never reads generic secret configuration rows');
    adminUiCheck(strpos($bundle, 'TOP_SECRET_ACCESS_TOKEN_MARKER') === false
        && strpos($bundle, 'TOP_SECRET_SIGNING_MARKER') === false, 'bundle contains no configuration secret value');

    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    adminUiCheck($node !== '', 'node is available for JavaScript syntax validation');
    if ($node !== '') {
        foreach (array('loader' => $loader, 'bundle' => $bundle) as $label => $javascript) {
            $tmp = tempnam(sys_get_temp_dir(), 'bailing-admin-ui-');
            file_put_contents($tmp, $javascript);
            $output = array();
            $status = 0;
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $output, $status);
            @unlink($tmp);
            adminUiCheck($status === 0, $label . ' JavaScript syntax');
            if ($status !== 0) {
                fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
            }
        }
    }

    if ($failed > 0) {
        exit(1);
    }
    echo 'Admin bundle UI: ' . $checks . " checks passed\n";
}
