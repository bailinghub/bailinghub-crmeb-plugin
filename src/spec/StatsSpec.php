<?php
// +----------------------------------------------------------------------
// | 统计域能力声明（元能力：单一统计视图的只读查询）
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class StatsSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'stats_overview' => '/bailing/tools/stats/overview',
            'stats_trade_trend' => '/bailing/tools/stats/trade_trend',
            'stats_product_rank' => '/bailing/tools/stats/product_rank',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('stats_overview', 'GET', $p['stats_overview'], 'stats.read', '经营数据总览',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询今日/累计经营概况（销售额、订单量、用户数、商品数）时使用')
                  ->returns('{code:1,data:{today:{sales,orders,new_users},total:{sales,orders,users,products}}}')
                  ->examples([[]]);
            });

        $spec->tool('stats_trade_trend', 'GET', $p['stats_trade_trend'], 'stats.read', '营业额趋势',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询最近N天每日营业额和订单量趋势时使用')
                  ->returns('{code:1,data:{list:[{date,sales,orders}]}}')
                  ->examples([['days' => 7]]);
                $t->query('days', 'integer', false, '最近天数，默认7，最大30');
            });

        $spec->tool('stats_product_rank', 'GET', $p['stats_product_rank'], 'stats.read', '商品销量排行',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询商品销量排行榜（热销商品TOP）时使用')
                  ->returns('{code:1,data:{list:[{id,store_name,sales,price,stock}]}}')
                  ->examples([['limit' => 10]]);
                $t->query('limit', 'integer', false, '返回数量，默认10，最大50');
            });
    }
}
