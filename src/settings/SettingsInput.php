<?php

namespace app\bailing\settings;

use app\bailing\ChatEmbed;
use app\bailing\HubUrl;

/**
 * 专用配置接口的纯输入校验。
 *
 * 返回值使用 null 表示“本次保持原值”，从而避免用空字符串误清除现有密钥。
 */
final class SettingsInput
{
    const MAX_EMBED_BYTES = 32768;
    const MAX_URL_BYTES = 2048;
    const MAX_SECRET_BYTES = 512;
    const MIN_SECRET_BYTES = 16;

    private static $allowedKeys = array(
        'nonce',
        'embed_code',
        'hub_url',
        'entry_key',
        'access_token',
        'sign_secret',
    );

    /**
     * 三种部署来源在此统一为相同的底层配置，不接收也不保存来源类型。
     *
     * @return array{chat:?array,access_token:?string,sign_secret:?string}
     */
    public static function normalize(array $payload)
    {
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !in_array($key, self::$allowedKeys, true)) {
                throw new SettingsException('配置请求包含未支持的字段');
            }
            if (!is_string($value)) {
                throw new SettingsException('配置字段必须是字符串');
            }
        }

        $embed = self::field($payload, 'embed_code');
        $hub = self::field($payload, 'hub_url');
        $entry = self::field($payload, 'entry_key');
        $chat = null;

        if ($embed !== '') {
            if ($hub !== '' || $entry !== '') {
                throw new SettingsException('完整嵌入代码与高级地址配置只能选择一种');
            }
            if (strlen($embed) > self::MAX_EMBED_BYTES) {
                throw new SettingsException('聊天入口嵌入代码过长');
            }
            $decoded = html_entity_decode($embed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/<script\b/i', $decoded)) {
                throw new SettingsException('请粘贴包含 script 标签的完整聊天入口嵌入代码');
            }
            try {
                // 完整代码自身必须给出租户地址；不允许悄悄借用旧地址。
                $chat = ChatEmbed::parse($embed, '');
            } catch (\InvalidArgumentException $e) {
                throw new SettingsException($e->getMessage());
            }
        } elseif ($hub !== '' || $entry !== '') {
            if ($hub === '' || $entry === '') {
                throw new SettingsException('高级配置必须同时填写中枢地址和聊天入口 key');
            }
            if (strlen($hub) > self::MAX_URL_BYTES) {
                throw new SettingsException('百灵中枢地址过长');
            }
            try {
                $chat = array(
                    'hub_url' => HubUrl::normalize($hub),
                    'entry_key' => ChatEmbed::normalizeEntryKey($entry),
                );
            } catch (\InvalidArgumentException $e) {
                throw new SettingsException($e->getMessage());
            }
        }

        $accessToken = self::secret($payload, 'access_token', '接入方 token');
        $signSecret = self::secret($payload, 'sign_secret', '工具源签名密钥');
        if ($chat === null && $accessToken === null && $signSecret === null) {
            throw new SettingsException('没有需要保存的配置变更');
        }

        return array(
            'chat' => $chat,
            'access_token' => $accessToken,
            'sign_secret' => $signSecret,
        );
    }

    private static function field(array $payload, $name)
    {
        return isset($payload[$name]) ? trim($payload[$name]) : '';
    }

    /** 空字符串表示保留；非空替换值必须是可安全放入 HTTP 头/签名材料的单行值。 */
    private static function secret(array $payload, $name, $label)
    {
        $value = self::field($payload, $name);
        if ($value === '') {
            return null;
        }
        $length = strlen($value);
        if ($length < self::MIN_SECRET_BYTES || $length > self::MAX_SECRET_BYTES) {
            throw new SettingsException($label . '长度必须为 16-512 字节');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new SettingsException($label . '不能包含空白或控制字符');
        }
        return $value;
    }
}
