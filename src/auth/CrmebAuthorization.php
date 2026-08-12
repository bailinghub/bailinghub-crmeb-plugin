<?php
// +----------------------------------------------------------------------
// | CRMEB 原生权限适配器
// |
// | 边界：
// | - 票据 / X-Bailing-On-Behalf-Of 只传递 CRMEB 管理员主体；
// | - 本类只把 BailingHub 工具映射到等价的 CRMEB 原生菜单、按钮或 API；
// | - 账号、角色、角色状态和权限规则始终实时读取 CRMEB 原表；
// | - 不在中枢或插件中保存第二套角色、权限快照或审批策略。
// +----------------------------------------------------------------------
namespace app\bailing\auth;

use think\facade\Db;

class CrmebAuthorization
{
    /** @var string */
    protected $lastError = '';

    /**
     * AI 工具与 CRMEB 原生权限点的语义映射。
     *
     * menu：CRMEB system_menus.unique_auth（后台登录时返回给前端的同一权限标识）。
     * api：CRMEB system_menus 中 auth_type=2 的原生接口权限。
     * any：满足其中任意一个原生权限点即可。
     *
     * 这里没有角色或账号数据；它只回答“这个工具等价于后台哪个操作”。
     */
    protected static $toolPermissions = [
        // 商品
        'product_list' => ['menu' => ['admin-store-storeProuduct-index']],
        'product_detail' => ['menu' => ['admin-store-storeProuduct-index']],
        'product_category_tree' => ['any' => [
            ['menu' => ['admin-store-storeProuduct-index']],
            ['menu' => ['admin-store-storeCategory-index']],
        ]],
        'product_create' => ['api' => ['POST', 'product/product/<id>']],
        'product_set_show' => ['api' => ['PUT', 'product/product/set_show/<id>/<is_show>']],
        'product_update_stock' => ['api' => ['PUT', 'product/product/set_product/<id>']],
        'product_update_price' => ['api' => ['PUT', 'product/product/set_product/<id>']],
        'product_reply_list' => ['menu' => ['product-product-reply']],
        // CRMEB 当前版本未给评论审核单独登记 API 权限，后台也按评论页权限展示该操作。
        'product_reply_audit' => ['menu' => ['product-product-reply']],
        'product_reply_answer' => ['api' => ['PUT', 'product/reply/set_reply/<id>']],

        // 订单
        'order_list' => ['menu' => ['admin-order-storeOrder-index']],
        'order_detail' => ['menu' => ['admin-order-storeOrder-index']],
        'order_status_stats' => ['menu' => ['admin-order-storeOrder-index']],
        'order_delivery' => ['api' => ['PUT', 'order/delivery/<id>']],
        'order_remark' => ['api' => ['PUT', 'order/remark/<id>']],
        'order_take_delivery' => ['api' => ['PUT', 'order/take/<id>']],

        // 售后
        'refund_list' => ['menu' => ['admin-order-refund']],
        'refund_agree' => ['api' => ['GET', 'refund/agree/<id>']],
        'refund_refuse' => ['api' => ['PUT', 'refund/no_refund/<id>']],

        // 用户
        'user_list' => ['menu' => ['admin-user-user-index']],
        'user_detail' => ['menu' => ['admin-user-user-index']],
        // CRMEB 当前版本未把 user/set_status 登记到 system_menus，沿用用户管理页权限。
        'user_set_status' => ['menu' => ['admin-user-user-index']],
        'user_level_list' => ['menu' => ['user-user-level']],

        // 营销
        'coupon_issue_list' => ['menu' => ['marketing-store_coupon_issue']],
        'coupon_grant' => ['api' => ['POST', 'marketing/coupon/user/grant']],
        'seckill_list' => ['menu' => ['marketing-store_seckill']],
        'combination_list' => ['menu' => ['marketing-store_combination']],
        'bargain_list' => ['menu' => ['marketing-store_bargain']],

        // 财务
        'extract_list' => ['menu' => ['finance-user_extract']],
        'extract_refuse' => ['api' => ['PUT', 'finance/extract/refuse/<id>']],
        'balance_bill_list' => ['menu' => ['finance-user-balance']],

        // 统计：CRMEB 首页本身向该管理员展示经营概况；排名同时接受商品管理页权限。
        'stats_overview' => ['menu' => ['admin-home']],
        'stats_trade_trend' => ['any' => [
            ['menu' => ['admin-home']],
            ['menu' => ['admin-order-storeOrder-index']],
        ]],
        'stats_product_rank' => ['any' => [
            ['menu' => ['admin-home']],
            ['menu' => ['admin-store-storeProuduct-index']],
        ]],

        // 分销、门店、物流基础数据
        'agent_list' => ['menu' => ['agent-agent-manage']],
        'store_list' => ['menu' => ['setting-merchant-system-store']],
        'express_list' => ['menu' => ['admin-order-storeOrder-index']],
    ];

    /**
     * 返回是否允许执行。每次调用都实时读取 CRMEB 原生账号、角色和权限表。
     */
    public function allows($subject, $tool, array $params = [])
    {
        $decision = $this->decide($subject, $tool, $params);
        return $decision['allowed'];
    }

    /**
     * 返回可审计的裁决结果；不返回角色规则明细，避免把权限结构泄露给调用方。
     */
    public function decide($subject, $tool, array $params = [])
    {
        $this->lastError = '';
        $adminId = filter_var($subject, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($adminId === false) {
            return $this->deny('CRMEB 登录主体无效');
        }

        if (!isset(self::$toolPermissions[$tool])) {
            return $this->deny('工具没有对应的 CRMEB 原生权限点');
        }

        $admin = Db::name('system_admin')
            ->where('id', (int)$adminId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->field('id,level,roles')
            ->find();
        if (empty($admin)) {
            return $this->deny('CRMEB 账号不存在、已禁用或已删除');
        }

        // CRMEB 原生语义：level=0 为超级管理员，不受角色规则限制。
        if ((int)$admin['level'] === 0) {
            return ['allowed' => true, 'reason' => 'crmeb_super_admin'];
        }

        $roleIds = $this->integerList($admin['roles']);
        if (empty($roleIds)) {
            return $this->deny('CRMEB 账号没有有效角色');
        }

        // 只采用 CRMEB 当前启用角色的实时 rules；不读票据权限、不用插件缓存。
        $ruleStrings = Db::name('system_role')
            ->whereIn('id', $roleIds)
            ->where('status', 1)
            ->column('rules');
        $menuIds = [];
        foreach ($ruleStrings as $rules) {
            $menuIds = array_merge($menuIds, $this->integerList($rules));
        }
        $menuIds = array_values(array_unique($menuIds));
        if (empty($menuIds)) {
            return $this->deny('CRMEB 角色没有有效权限');
        }

        $rows = Db::name('system_menus')
            ->whereIn('id', $menuIds)
            ->where('is_del', 0)
            ->where('is_show', 1)
            ->field('auth_type,methods,api_url,unique_auth')
            ->select()
            ->toArray();

        $grants = ['menus' => [], 'apis' => []];
        foreach ($rows as $row) {
            $unique = trim((string)($row['unique_auth'] ?? ''));
            if ($unique !== '') {
                $grants['menus'][strtolower($unique)] = true;
            }
            if ((int)($row['auth_type'] ?? 0) === 2) {
                $method = strtoupper(trim((string)($row['methods'] ?? '')));
                $api = $this->normalizeApi((string)($row['api_url'] ?? ''));
                if ($method !== '' && $api !== '') {
                    $grants['apis'][$method . ' ' . $api] = true;
                }
            }
        }

        if ($this->matches(self::$toolPermissions[$tool], $grants)) {
            return ['allowed' => true, 'reason' => 'crmeb_role_permission'];
        }
        return $this->deny('CRMEB 账号没有该操作权限');
    }

    public function lastError()
    {
        return $this->lastError;
    }

    /** 用于安装检查和自动化测试：返回工具名列表，不暴露账号权限。 */
    public static function toolNames()
    {
        return array_keys(self::$toolPermissions);
    }

    protected function matches(array $requirement, array $grants)
    {
        if (isset($requirement['any'])) {
            foreach ($requirement['any'] as $item) {
                if ($this->matches($item, $grants)) {
                    return true;
                }
            }
            return false;
        }

        if (isset($requirement['menu'])) {
            foreach ((array)$requirement['menu'] as $unique) {
                if (isset($grants['menus'][strtolower(trim((string)$unique))])) {
                    return true;
                }
            }
            return false;
        }

        if (isset($requirement['api']) && count($requirement['api']) === 2) {
            $key = strtoupper(trim((string)$requirement['api'][0])) . ' '
                . $this->normalizeApi((string)$requirement['api'][1]);
            return isset($grants['apis'][$key]);
        }

        return false;
    }

    protected function integerList($value)
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', trim((string)$value));
        }
        $ids = [];
        foreach ($parts as $part) {
            $id = (int)$part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    protected function normalizeApi($api)
    {
        return strtolower(str_replace(' ', '', trim((string)$api, " \t\n\r\0\x0B/")));
    }

    protected function deny($message)
    {
        $this->lastError = $message;
        return ['allowed' => false, 'reason' => $message];
    }
}
