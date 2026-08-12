<?php
// +----------------------------------------------------------------------
// | 百灵中枢插件卸载脚本
// | 手动执行：php vendor/crmeb/bailinghub/scripts/uninstall.php
// | 清理：app/bailing、config、插件私密存储、升级状态、后台配置项/分组、聊天标记块
// +----------------------------------------------------------------------

$rootDir = findRoot(__DIR__);
if (!$rootDir) {
    echo "[bailinghub] 无法定位 CRMEB 项目根目录（未找到同时含 app/ 和 vendor/ 的上级目录）\n";
    exit(1);
}
$targetAppDir = $rootDir . '/app/bailing';

/**
 * 向上查找 CRMEB 项目根（同时含 app/ 与 vendor/ 的目录）
 * 不能写死层级数：vendor 包路径深度可能变化
 */
function findRoot($start)
{
    $dir = $start;
    for ($i = 0; $i < 6; $i++) {
        if (is_dir($dir . '/app') && is_dir($dir . '/vendor')) {
            return $dir;
        }
        $dir = dirname($dir);
    }
    return null;
}

// 1. 移除应用目录
if (is_link($targetAppDir)) {
    unlink($targetAppDir);
    echo "[bailinghub] 已移除 app/bailing 软链\n";
} elseif (is_dir($targetAppDir)) {
    recurseRemove($targetAppDir);
    echo "[bailinghub] 已移除 app/bailing 目录\n";
}

// 2. 移除配置文件
$configFile = $rootDir . '/config/bailing.php';
if (is_file($configFile)) {
    unlink($configFile);
    echo "[bailinghub] 已移除 config/bailing.php\n";
}

// 2.2 移除网页安装器的一次性口令、锁定和预设文件（重装前的必要清理）
foreach (['/runtime/bailinghub_install.lock', '/runtime/bailinghub_install.key', '/runtime/bailinghub_preset.json'] as $f) {
    if (is_file($rootDir . $f)) {
        unlink($rootDir . $f);
        echo "[bailinghub] 已移除 $f\n";
    }
}

// 2.3 删除插件自有升级暂存、历史和备份；该目录不承载 CRMEB 业务数据。
$upgradeStateDir = $rootDir . '/runtime/bailinghub-updates';
if (is_dir($upgradeStateDir) && !is_link($upgradeStateDir)) {
    recurseRemove($upgradeStateDir);
    echo "[bailinghub] 已移除 /runtime/bailinghub-updates\n";
}

// 2.4 精确删除插件自有私密存储。符号链接只删除链接本身，绝不递归到链接目标。
$secretStateDir = $rootDir . '/runtime/bailinghub-secrets';
if (is_link($secretStateDir) || is_file($secretStateDir)) {
    if (!unlink($secretStateDir)) {
        throw new RuntimeException('无法删除 /runtime/bailinghub-secrets 路径');
    }
    echo "[bailinghub] 已移除 /runtime/bailinghub-secrets 路径\n";
} elseif (is_dir($secretStateDir)) {
    recurseRemove($secretStateDir);
    if (is_dir($secretStateDir) || is_link($secretStateDir) || file_exists($secretStateDir)) {
        throw new RuntimeException('无法完整删除 /runtime/bailinghub-secrets');
    }
    echo "[bailinghub] 已移除 /runtime/bailinghub-secrets\n";
}

// 2.1 从 vendor/services.php 移除服务注册
$servicesFile = $rootDir . '/vendor/services.php';
if (is_file($servicesFile)) {
    $content = file_get_contents($servicesFile);
    if (strpos($content, 'BailingService') !== false) {
        $content = preg_replace('/^\s*\d+\s*=>\s*[\'"]app\\\\\\\\bailing\\\\\\\\BailingService[\'"],?\s*\n/m', '', $content);
        file_put_contents($servicesFile, $content);
        echo "[bailinghub] 已从 vendor/services.php 移除服务注册\n";
    }
}

// 3. 清理数据库配置（需在 CRMEB 项目根有 .env 数据库配置时才能连上）
//    通过 ThinkPHP 引导较为笨重，这里输出 SQL 供手动执行，或尝试用 .env 直连
$envFile = $rootDir . '/.env';
if (is_file($envFile)) {
    $env = parseEnv($envFile);
    $host = $env['DB_HOST'] ?? ($env['DB_HOSTNAME'] ?? '127.0.0.1');
    $port = $env['DB_PORT'] ?? ($env['DB_HOSTPORT'] ?? '3306');
    $name = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? 'root';
    $pass = $env['DB_PASSWORD'] ?? '';
    $prefix = $env['DB_PREFIX'] ?? 'eb_';

    if ($name !== '') {
        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // 删除配置项
            $names = ['bailing_embed_code', 'bailing_hub_url', 'bailing_access_token', 'bailing_sign_secret', 'bailing_route', 'bailing_chat_entry'];
            $in = implode(',', array_fill(0, count($names), '?'));
            $stmt = $pdo->prepare("DELETE FROM {$prefix}system_config WHERE menu_name IN ($in)");
            $stmt->execute($names);
            echo "[bailinghub] 已删除 {$prefix}system_config 中的百灵配置项（{$stmt->rowCount()} 条）\n";

            // 剥离聊天注入标记块
            $markBegin = '/* ==== bailinghub-widget-begin ==== */';
            $markEnd = '/* ==== bailinghub-widget-end ==== */';
            $row = $pdo->query("SELECT value FROM {$prefix}system_config WHERE menu_name='custom_admin_js'")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $current = (string)json_decode($row['value'], true);
                $pattern = '/' . preg_quote($markBegin, '/') . '.*?' . preg_quote($markEnd, '/') . '/s';
                $new = trim(preg_replace($pattern, '', $current));
                if ($new !== $current) {
                    $pdo->prepare("UPDATE {$prefix}system_config SET value=? WHERE menu_name='custom_admin_js'")
                        ->execute([json_encode($new)]);
                    echo "[bailinghub] 已从 custom_admin_js 剥离聊天组件标记块\n";
                }
            }

            // 删除配置分组
            $stmt = $pdo->prepare("DELETE FROM {$prefix}system_config_tab WHERE eng_title='bailing_config'");
            $stmt->execute();
            echo "[bailinghub] 已删除配置分组 bailing_config（{$stmt->rowCount()} 条）\n";
        } catch (Throwable $e) {
            echo "[bailinghub] 数据库清理失败（{$e->getMessage()}），请手动执行以下 SQL：\n";
            echoManualSql($prefix);
        }
    } else {
        echoManualSql($prefix);
    }
} else {
    echoManualSql('eb_');
}

echo "[bailinghub] 卸载完成。记得 composer remove crmeb/bailinghub\n";

// 4. 自删除 vendor 包目录（zip/网页安装场景没有 composer remove 这一步）
// PHP 已把本脚本加载进内存，Linux 下删除运行中的脚本所在目录是安全的
$pkgDir = $rootDir . '/vendor/crmeb/bailinghub';
if (is_dir($pkgDir)) {
    recurseRemove($pkgDir);
    echo "[bailinghub] 已移除 vendor/crmeb/bailinghub 包目录\n";
}

function echoManualSql($prefix)
{
    echo "[bailinghub] 请手动执行以下 SQL 完成数据库清理：\n";
    echo "  DELETE FROM {$prefix}system_config WHERE menu_name IN ('bailing_embed_code','bailing_hub_url','bailing_access_token','bailing_sign_secret','bailing_route','bailing_chat_entry');\n";
    echo "  DELETE FROM {$prefix}system_config_tab WHERE eng_title='bailing_config';\n";
    echo "  -- custom_admin_js 中如含 bailinghub-widget 标记块，请手动删除该块\n";
}

function parseEnv($file)
{
    // TP 风格 .env：分节（[DATABASE]）+ 键值；键自动补 DB_ 前缀
    $out = [];
    $section = '';
    foreach (file($file) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $section = strtoupper(trim($m[1]));
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = strtoupper(trim($k));
        $v = trim(trim($v), '"\'');
        if ($section === 'DATABASE' && strpos($k, 'DB_') !== 0) {
            $k = 'DB_' . $k;
        }
        $out[$k] = $v;
    }
    return $out;
}

function recurseRemove($dir)
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $ok = $file->isDir() && !$file->isLink()
            ? @rmdir($file->getPathname())
            : @unlink($file->getPathname());
        if (!$ok && (file_exists($file->getPathname()) || is_link($file->getPathname()))) {
            throw new RuntimeException('无法删除路径: ' . $file->getPathname());
        }
    }
    if (!@rmdir($dir) && is_dir($dir)) {
        throw new RuntimeException('无法删除目录: ' . $dir);
    }
}
