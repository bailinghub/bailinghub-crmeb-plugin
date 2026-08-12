<?php

namespace app\bailing;

use InvalidArgumentException;

/**
 * BailingHub 聊天入口嵌入代码解析与本地校验。
 *
 * 嵌入代码只是一种便于复制的配置载体。插件最终仍只保存规范化后的
 * hub base URL 与公开 entry key，不保存整段 HTML，也不从 entry key 猜测中枢地址。
 */
final class ChatEmbed
{
    const ENTRY_PATTERN = '/^[a-z0-9_-]{4,32}$/';

    /**
     * @param string $value 完整 <script> 嵌入代码，或裸 entry key
     * @param string $fallbackHub 裸 entry key 使用的既有中枢地址
     * @return array{hub_url:string,entry_key:string}
     */
    public static function parse($value, $fallbackHub = '')
    {
        $value = trim((string)$value);
        if ($value === '') {
            throw new InvalidArgumentException('请粘贴聊天入口的完整嵌入代码');
        }

        // 高级用户可只粘贴公开 entry key，但前提是已明确配置中枢地址。
        if (preg_match(self::ENTRY_PATTERN, $value)) {
            $fallbackHub = trim((string)$fallbackHub);
            if ($fallbackHub === '') {
                throw new InvalidArgumentException('只填写聊天入口 key 时，必须先填写下方的百灵中枢地址');
            }
            return array(
                'hub_url' => HubUrl::normalize($fallbackHub),
                'entry_key' => self::normalizeEntryKey($value),
            );
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/<script\b[^>]*>/i', $decoded, $tags);
        $candidates = array();
        foreach (isset($tags[0]) ? $tags[0] : array() as $tag) {
            $src = self::attribute($tag, 'src');
            $entry = self::attribute($tag, 'data-entry');
            if ($src === null && $entry === null) {
                continue;
            }
            // data-ticket 是短期登录票据，不属于可持久化配置；即使为空也拒绝，
            // 避免用户从运行时 DOM 复制后把真实票据暂存进 CRMEB 配置表。
            if (self::hasAttribute($tag, 'data-ticket')) {
                throw new InvalidArgumentException('嵌入代码不能包含临时 data-ticket；请从 BailingHub 控制台复制标准嵌入代码');
            }
            if ($src !== null && !self::isWidgetSource($src)) {
                if ($entry !== null) {
                    throw new InvalidArgumentException('嵌入代码的 src 必须指向 BailingHub 的 widget.js');
                }
                // 允许用户误把包含其他普通脚本的代码片段一起粘贴进来。
                continue;
            }
            if ($src === null || $entry === null) {
                throw new InvalidArgumentException('嵌入代码必须同时包含 src 和 data-entry');
            }
            $candidate = array(
                'hub_url' => HubUrl::normalize($src),
                'entry_key' => self::normalizeEntryKey($entry),
            );
            $key = $candidate['hub_url'] . "\n" . $candidate['entry_key'];
            $candidates[$key] = $candidate;
        }

        if (!$candidates) {
            throw new InvalidArgumentException('没有找到包含 src 和 data-entry 的 BailingHub <script> 嵌入代码');
        }
        if (count($candidates) > 1) {
            throw new InvalidArgumentException('检测到多个不同的聊天入口，请一次只粘贴一个嵌入代码');
        }

        return reset($candidates);
    }

    /**
     * entry key 是页面源码可见的公开标识，但仍需严格限制为 widget 支持的格式。
     */
    public static function normalizeEntryKey($value)
    {
        $value = trim((string)$value);
        if (!preg_match(self::ENTRY_PATTERN, $value)) {
            throw new InvalidArgumentException('聊天入口 key 格式不正确，应为 4-32 位小写字母、数字、下划线或连字符（如 pub_xxx）');
        }
        return $value;
    }

    private static function attribute($tag, $name)
    {
        $name = preg_quote($name, '/');
        $pattern = '/(?:^|\s)' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i';
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

    private static function hasAttribute($tag, $name)
    {
        $name = preg_quote($name, '/');
        return (bool)preg_match('/(?:^|\s)' . $name . '(?:\s*=|\s|>)/i', $tag);
    }

    private static function isWidgetSource($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return false;
        }
        $probe = preg_match('#^https?://#i', $value) ? $value : 'https://' . $value;
        $parts = parse_url($probe);
        $path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '';
        return (bool)preg_match('#/widget\.js$#i', $path);
    }
}
