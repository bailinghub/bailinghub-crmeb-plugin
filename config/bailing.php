<?php
// +----------------------------------------------------------------------
// | 百灵中枢（BailingHub）接入配置
// | 安装本包后，后台「系统设置 → 百灵中枢配置」可填写以下值
// | 这里只承载非秘密聊天入口信息；token/签名密钥位于 runtime 插件私密存储。
// +----------------------------------------------------------------------

// 注意：TP 配置加载时机极早，sys_config() 助手可能尚未注册，必须做存在性回退，
// 否则全站 500（helper 未加载时调用未定义函数是 fatal）
$__bailing_sys = function ($name, $default = '') {
    if (function_exists('sys_config')) {
        try {
            $v = sys_config($name, $default);
            return $v === '' ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }
    return $default;
};

return [
    // BailingHub 中枢控制台地址
    'hub_url' => $__bailing_sys('bailing_hub_url', ''),

    // 聊天入口 key（BailingHub 控制台「聊天入口」的 entry key，用于后台嵌入聊天组件）
    'chat_entry' => $__bailing_sys('bailing_chat_entry', ''),
];
