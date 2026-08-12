<?php
// +----------------------------------------------------------------------
// | 百灵中枢插件 - 网页一键安装器
// | 面向无 SSH/composer 环境的用户（宝塔面板/FTP 场景）
// |
// | 推荐使用方法：
// |   1. 把网页安装整包上传到 CRMEB 根目录并解压一次
// |   2. 解压后会得到 public/bailinghub-setup.php 和 crmeb-bailinghub.zip
// |   3. 浏览器访问 https://你的域名/bailinghub-setup.php 按提示安装
// | 也兼容分别上传本文件和插件 zip 包的旧流程。
// |
// | 安全说明：安装前必须由服务器管理者在 runtime/bailinghub_install.key
// | 放入一次性随机口令。页面只校验、不回显该口令；未授权请求不会执行任何写入。
// | 安装成功后销毁口令文件，并生成 runtime/bailinghub_install.lock 防重放。
// | 建议安装完成后删除本文件。
// +----------------------------------------------------------------------

@set_time_limit(120);
header('Content-Type: text/html; charset=utf-8');

$rootDir = dirname(__DIR__);          // public/ 的上级 = CRMEB 根目录
$lockFile = $rootDir . '/runtime/bailinghub_install.lock';
$installKeyFile = $rootDir . '/runtime/bailinghub_install.key';
$pkgDir = $rootDir . '/vendor/crmeb/bailinghub';
$appDir = $rootDir . '/app/bailing';
$configDst = $rootDir . '/config/bailing.php';
$servicesFile = $rootDir . '/vendor/services.php';

$msg = [];
$err = [];
$action = isset($_POST['action']) ? $_POST['action'] : '';
$normalizedHubUrl = '';
$normalizedChatEntry = '';

// ---------- 状态检测 ----------
$installed = is_dir($appDir) && is_file($appDir . '/controller/BailingController.php');
$serviceOk = is_file($servicesFile) && strpos(file_get_contents($servicesFile), 'BailingService') !== false;
$locked = is_file($lockFile);
$serverInstallKey = readInstallKey($installKeyFile);
$installKeyReady = $serverInstallKey !== '';

// 查找 zip 包：优先使用标准插件包，避免误把留在根目录的网页安装整包当作插件载荷。
$zipFound = '';
$preferredZips = [
    $rootDir . '/crmeb-bailinghub.zip',
    $rootDir . '/public/crmeb-bailinghub.zip',
];
foreach ($preferredZips as $z) {
    if (is_file($z)) {
        $zipFound = $z;
        break;
    }
}
if ($zipFound === '') {
    foreach ([$rootDir, $rootDir . '/public'] as $dir) {
        foreach (glob($dir . '/*bailinghub*.zip') ?: [] as $z) {
            $name = strtolower(basename($z));
            if (strpos($name, 'web-install') !== false || strpos($name, 'web_installer') !== false) {
                continue;
            }
            $zipFound = $z;
            break 2;
        }
    }
}

// ---------- 预检：目录可写性（PHP 进程用户需要对以下目录有写权限） ----------
$writeChecks = [
    'vendor' => $rootDir . '/vendor',
    'app' => $rootDir . '/app',
    'config' => $rootDir . '/config',
    'runtime' => $rootDir . '/runtime',
];
$writableFail = [];
foreach ($writeChecks as $label => $dir) {
    if (!is_dir($dir) || !is_writable($dir)) {
        $writableFail[] = $label;
    }
}

// ---------- 执行安装 ----------
if ($action === 'install') {
    $submittedInstallKey = isset($_POST['install_key']) ? (string)$_POST['install_key'] : '';
    if (!$installKeyReady) {
        $err[] = '安装口令未就绪：请先由服务器管理者创建 runtime/bailinghub_install.key，再刷新本页。';
    } elseif (!installKeyMatches($serverInstallKey, $submittedInstallKey)) {
        $err[] = '安装口令错误，未执行任何写入。';
    } elseif ($locked) {
        $err[] = '已安装并锁定（runtime/bailinghub_install.lock 存在）。如需重装请删除该锁定文件。';
    } elseif ($writableFail) {
        $err[] = '目录不可写：' . implode('、', $writableFail) . '。请把这些目录的所有者改为 PHP 运行用户（宝塔通常是把文件所有者设为 www），或给目录加写权限后刷新重试。';
    } else {
        try {
            $rawHubUrl = isset($_POST['hub_url']) ? (string)$_POST['hub_url'] : '';
            $embedCode = isset($_POST['embed_code']) ? trim((string)$_POST['embed_code']) : '';
            if ($embedCode !== '') {
                $parsedEmbed = parseChatEmbedForInstaller($embedCode, $rawHubUrl);
                $normalizedHubUrl = $parsedEmbed['hub_url'];
                $normalizedChatEntry = $parsedEmbed['entry_key'];
            } else {
                $normalizedHubUrl = normalizeHubUrlForInstaller($rawHubUrl);
            }
        } catch (InvalidArgumentException $e) {
            $err[] = '聊天入口配置无效：' . $e->getMessage();
        }
    }

    if (!$err) {
        try {
            // 1. 找包：已解压目录优先，否则解压 zip
            if (!is_dir($pkgDir . '/src')) {
                if ($zipFound === '') {
                    throw new Exception('未找到插件包：请把 crmeb-bailinghub.zip 上传到 CRMEB 根目录或 public/ 下');
                }
                if (!class_exists('ZipArchive')) {
                    throw new Exception('PHP 未启用 zip 扩展，无法自动解压。请手动解压到 vendor/crmeb/bailinghub/');
                }
                $zip = new ZipArchive();
                if ($zip->open($zipFound) !== true) {
                    throw new Exception('zip 包打开失败：' . basename($zipFound));
                }
                $tmpDir = $rootDir . '/runtime/bailinghub_pkg_' . time();
                $zip->extractTo($tmpDir);
                $zip->close();
                // zip 内顶层目录名兼容 crmeb-bailinghub/ 或扁平结构
                $srcRoot = is_dir($tmpDir . '/crmeb-bailinghub/src') ? $tmpDir . '/crmeb-bailinghub' : $tmpDir;
                if (!is_dir($srcRoot . '/src')) {
                    $sub = glob($tmpDir . '/*/src') ?: [];
                    if ($sub) {
                        $srcRoot = dirname($sub[0]);
                    }
                }
                if (!is_dir($srcRoot . '/src')) {
                    recurseRemove($tmpDir);
                    throw new Exception('zip 包结构不正确：找不到 src/ 目录');
                }
                if (!is_dir(dirname($pkgDir)) && !@mkdir(dirname($pkgDir), 0755, true)) {
                    recurseRemove($tmpDir);
                    throw new Exception('无法创建 vendor/crmeb 目录（权限不足）');
                }
                recurseCopy($srcRoot, $pkgDir);
                recurseRemove($tmpDir);
                // 验证包完整
                if (!is_file($pkgDir . '/src/BailingService.php')) {
                    throw new Exception('插件包复制不完整（缺少 src/BailingService.php），请检查磁盘空间后重试');
                }
                $msg[] = '插件包已就位 vendor/crmeb/bailinghub/';
            }

            // 2. 复制应用代码到 app/bailing/
            if (is_dir($appDir)) {
                recurseRemove($appDir);
            }
            recurseCopy($pkgDir . '/src', $appDir);
            if (!is_file($appDir . '/controller/BailingController.php')) {
                throw new Exception('应用代码复制失败（app/bailing 目录不可写？）');
            }
            $msg[] = '应用代码已复制到 app/bailing/';

            // 3. 配置文件（不覆盖已有）
            if (!is_file($configDst) && is_file($pkgDir . '/config/bailing.php')) {
                if (!copy($pkgDir . '/config/bailing.php', $configDst)) {
                    throw new Exception('config 目录不可写，无法创建 config/bailing.php');
                }
                $msg[] = '已创建 config/bailing.php';
            }

            // 4. 注册 ThinkPHP 服务
            if (!$serviceOk) {
                registerService($servicesFile);
                if (!strpos(file_get_contents($servicesFile), 'BailingService')) {
                    throw new Exception('服务注册失败（vendor/services.php 不可写）');
                }
                $msg[] = '已注册 BailingService 到 vendor/services.php';
            }

            // 5. 用户预填的中枢地址/聊天入口：首次启动时回填到底层配置
            if ($normalizedHubUrl !== '' || $normalizedChatEntry !== '') {
                $presetJson = json_encode([
                    'hub_url' => $normalizedHubUrl,
                    'chat_entry' => $normalizedChatEntry,
                ]);
                if ($presetJson === false
                    || file_put_contents($rootDir . '/runtime/bailinghub_preset.json', $presetJson) === false
                ) {
                    throw new Exception('无法写入聊天入口预设文件');
                }
                $msg[] = '聊天入口配置已保存（首次访问后台时自动填入）';
            }

            // 6. 全部安装写入成功后才锁定，避免把半成品误报为已安装。
            if (file_put_contents($lockFile, date('Y-m-d H:i:s') . " installed\n") === false) {
                throw new Exception('无法写入安装锁定文件');
            }
            $msg[] = '安装完成！锁定文件已生成';
            $installed = true;
            $serviceOk = true;
            $locked = true;

            // 7. 一次性口令只用于本次安装。锁已落盘后立即销毁，防止重放。
            if (is_file($installKeyFile) && @unlink($installKeyFile)) {
                $serverInstallKey = '';
                $installKeyReady = false;
                $msg[] = '一次性安装口令已销毁';
            } else {
                $err[] = '安装已完成并锁定，但一次性口令文件未能自动删除；请手动删除 runtime/bailinghub_install.key。';
            }
        } catch (Exception $e) {
            $err[] = '安装失败：' . $e->getMessage();
        }
    }
}

// ---------- 辅助函数 ----------
/**
 * 读取服务器管理者预先放置的一次性安装口令。
 * 只接受 32 字节随机数的 64 位十六进制表示；不回显、不自动生成，避免首访者取得授权。
 */
function readInstallKey($file)
{
    if (!is_file($file) || is_link($file) || !is_readable($file)) {
        return '';
    }
    $key = trim((string)file_get_contents($file));
    return preg_match('/^[a-f0-9]{64}$/i', $key) ? strtolower($key) : '';
}

/** 使用恒定时间比较口令，避免通过响应时间逐字节探测。 */
function installKeyMatches($serverKey, $submittedKey)
{
    $submittedKey = strtolower(trim((string)$submittedKey));
    if ($serverKey === '' || !preg_match('/^[a-f0-9]{64}$/', $submittedKey)) {
        return false;
    }
    return hash_equals($serverKey, $submittedKey);
}

function recurseCopy($src, $dst)
{
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        throw new Exception('无法创建目录 ' . $dst . '（权限不足）');
    }
    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if ($file != '.' && $file != '..') {
            if (is_dir($src . '/' . $file)) {
                recurseCopy($src . '/' . $file, $dst . '/' . $file);
            } else {
                if (!copy($src . '/' . $file, $dst . '/' . $file)) {
                    closedir($dir);
                    throw new Exception('复制文件失败：' . $file);
                }
            }
        }
    }
    closedir($dir);
}

function recurseRemove($dir)
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

function registerService($servicesFile)
{
    $entry = "  99 => 'app\\\\bailing\\\\BailingService',\n";
    if (is_file($servicesFile)) {
        $content = file_get_contents($servicesFile);
        $pos = strrpos($content, ');');
        if ($pos !== false) {
            file_put_contents($servicesFile, substr($content, 0, $pos) . $entry . substr($content, $pos));
            return;
        }
    }
    file_put_contents($servicesFile, "<?php \ndeclare (strict_types = 1);\nreturn array (\n" . $entry . ");\n");
}

/**
 * 安装器是可单文件上传的入口，不能依赖尚未解压的插件类，故在这里保留同等校验。
 * 返回值可直接拼接 /widget.js。
 */
function normalizeHubUrlForInstaller($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)
        && !preg_match('#^https?://#i', $value)
    ) {
        throw new InvalidArgumentException('中枢地址只支持 http 或 https');
    }
    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);
    if (!is_array($parts)
        || empty($parts['scheme'])
        || empty($parts['host'])
        || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
    ) {
        throw new InvalidArgumentException('中枢地址必须是有效的 http(s) 地址');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('中枢地址不能包含用户名或密码');
    }

    $scheme = strtolower($parts['scheme']);
    $host = rtrim(strtolower($parts['host']), '.');
    $hostForUrl = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
    $origin = $scheme . '://' . $hostForUrl . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    $path = isset($parts['path']) ? preg_replace('#/+$#', '', $parts['path']) : '';
    if (preg_match('#^(.*?)/widget\.js$#i', $path, $matches)) {
        $path = $matches[1];
    }
    if (preg_match('#^(.*?)/console(?:/.*)?$#i', $path, $matches)) {
        $path = $matches[1];
    }
    $path = preg_replace('#/+$#', '', $path);

    if ($host === 'trial.bailinghub.com'
        && !preg_match('#^/tenant/[a-zA-Z0-9][a-zA-Z0-9_.:-]{1,63}$#', $path)
    ) {
        throw new InvalidArgumentException(
            'BailingHub 在线体验必须使用包含 /tenant/<租户ID> 的具体租户地址；请进入申请到的体验租户控制台复制聊天入口代码'
        );
    }

    return $origin . $path;
}

/**
 * 单文件安装器版嵌入代码解析。与 app\bailing\ChatEmbed 保持同一契约，
 * 但不能依赖尚未解压的插件类。
 */
function parseChatEmbedForInstaller($value, $fallbackHub = '')
{
    $value = trim((string)$value);
    if ($value === '') {
        throw new InvalidArgumentException('请粘贴聊天入口的完整嵌入代码');
    }
    if (preg_match('/^[a-z0-9_-]{4,32}$/', $value)) {
        if (trim((string)$fallbackHub) === '') {
            throw new InvalidArgumentException('只填写聊天入口 key 时，必须先填写下方的百灵中枢地址');
        }
        return array(
            'hub_url' => normalizeHubUrlForInstaller($fallbackHub),
            'entry_key' => normalizeEntryKeyForInstaller($value),
        );
    }

    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('/<script\b[^>]*>/i', $decoded, $tags);
    $candidates = array();
    foreach (isset($tags[0]) ? $tags[0] : array() as $tag) {
        if (preg_match('/(?:^|\s)data-ticket(?=\s|=|>)/i', $tag)) {
            throw new InvalidArgumentException('嵌入代码不能包含临时 data-ticket；请从 BailingHub 控制台复制不含临时票据的标准嵌入代码');
        }
        $src = chatEmbedAttributeForInstaller($tag, 'src');
        $entry = chatEmbedAttributeForInstaller($tag, 'data-entry');
        if ($src === null && $entry === null) {
            continue;
        }
        if ($src !== null) {
            $probe = preg_match('#^https?://#i', $src) ? $src : 'https://' . $src;
            $parts = parse_url($probe);
            $path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '';
            if (!preg_match('#/widget\.js$#i', $path)) {
                if ($entry !== null) {
                    throw new InvalidArgumentException('嵌入代码的 src 必须指向 BailingHub 的 widget.js');
                }
                continue;
            }
        }
        if ($src === null || $entry === null) {
            throw new InvalidArgumentException('嵌入代码必须同时包含 src 和 data-entry');
        }
        $candidate = array(
            'hub_url' => normalizeHubUrlForInstaller($src),
            'entry_key' => normalizeEntryKeyForInstaller($entry),
        );
        $candidates[$candidate['hub_url'] . "\n" . $candidate['entry_key']] = $candidate;
    }
    if (!$candidates) {
        throw new InvalidArgumentException('没有找到包含 src 和 data-entry 的 BailingHub <script> 嵌入代码');
    }
    if (count($candidates) > 1) {
        throw new InvalidArgumentException('检测到多个不同的聊天入口，请一次只粘贴一个嵌入代码');
    }
    return reset($candidates);
}

function normalizeEntryKeyForInstaller($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^[a-z0-9_-]{4,32}$/', $value)) {
        throw new InvalidArgumentException('聊天入口 key 格式不正确，应为 4-32 位小写字母、数字、下划线或连字符（如 pub_xxx）');
    }
    return $value;
}

function chatEmbedAttributeForInstaller($tag, $name)
{
    $pattern = '/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i';
    if (!preg_match($pattern, $tag, $matches)) {
        return null;
    }
    for ($i = 1; $i <= 3; $i++) {
        if (isset($matches[$i]) && $matches[$i] !== '') {
            return html_entity_decode(trim($matches[$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return '';
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>百灵中枢插件安装 · BailingHub</title>
<style>
/* 风格对齐 www.bailinghub.com：暗色底 + 信号绿 + 直角 + JetBrains Mono */
:root{
  --bg:#0a0b0d;--surface:#0e1013;--ink:#e6e8eb;--ink-bright:#f3f5f7;
  --muted:#9aa0a8;--faint:#6b7178;
  --green:#3fb950;--green-bright:#56d364;--on-green:#06120a;
  --line:rgba(255,255,255,.06);--warn:#febc2e;--danger:#ff6b6b;
  --mono:'JetBrains Mono',ui-monospace,SFMono-Regular,Menlo,monospace;
  --sans:'Noto Sans SC','HarmonyOS Sans SC','PingFang SC',system-ui,-apple-system,sans-serif;
}
*{box-sizing:border-box}
body{font-family:var(--sans);background:var(--bg);margin:0;padding:24px 16px;color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center}
.box{width:100%;max-width:680px;background:var(--surface);border:1px solid var(--line);border-radius:0;padding:40px}
.brand{font-family:var(--mono);font-size:12px;color:var(--green);letter-spacing:.12em;margin-bottom:10px}
h1{font-size:22px;margin:0 0 8px;color:var(--ink-bright);font-weight:700}
.sub{color:var(--muted);font-size:13px;margin-bottom:28px}
.status{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--line);border-radius:0;font-size:14px;margin-bottom:8px;background:var(--bg)}
.status::before{content:'';width:8px;height:8px;flex:none}
.status.ok::before{background:var(--green-bright)}
.status.no::before{background:var(--danger)}
.status.warn::before{background:var(--warn)}
.status.todo::before{border:1px solid var(--faint);background:transparent}
.status.todo{color:var(--muted)}
.section-label{font-family:var(--mono);font-size:11px;color:var(--faint);letter-spacing:.1em;margin:18px 0 8px}
.msg{border-left:2px solid var(--green);background:var(--bg);color:var(--green-bright);padding:10px 14px;font-size:13px;margin-bottom:6px;font-family:var(--mono)}
.err{border-left:2px solid var(--danger);background:var(--bg);color:var(--danger);padding:10px 14px;font-size:13px;margin-bottom:6px}
button{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--green);color:var(--on-green);border:1px solid transparent;border-radius:0;padding:13px 28px;font-size:15px;font-weight:600;cursor:pointer;width:100%;transition:.15s;font-family:var(--sans)}
button:hover{background:var(--green-bright)}
button:disabled{background:var(--faint);cursor:not-allowed}
.steps{border:1px solid var(--line);background:var(--bg);border-radius:0;padding:18px;font-size:13px;line-height:2;color:var(--muted);margin-top:20px}
.steps b{color:var(--ink)}
code{background:rgba(255,255,255,.06);padding:2px 6px;border-radius:0;font-size:12px;font-family:var(--mono);color:var(--green-bright)}
a{color:var(--green-bright);text-decoration:none}
a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="box">
<div class="brand">BAILINGHUB // CRMEB PLUGIN</div>
<h1>百灵中枢（BailingHub）插件安装器</h1>
<div class="sub">CRMEB × AI 中枢接入 · 网页一键安装</div>

<?php foreach ($msg as $m): ?><div class="msg">✓ <?= htmlspecialchars($m) ?></div><?php endforeach; ?>
<?php foreach ($err as $e): ?><div class="err">✗ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="section-label">ENVIRONMENT CHECK // 环境检测</div>
<div class="status <?= $zipFound || is_dir($pkgDir . '/src') ? 'ok' : 'no' ?>">插件包：<?= is_dir($pkgDir . '/src') ? '已就位（vendor/crmeb/bailinghub）' : ($zipFound ? '已找到 zip：' . basename($zipFound) : '未找到，请上传 crmeb-bailinghub.zip') ?></div>
<div class="status <?= $writableFail ? 'no' : 'ok' ?>">目录写权限：<?= $writableFail ? ('不可写：' . implode('、', $writableFail)) : '正常（vendor / app / config / runtime）' ?></div>
<div class="status <?= $locked || $installKeyReady ? 'ok' : 'no' ?>">一次性安装口令：<?= $locked ? '安装已锁定，口令应已销毁' : ($installKeyReady ? '已就绪（服务端已读取，不会在页面回显）' : '未就绪，请先创建 runtime/bailinghub_install.key') ?></div>

<div class="section-label">INSTALL PLAN // 安装器将执行</div>
<div class="status todo">部署应用代码到 app/bailing/<span style="margin-left:auto;font-size:12px;color:var(--faint)"><?= $installed ? '已安装，将覆盖更新' : '待执行' ?></span></div>
<div class="status todo">注册服务到 vendor/services.php<span style="margin-left:auto;font-size:12px;color:var(--faint)"><?= $serviceOk ? '已注册，将跳过' : '待执行' ?></span></div>
<div class="status todo">创建 config/bailing.php 与后台配置项<span style="margin-left:auto;font-size:12px;color:var(--faint)">待执行</span></div>

<?php if (!$locked): ?>
<?php if (!$installKeyReady): ?>
<div class="steps">
<b>先创建一次性安装口令：</b><br>
1. 点击下方按钮在当前浏览器本地生成 64 位随机口令；生成过程不会向服务器发送内容。<br>
2. 用宝塔/FTP 文件管理器在 CRMEB 根目录创建 <code>runtime/bailinghub_install.key</code>，只写入该口令；有 SSH 时也可执行 <code>php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'</code> 后把输出写入该文件，并尽量设置权限为 <code>600</code>。<br>
3. 刷新本页，看到“安装口令已就绪”后，把同一口令填入安装表单。安装成功后文件会自动销毁。<br><br>
<button type="button" id="bailinghub-generate-key">在本机生成随机口令</button>
<code id="bailinghub-generated-key" style="display:none;margin-top:10px;padding:10px;word-break:break-all;user-select:all"></code>
</div>
<script>
(function(){
  var button=document.getElementById('bailinghub-generate-key');
  var output=document.getElementById('bailinghub-generated-key');
  if(!button||!output)return;
  button.onclick=function(){
    if(!window.crypto||!window.crypto.getRandomValues){
      output.textContent='当前浏览器不支持安全随机数，请使用页面上方 PHP 命令生成。';
      output.style.display='block';
      return;
    }
    var bytes=new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    var value='';
    for(var i=0;i<bytes.length;i++) value+=bytes[i].toString(16).padStart(2,'0');
    output.textContent=value;
    output.style.display='block';
  };
})();
</script>
<?php endif; ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="action" value="install">
  <div style="margin-bottom:14px">
    <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:6px">一次性安装口令（必填）</label>
    <input type="password" name="install_key" value="" autocomplete="new-password" placeholder="runtime/bailinghub_install.key 中的 64 位随机口令" style="width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--line);border-radius:0;color:var(--ink);font-size:14px;font-family:var(--mono)">
    <div style="font-size:12px;color:var(--faint);margin-top:7px;line-height:1.7">服务端只做恒定时间校验，绝不会把文件中的口令回显到页面。</div>
  </div>
  <div style="margin-bottom:14px;padding:14px;border:1px solid var(--line);background:var(--bg)">
    <div style="font-size:13px;color:var(--muted);margin-bottom:9px">从哪里获取聊天入口代码？请选择你的情况</div>
    <label style="display:block;margin:7px 0"><input type="radio" name="hub_source" value="open_source" checked> 已部署自己的 BailingHub 开源版</label>
    <label style="display:block;margin:7px 0"><input type="radio" name="hub_source" value="commercial"> 已部署自己的 BailingHub 商业版</label>
    <label style="display:block;margin:7px 0"><input type="radio" name="hub_source" value="online_trial"> 目前没有中枢，先申请 BailingHub 在线体验空间</label>
    <div id="bailinghub-source-help" style="font-size:12px;color:var(--faint);margin-top:9px;line-height:1.7"></div>
  </div>
  <div style="margin-bottom:14px">
    <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:6px">聊天入口嵌入代码（推荐，安装时可留空）</label>
    <textarea name="embed_code" rows="4" placeholder="从 BailingHub 控制台「聊天入口 → 嵌入」复制完整 &lt;script&gt; 代码" style="width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--line);border-radius:0;color:var(--ink);font-size:13px;font-family:var(--mono);resize:vertical"><?= htmlspecialchars(isset($_POST['embed_code']) ? (string)$_POST['embed_code'] : '') ?></textarea>
    <div style="font-size:12px;color:var(--faint);margin-top:7px;line-height:1.7">三种来源最终都使用同一种标准嵌入代码；安装器只提取中枢地址和公开入口 key。</div>
  </div>
  <details style="margin-bottom:14px">
    <summary style="cursor:pointer;color:var(--muted);font-size:13px">高级：手动填写中枢地址</summary>
    <div style="margin-top:10px">
      <input type="text" name="hub_url" value="<?= htmlspecialchars(isset($_POST['hub_url']) ? (string)$_POST['hub_url'] : '') ?>" placeholder="仅在无法复制完整嵌入代码时填写" style="width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--line);border-radius:0;color:var(--ink);font-size:14px;font-family:var(--mono)">
      <div style="font-size:12px;color:var(--faint);margin-top:7px;line-height:1.7">开源单实例通常使用站点根地址；商业版和在线体验都必须使用具体租户地址，不能填写平台管理地址。</div>
    </div>
  </details>
  <button type="submit" <?= $writableFail || !$installKeyReady ? 'disabled' : '' ?>>验证口令并开始安装</button>
</form>
<script>
(function(){
  var box=document.getElementById('bailinghub-source-help');
  var choices=document.querySelectorAll('input[name="hub_source"]');
  if(!box||!choices.length)return;
  function render(){
    var value='open_source';
    for(var i=0;i<choices.length;i++)if(choices[i].checked)value=choices[i].value;
    if(value==='commercial'){
      box.textContent='进入你自己部署的商业版，先选择具体租户，再从该租户的中枢控制台复制聊天入口代码；不要复制平台管理地址。';
    }else if(value==='online_trial'){
      box.innerHTML='前往 <a href="https://trial.bailinghub.com/register/" target="_blank" rel="noopener">BailingHub 在线体验</a> 申请空间；租户准备完成后进入该租户控制台复制聊天入口代码。';
    }else{
      box.textContent='进入你自己部署的开源版中枢控制台，从“聊天入口 → 嵌入”复制完整代码。';
    }
  }
  for(var i=0;i<choices.length;i++)choices[i].addEventListener('change',render);
  render();
})();
</script>
<?php if ($writableFail): ?>
<div class="steps">
<b>目录权限不足，请先处理：</b><br>
安装器以 PHP 进程用户（通常是 <code>www</code> / <code>www-data</code>）运行，需要对 CRMEB 的 <code>vendor</code>、<code>app</code>、<code>config</code>、<code>runtime</code> 目录有写权限。<br>
宝塔面板：文件管理 → 选中这些目录 → 权限 → 所有者改为 <code>www</code>（或权限 755→775 并加入 www 组）。<br>
SSH：执行 <code>chown -R www:www vendor app config runtime</code>（用户名按你的 PHP 用户调整）。
</div>
<?php else: ?>
<div class="steps">
点击上方按钮即完成全部安装（解压插件包 → 复制应用代码 → 创建配置 → 注册服务）。<br>
<br>
<b>还没有可用的百灵中枢？</b>可以先到 <a href="https://www.bailinghub.com/docs" target="_blank">bailinghub.com</a> 查看开源版自托管文档；也可以申请 <a href="https://trial.bailinghub.com/register/" target="_blank" rel="noopener">BailingHub 在线体验空间</a>，租户准备完成后从该租户控制台复制聊天入口代码。
</div>
<?php endif; ?>
<?php else: ?>
<div class="steps">
<b>下一步：</b><br>
1. 登录 CRMEB 后台 → 系统设置 → <b>百灵中枢配置</b>，优先粘贴完整聊天入口嵌入代码，并填写 token / 签名密钥<br>
2. 到 BailingHub 中枢控制台登记工具源：<br>
&nbsp;&nbsp;&nbsp;spec_url = <code><?= $siteUrl ?>/bailing/tools.json</code><br>
&nbsp;&nbsp;&nbsp;base_url = <code><?= $siteUrl ?></code><br>
&nbsp;&nbsp;&nbsp;访问策略 = <code>签名保护（signed_required）</code><br>
3. 在中枢点击刷新工具源，确认三探针：正确签名 200 / 未签名 401 / 错误签名 401<br>
<br>
<b>安全提醒：</b>安装已完成，请删除 public/ 下的 <code>bailinghub-setup.php</code>
</div>
<?php endif; ?>
</div>
</body>
</html>
