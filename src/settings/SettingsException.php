<?php

namespace app\bailing\settings;

/**
 * 可安全返回给后台管理员的配置错误。
 *
 * message 只能描述字段或操作，绝不能拼接 token、密钥或原始请求内容。
 */
final class SettingsException extends \RuntimeException
{
    private $httpStatus;

    public function __construct($message, $httpStatus = 422)
    {
        parent::__construct((string)$message);
        $this->httpStatus = (int)$httpStatus;
    }

    public function httpStatus()
    {
        return $this->httpStatus;
    }
}
