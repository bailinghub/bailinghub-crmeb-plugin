<?php
// +----------------------------------------------------------------------
// | 百灵中枢 CRMEB 专用安全配置接口
// | GET 仅返回非秘密摘要；POST 还要求超级管理员、同源和一次性 nonce。
// +----------------------------------------------------------------------
namespace app\bailing\controller;

use app\bailing\settings\SettingsException;
use app\bailing\settings\SettingsInput;
use app\bailing\settings\SettingsRepository;
use app\bailing\settings\SecretStore;
use app\bailing\upgrade\NonceStore;
use app\bailing\upgrade\OriginGuard;
use app\bailing\upgrade\UpgradeStorage;

class BailingSettingsController
{
    const MAX_JSON_BYTES = 65536;
    const NONCE_SCOPE = 'settings_save';

    /** 已登录管理员可读取，但响应永远不包含 token 或签名密钥明文。 */
    public function status()
    {
        try {
            $admin = $this->authenticate();
            list($repository, $nonces) = $this->services();
            $canManage = (int)$admin['level'] === 0;
            $data = $repository->status();
            $data['can_manage'] = $canManage;
            $data['nonce'] = $canManage
                ? $nonces->issue((int)$admin['id'], self::NONCE_SCOPE)
                : null;
            return $this->success($data);
        } catch (SettingsException $e) {
            return $this->failure($e->getMessage(), $e->httpStatus());
        } catch (\Throwable $e) {
            return $this->failure('无法读取百灵中枢配置状态', 500);
        }
    }

    /**
     * 保存采用局部替换语义：
     * - embed_code 非空：解析并替换 hub + entry；
     * - embed_code 空、hub/entry 任一非空：两者必须成对替换；
     * - 上述均空：聊天配置保持原值；
     * - token/secret 空：保持原值，非空才替换。
     */
    public function save()
    {
        try {
            $admin = $this->authenticate();
            $this->requireSuperAdmin($admin);
            $this->requireSameOrigin();
            $payload = $this->jsonPayload();
            list($repository, $nonces) = $this->services();

            $nonce = isset($payload['nonce']) && is_string($payload['nonce'])
                ? trim($payload['nonce']) : '';
            if (!$nonces->consume((int)$admin['id'], self::NONCE_SCOPE, $nonce)) {
                throw new SettingsException('配置 nonce 无效、已使用或已过期', 403);
            }

            $changes = SettingsInput::normalize($payload);
            $data = $repository->save($changes);
            $data['can_manage'] = true;
            try {
                $data['nonce'] = $nonces->issue((int)$admin['id'], self::NONCE_SCOPE);
            } catch (\Throwable $ignored) {
                // 保存已经完成；新 nonce 是便利返回值，失败时前端重新 GET status 即可。
                $data['nonce'] = null;
            }
            return $this->success($data);
        } catch (SettingsException $e) {
            return $this->failure($e->getMessage(), $e->httpStatus());
        } catch (\Throwable $e) {
            // 不回显数据库异常或请求内容，避免秘密进入错误响应和日志聚合链。
            return $this->failure('百灵中枢配置保存失败', 500);
        }
    }

    /** 直接复用 CRMEB 自己的后台登录令牌解析服务，不建立第二套管理员身份。 */
    private function authenticate()
    {
        $header = trim((string)request()->header('Authori-zation', ''));
        if ($header === '') {
            $header = trim((string)request()->header('Authorization', ''));
        }
        $token = stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : $header;
        if ($token === '') {
            throw new SettingsException('请登录', 401);
        }
        try {
            $service = app()->make(\app\services\system\admin\AdminAuthServices::class);
            $admin = $service->parseToken($token);
        } catch (\Throwable $e) {
            throw new SettingsException('登录已过期，请重新登录', 401);
        }
        if (!is_array($admin) || empty($admin['id']) || !array_key_exists('level', $admin)) {
            throw new SettingsException('登录状态无效', 401);
        }
        return $admin;
    }

    private function requireSuperAdmin(array $admin)
    {
        if ((int)$admin['level'] !== 0) {
            throw new SettingsException('只有 CRMEB 超级管理员可以修改百灵中枢配置', 403);
        }
    }

    private function requireSameOrigin()
    {
        $origin = (string)request()->header('Origin', '');
        $host = (string)request()->header('Host', '');
        if (!OriginGuard::isSameOrigin($origin, $host)) {
            throw new SettingsException('配置请求必须来自当前 CRMEB 后台同源页面', 403);
        }
    }

    private function jsonPayload()
    {
        $contentLength = (int)request()->header('Content-Length', 0);
        if ($contentLength > self::MAX_JSON_BYTES) {
            throw new SettingsException('配置请求不能超过 64KB', 413);
        }
        $contentType = strtolower(trim((string)request()->header('Content-Type', '')));
        if (!preg_match('#^application/json(?:\s*;|$)#', $contentType)) {
            throw new SettingsException('配置保存只接受 application/json', 415);
        }
        $raw = (string)request()->getContent();
        if ($raw === '' || strlen($raw) > self::MAX_JSON_BYTES) {
            throw new SettingsException('配置请求内容为空或过长', strlen($raw) > self::MAX_JSON_BYTES ? 413 : 422);
        }
        if (substr(ltrim($raw), 0, 1) !== '{') {
            throw new SettingsException('配置请求必须是 JSON 对象');
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new SettingsException('配置请求 JSON 格式不正确');
        }
        return $payload;
    }

    private function services()
    {
        $root = rtrim((string)app()->getRootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $storage = new UpgradeStorage($root . 'runtime');
        return array(
            new SettingsRepository(new SecretStore($root)),
            new NonceStore($storage->nonceDirectory()),
        );
    }

    private function success(array $data)
    {
        return json(array('status' => 1, 'data' => $data))->header(array(
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ));
    }

    private function failure($message, $httpStatus)
    {
        return json(array('status' => 0, 'msg' => (string)$message), (int)$httpStatus)->header(array(
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ));
    }
}
