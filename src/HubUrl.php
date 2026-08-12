<?php

namespace app\bailing;

use InvalidArgumentException;

/**
 * BailingHub 基础地址规范化。
 *
 * 自托管单实例可以使用站点根地址；托管多租户实例必须保留 /tenant/<tenantId>
 * 挂载前缀，否则 widget 后续请求无法确定应进入哪个租户内核。
 */
final class HubUrl
{
    /**
     * @param string $value 用户填写的中枢、控制台或 widget.js 地址
     * @return string 可直接拼接 /widget.js 的中枢基础地址
     */
    public static function normalize($value)
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
        $origin = $scheme . '://' . $hostForUrl;
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }

        $path = isset($parts['path']) ? $parts['path'] : '';
        $path = preg_replace('#/+$#', '', $path);

        // 用户可以直接复制聊天入口给出的 widget.js 地址。
        if (preg_match('#^(.*?)/widget\.js$#i', $path, $matches)) {
            $path = $matches[1];
        }
        // 也可以复制当前中枢控制台的任意页面地址。
        if (preg_match('#^(.*?)/console(?:/.*)?$#i', $path, $matches)) {
            $path = $matches[1];
        }
        $path = preg_replace('#/+$#', '', $path);

        if ($host === 'trial.bailinghub.com') {
            if (!preg_match('#^/tenant/[a-zA-Z0-9][a-zA-Z0-9_.:-]{1,63}$#', $path)) {
                throw new InvalidArgumentException(
                    'BailingHub 在线体验必须使用包含 /tenant/<租户ID> 的具体租户地址；请进入申请到的体验租户控制台复制聊天入口代码'
                );
            }
        }

        return $origin . $path;
    }
}
