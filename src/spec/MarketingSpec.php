<?php
// +----------------------------------------------------------------------
// | 营销域能力声明（元能力：单资源单动作）
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class MarketingSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'coupon_issue_list' => '/bailing/tools/coupon/issue_list',
            'coupon_grant' => '/bailing/tools/coupon/grant',
            'seckill_list' => '/bailing/tools/seckill/list',
            'combination_list' => '/bailing/tools/combination/list',
            'bargain_list' => '/bailing/tools/bargain/list',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('coupon_issue_list', 'GET', $p['coupon_issue_list'], 'marketing.read', '查询已发布优惠券',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询已发布的优惠券、剩余数量、使用门槛时使用')
                  ->returns('{code:1,data:{list:[{id,title,coupon_price,use_min_price,total_count,remain_count,status,end_time}],total}}')
                  ->examples([['status' => 1]]);
                $t->query('status', 'integer', false, '状态：1=进行中 0=已关闭');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('coupon_grant', 'POST', $p['coupon_grant'], 'marketing.coupon', '定向发放优惠券给用户',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('把某张优惠券直接发给指定用户（补偿/活动奖励场景）时使用。优惠券ID用 coupon_issue_list 查询')
                  ->returns('{code:1,data:{coupon_id,uid,remain_count}}')
                  ->examples([['coupon_id' => 1, 'uid' => 2]]);
                $t->body('coupon_id', 'integer', true, '优惠券发布ID');
                $t->body('uid', 'integer', true, '目标用户UID');
                $t->body('num', 'integer', false, '发放张数，默认1，最大10');
            });

        $spec->tool('seckill_list', 'GET', $p['seckill_list'], 'marketing.read', '查询秒杀活动',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询秒杀活动商品、秒杀价、库存、活动状态时使用')
                  ->returns('{code:1,data:{list:[{id,title,price,ot_price,stock,status,start_time,stop_time}],total}}')
                  ->examples([['status' => 1]]);
                $t->query('status', 'integer', false, '状态：1=进行中 0=已关闭');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('combination_list', 'GET', $p['combination_list'], 'marketing.read', '查询拼团活动',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询拼团活动商品、拼团价、成团人数时使用')
                  ->returns('{code:1,data:{list:[{id,title,price,people,stock,status,start_time,stop_time}],total}}')
                  ->examples([['status' => 1]]);
                $t->query('status', 'integer', false, '状态：1=进行中 0=已关闭');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('bargain_list', 'GET', $p['bargain_list'], 'marketing.read', '查询砍价活动',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询砍价活动商品、底价、参与情况时使用')
                  ->returns('{code:1,data:{list:[{id,title,price,min_price,stock,status,start_time,stop_time}],total}}')
                  ->examples([['status' => 1]]);
                $t->query('status', 'integer', false, '状态：1=进行中 0=已关闭');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });
    }
}
