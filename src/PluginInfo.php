<?php
// +----------------------------------------------------------------------
// | CRMEB 百灵中枢独立适配器版本信息
// +----------------------------------------------------------------------
namespace app\bailing;

/**
 * 插件包版本、工具契约版本和配置结构版本必须彼此独立。
 *
 * PLUGIN_VERSION 只描述当前运行的适配器代码；
 * TOOL_SPEC_VERSION 只描述 tools.json 的能力集合；
 * CONFIG_SCHEMA_VERSION 只描述插件自有配置/升级状态结构。
 */
final class PluginInfo
{
    const PACKAGE_NAME = 'crmeb-bailinghub';
    const PACKAGE_FORMAT_VERSION = 1;
    const PLUGIN_VERSION = '2.4.1';
    const TOOL_SPEC_VERSION = '2.3.0';
    const CONFIG_SCHEMA_VERSION = 2;
    const CRMEB_EDITION = 'CRMEB-KY-v6';
    const BAILINGHUB_MIN_VERSION = '0.2.0';

    public static function current()
    {
        return [
            'adapter_version' => self::PLUGIN_VERSION,
            'tool_spec_version' => self::TOOL_SPEC_VERSION,
            'config_schema_version' => self::CONFIG_SCHEMA_VERSION,
        ];
    }
}
