<?php
// +----------------------------------------------------------------------
// | 门店/分销/基础数据域能力声明
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class StoreSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'agent_list' => '/bailing/tools/agent/list',
            'store_list' => '/bailing/tools/store/list',
            'express_list' => '/bailing/tools/express/list',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('agent_list', 'GET', $p['agent_list'], 'agent.read', '查询分销员列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询推广员/分销员列表及其佣金时使用')
                  ->returns('{code:1,data:{list:[{uid,nickname,brokerage_price,spread_count,pay_count,add_time}],total}}')
                  ->examples([['page' => 1, 'limit' => 10]]);
                $t->query('keyword', 'string', false, '昵称/UID（模糊）');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('store_list', 'GET', $p['store_list'], 'store.read', '查询门店列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询线下门店（自提点/核销点）列表时使用')
                  ->returns('{code:1,data:{list:[{id,name,phone,address,is_show}],total}}')
                  ->examples([[]]);
            });

        $spec->tool('express_list', 'GET', $p['express_list'], 'store.read', '查询物流公司列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('发货前查询可用的物流公司名称时使用（order_delivery 的 delivery_name 需与此处一致）')
                  ->returns('{code:1,data:{list:[{id,name,code}]}}')
                  ->examples([[]]);
            });
    }
}
