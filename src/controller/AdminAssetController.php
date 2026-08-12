<?php
// +----------------------------------------------------------------------
// | 百灵中枢 CRMEB 后台同源静态脚本
// | 只返回包内固定 JS，不读取配置、登录态或任何秘密。
// +----------------------------------------------------------------------
namespace app\bailing\controller;

use app\bailing\BailingService;

class AdminAssetController
{
    public function javascript()
    {
        $headers = array(
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        );
        try {
            $javascript = (string)BailingService::adminBundleJs();
            return response($javascript, 200, $headers);
        } catch (\Throwable $e) {
            return response('/* BailingHub admin bundle unavailable */', 500, $headers);
        }
    }
}
