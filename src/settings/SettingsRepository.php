<?php

namespace app\bailing\settings;

use think\facade\Db;

/**
 * CRMEB 非秘密配置与插件私密存储之间的提交边界。
 *
 * hub/entry 继续使用 CRMEB 原生 system_config；token/密钥只进入 SecretStore。
 */
final class SettingsRepository
{
    const HUB_URL = 'bailing_hub_url';
    const CHAT_ENTRY = 'bailing_chat_entry';
    const ACCESS_TOKEN = SecretStore::ACCESS_TOKEN;
    const SIGN_SECRET = SecretStore::SIGN_SECRET;
    const EMBED_CODE = 'bailing_embed_code';
    const LEGACY_ROUTE = 'bailing_route';
    const LEGACY_ACCESS_TOKEN = 'bailing_access_token';
    const LEGACY_SIGN_SECRET = 'bailing_sign_secret';

    private $secretStore;

    public function __construct($secretStore = null)
    {
        $this->secretStore = $secretStore === null ? new SecretStore() : $secretStore;
    }

    public function status()
    {
        $secretStatus = $this->secretStore->status();
        return self::summarize(array(
            self::HUB_URL => $this->readValue(self::HUB_URL),
            self::CHAT_ENTRY => $this->readValue(self::CHAT_ENTRY),
            self::ACCESS_TOKEN => !empty($secretStatus['access_token_configured']),
            self::SIGN_SECRET => !empty($secretStatus['sign_secret_configured']),
        ));
    }

    /**
     * 纯摘要函数便于测试：无论输入中有什么秘密，输出都没有对应明文字段和值。
     */
    public static function summarize(array $values)
    {
        $hubUrl = isset($values[self::HUB_URL]) && is_string($values[self::HUB_URL])
            ? $values[self::HUB_URL] : '';
        $entryKey = isset($values[self::CHAT_ENTRY]) && is_string($values[self::CHAT_ENTRY])
            ? $values[self::CHAT_ENTRY] : '';
        $accessToken = isset($values[self::ACCESS_TOKEN]) ? $values[self::ACCESS_TOKEN] : false;
        $signSecret = isset($values[self::SIGN_SECRET]) ? $values[self::SIGN_SECRET] : false;

        return array(
            'hub_url' => $hubUrl,
            'entry_key' => $entryKey,
            'access_token_configured' => is_string($accessToken) ? trim($accessToken) !== '' : $accessToken === true,
            'sign_secret_configured' => is_string($signSecret) ? trim($signSecret) !== '' : $signSecret === true,
        );
    }

    /**
     * null 表示保持原值。SecretStore 独占锁覆盖秘密原子写和数据库事务；数据库
     * 失败会先恢复旧秘密再释放锁，成功响应则来自重新读取的实际状态摘要。
     */
    public function save(array $changes)
    {
        $secretStatus = $this->secretStore->update(array(
            self::ACCESS_TOKEN => array_key_exists('access_token', $changes) ? $changes['access_token'] : null,
            self::SIGN_SECRET => array_key_exists('sign_secret', $changes) ? $changes['sign_secret'] : null,
        ), function () use ($changes) {
            Db::transaction(function () use ($changes) {
                if (isset($changes['chat']) && is_array($changes['chat'])) {
                    $this->updateRequired(self::HUB_URL, (string)$changes['chat']['hub_url']);
                    $this->updateRequired(self::CHAT_ENTRY, (string)$changes['chat']['entry_key']);
                }

                // 专用接口从不持久化原始 embed；同时清除旧版 generic form 的待处理值。
                $embed = Db::name('system_config')->where('menu_name', self::EMBED_CODE)->find();
                if ($embed) {
                    Db::name('system_config')->where('menu_name', self::EMBED_CODE)->update(array(
                        'value' => self::encodeValue(''),
                    ));
                }

                // 旧版秘密不迁移。任何 generic API 都不应再能按名称读取这些行。
                foreach (array(self::LEGACY_ACCESS_TOKEN, self::LEGACY_SIGN_SECRET, self::LEGACY_ROUTE) as $legacy) {
                    Db::name('system_config')->where('menu_name', $legacy)->delete();
                }
            });
        });

        foreach (array(
            self::HUB_URL,
            self::CHAT_ENTRY,
            self::LEGACY_ACCESS_TOKEN,
            self::LEGACY_SIGN_SECRET,
            self::EMBED_CODE,
            self::LEGACY_ROUTE,
        ) as $name) {
            $this->clearConfigCache($name);
        }

        return self::summarize(array(
            self::HUB_URL => $this->readValue(self::HUB_URL),
            self::CHAT_ENTRY => $this->readValue(self::CHAT_ENTRY),
            self::ACCESS_TOKEN => !empty($secretStatus['access_token_configured']),
            self::SIGN_SECRET => !empty($secretStatus['sign_secret_configured']),
        ));
    }

    private function readValue($menuName)
    {
        $row = Db::name('system_config')->where('menu_name', $menuName)->find();
        if (!$row || !array_key_exists('value', $row)) {
            return '';
        }
        $decoded = json_decode((string)$row['value'], true);
        return is_string($decoded) ? $decoded : '';
    }

    private function updateRequired($menuName, $value, $status = null)
    {
        $row = Db::name('system_config')->where('menu_name', $menuName)->find();
        if (!$row) {
            throw new SettingsException('百灵中枢配置项尚未初始化，请刷新后台后重试', 409);
        }
        $update = array('value' => self::encodeValue($value));
        if ($status !== null) {
            $update['status'] = (int)$status;
        }
        Db::name('system_config')->where('menu_name', $menuName)->update($update);
    }

    private static function encodeValue($value)
    {
        $encoded = json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new SettingsException('配置值编码失败', 500);
        }
        return $encoded;
    }

    private function clearConfigCache($menuName)
    {
        if (!class_exists('\crmeb\services\CacheService')) {
            return;
        }
        try {
            \crmeb\services\CacheService::delete('system_config_' . $menuName);
        } catch (\Throwable $e) {
            // 数据库事务已经提交；缓存失败不回滚成功事实，下一轮自然过期仍会收敛。
        }
    }
}
