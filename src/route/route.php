<?php
// +----------------------------------------------------------------------
// | 百灵中枢（BailingHub）工具源路由
// | 本文件位于 app/bailing/route/，think-multi-app 剥离 bailing 后加载
// +----------------------------------------------------------------------
use think\facade\Route;

// 工具源 spec（中枢配置 spec_url 指向此地址，访问策略固定 signed_required）
Route::get('tools.json', 'BailingController/tools');
// 授权探针（中枢用不存在的主体探测，业务侧默认拒绝）
Route::post('authz-probe', 'BailingController/authzProbe');
// 工具执行端点（验签 + 权限裁决 + 分发）
Route::any('tools/:cat/:action', 'BailingController/dispatch');
// 聊天组件登录票据（CRMEB 后台管理员凭 Authori-zation 换取百灵访客票据）
Route::any('chat-ticket', 'BailingController/chatTicket');

// 后台配置增强脚本不含配置或秘密，可公开同源加载；配置读写使用下方专用安全接口。
// 使用无扩展名动态入口，避免被宝塔/常见 Nginx 的 .js 静态资源规则截获。
Route::get('admin-bundle', 'AdminAssetController/javascript');
Route::get('settings/status', 'BailingSettingsController/status');
Route::post('settings/save', 'BailingSettingsController/save');

// 独立适配器离线升级：状态需登录；暂存/应用还需超级管理员、同源和一次性 nonce。
// 不复用 CRMEB 官方整站在线升级器，所有写入严格限制在本插件目录。
Route::get('plugin-upgrade/status', 'PluginUpgradeController/status');
Route::post('plugin-upgrade/stage', 'PluginUpgradeController/stage');
Route::post('plugin-upgrade/apply', 'PluginUpgradeController/apply');
