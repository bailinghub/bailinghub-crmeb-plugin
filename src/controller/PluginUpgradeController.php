<?php
// +----------------------------------------------------------------------
// | 百灵中枢 CRMEB 独立适配器离线升级接口
// | 只接受已登录 CRMEB 管理员；写操作额外要求超级管理员、同源和一次性 nonce。
// +----------------------------------------------------------------------
namespace app\bailing\controller;

use app\bailing\upgrade\NonceStore;
use app\bailing\upgrade\OriginGuard;
use app\bailing\upgrade\PackageValidator;
use app\bailing\upgrade\UpgradeException;
use app\bailing\upgrade\UpgradeManager;
use app\bailing\upgrade\UpgradeStorage;

class PluginUpgradeController
{
    public function status()
    {
        try {
            $admin = $this->authenticate();
            list($manager, $storage, $nonces) = $this->services();
            $data = $manager->status();
            $data['can_upgrade'] = (int)$admin['level'] === 0;
            $data['nonce'] = $data['can_upgrade'] ? $nonces->issue((int)$admin['id'], 'stage') : null;
            return $this->success($data);
        } catch (UpgradeException $e) {
            return $this->failure($e->getMessage(), 401);
        } catch (\Throwable $e) {
            return $this->failure('无法读取插件升级状态', 500);
        }
    }

    public function stage()
    {
        try {
            $admin = $this->authenticate();
            $this->requireSuperAdmin($admin);
            $this->requireSameOrigin();
            list($manager, $storage, $nonces) = $this->services();
            $nonce = trim((string)request()->header('X-Bailing-Upgrade-Nonce', ''));
            if (!$nonces->consume((int)$admin['id'], 'stage', $nonce)) {
                throw new UpgradeException('升级 nonce 无效、已使用或已过期');
            }

            $this->validateUploadName((string)request()->header('X-Bailing-Package-Name', ''));
            $contentLength = (int)request()->header('Content-Length', 0);
            if ($contentLength > PackageValidator::MAX_ZIP_BYTES) {
                throw new UpgradeException('升级包不能超过 20MB');
            }
            $contentType = strtolower((string)request()->header('Content-Type', ''));
            if ($contentType !== '' && strpos($contentType, 'application/zip') !== 0
                && strpos($contentType, 'application/octet-stream') !== 0) {
                throw new UpgradeException('升级包必须以 ZIP 二进制上传');
            }
            $rawZip = (string)request()->getContent();
            $data = $manager->stage($rawZip, (int)$admin['id']);
            $data['apply_nonce'] = $nonces->issue((int)$admin['id'], 'apply');
            return $this->success($data);
        } catch (UpgradeException $e) {
            return $this->failure($e->getMessage(), $this->errorStatus($e->getMessage()));
        } catch (\Throwable $e) {
            return $this->failure('升级包验证失败', 500);
        }
    }

    public function apply()
    {
        try {
            $admin = $this->authenticate();
            $this->requireSuperAdmin($admin);
            $this->requireSameOrigin();
            list($manager, $storage, $nonces) = $this->services();
            $payload = json_decode((string)request()->getContent(), true);
            if (!is_array($payload) || empty($payload['staged_id']) || empty($payload['nonce'])) {
                throw new UpgradeException('缺少 staged_id 或 nonce');
            }
            if (!$nonces->consume((int)$admin['id'], 'apply', (string)$payload['nonce'])) {
                throw new UpgradeException('升级 nonce 无效、已使用或已过期');
            }
            $data = $manager->apply((string)$payload['staged_id'], (int)$admin['id']);
            return $this->success($data);
        } catch (UpgradeException $e) {
            return $this->failure($e->getMessage(), $this->errorStatus($e->getMessage()));
        } catch (\Throwable $e) {
            return $this->failure('插件升级失败', 500);
        }
    }

    /**
     * 所有接口都直接使用 CRMEB 自己的登录令牌解析服务，不自建账号体系。
     */
    private function authenticate()
    {
        $header = trim((string)request()->header('Authori-zation', ''));
        if ($header === '') {
            $header = trim((string)request()->header('Authorization', ''));
        }
        $token = stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : $header;
        if ($token === '') {
            throw new UpgradeException('请登录');
        }
        try {
            $service = app()->make(\app\services\system\admin\AdminAuthServices::class);
            $admin = $service->parseToken($token);
        } catch (\Throwable $e) {
            throw new UpgradeException('登录已过期，请重新登录');
        }
        if (!is_array($admin) || empty($admin['id']) || !array_key_exists('level', $admin)) {
            throw new UpgradeException('登录状态无效');
        }
        return $admin;
    }

    private function requireSuperAdmin(array $admin)
    {
        if ((int)$admin['level'] !== 0) {
            throw new UpgradeException('只有 CRMEB 超级管理员可以升级插件');
        }
    }

    private function requireSameOrigin()
    {
        $origin = (string)request()->header('Origin', '');
        $host = (string)request()->header('Host', '');
        if (!OriginGuard::isSameOrigin($origin, $host)) {
            throw new UpgradeException('升级请求必须来自当前 CRMEB 后台同源页面');
        }
    }

    private function validateUploadName($encodedName)
    {
        $name = rawurldecode(trim((string)$encodedName));
        if ($name === '' || strlen($name) > 255 || $name !== basename($name)
            || strpos($name, '/') !== false || strpos($name, '\\') !== false
            || strtolower(substr($name, -4)) !== '.zip') {
            throw new UpgradeException('请选择有效的 ZIP 升级包');
        }
    }

    private function services()
    {
        $root = rtrim((string)app()->getRootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $storage = new UpgradeStorage($root . 'runtime');
        $validator = new PackageValidator(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'upgrade'
            . DIRECTORY_SEPARATOR . 'release-public.pem');
        $manager = new UpgradeManager($root, $storage, $validator);
        $nonces = new NonceStore($storage->nonceDirectory());
        return [$manager, $storage, $nonces];
    }

    private function success(array $data)
    {
        return json(['status' => 1, 'data' => $data])->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function failure($message, $httpStatus)
    {
        return json(['status' => 0, 'msg' => (string)$message], (int)$httpStatus)->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function errorStatus($message)
    {
        $message = (string)$message;
        if (strpos($message, '登录') !== false) {
            return 401;
        }
        if (strpos($message, '超级管理员') !== false || strpos($message, '同源') !== false
            || strpos($message, 'nonce') !== false) {
            return 403;
        }
        if (strpos($message, '正在执行') !== false) {
            return 409;
        }
        return 422;
    }
}
