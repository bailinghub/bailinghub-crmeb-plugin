<?php
namespace app\bailing\upgrade;

/**
 * 升级是代码写入操作，除管理员登录态外还必须验证浏览器同源。
 */
final class OriginGuard
{
    public static function isSameOrigin($origin, $host)
    {
        $origin = trim((string)$origin);
        $host = self::normalizeAuthority($host);
        if ($origin === '' || $host === '') {
            return false;
        }

        $parts = @parse_url($origin);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $originHost = strtolower(rtrim((string)$parts['host'], '.'));
        if (strpos($originHost, ':') !== false) {
            $originHost = '[' . trim($originHost, '[]') . ']';
        }
        $originAuthority = $originHost;
        if (isset($parts['port'])) {
            $originAuthority .= ':' . (int)$parts['port'];
        } elseif ($scheme === 'http') {
            $originAuthority .= ':80';
        } else {
            $originAuthority .= ':443';
        }

        $hostWithDefault = $host;
        if (!self::hasPort($hostWithDefault)) {
            $hostWithDefault .= $scheme === 'http' ? ':80' : ':443';
        }
        return hash_equals($originAuthority, $hostWithDefault);
    }

    private static function normalizeAuthority($authority)
    {
        $authority = strtolower(trim((string)$authority));
        if ($authority === '' || preg_match('/[\s\/@?#]/', $authority)) {
            return '';
        }
        if ($authority[0] === '[') {
            if (!preg_match('/^\[([0-9a-f:]+)\](?::([0-9]{1,5}))?$/i', $authority, $matches)) {
                return '';
            }
            if (isset($matches[2]) && ((int)$matches[2] < 1 || (int)$matches[2] > 65535)) {
                return '';
            }
            return '[' . strtolower($matches[1]) . ']' . (isset($matches[2]) ? ':' . (int)$matches[2] : '');
        }
        if (!preg_match('/^([a-z0-9.-]+)(?::([0-9]{1,5}))?$/', $authority, $matches)) {
            return '';
        }
        $name = rtrim($matches[1], '.');
        if ($name === '' || strpos($name, '..') !== false) {
            return '';
        }
        if (isset($matches[2]) && ((int)$matches[2] < 1 || (int)$matches[2] > 65535)) {
            return '';
        }
        return $name . (isset($matches[2]) ? ':' . (int)$matches[2] : '');
    }

    private static function hasPort($authority)
    {
        if (strlen($authority) > 0 && $authority[0] === '[') {
            return (bool)preg_match('/\]:[0-9]+$/', $authority);
        }
        return substr_count($authority, ':') === 1;
    }
}
