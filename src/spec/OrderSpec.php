<?php
// +----------------------------------------------------------------------
// | 订单域能力声明（元能力：单资源单动作）
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class OrderSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'order_list' => '/bailing/tools/order/list',
            'order_detail' => '/bailing/tools/order/detail',
            'order_status_stats' => '/bailing/tools/order/status_stats',
            'order_delivery' => '/bailing/tools/order/delivery',
            'order_remark' => '/bailing/tools/order/remark',
            'order_take_delivery' => '/bailing/tools/order/take_delivery',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('order_list', 'GET', $p['order_list'], 'order.read', '查询订单列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询订单、订单列表、按状态/订单号/用户查订单时使用')
                  ->returns('{code:1,data:{list:[{id,order_id,real_name,user_phone,pay_price,total_num,paid,status,pay_type,add_time}],total}}')
                  ->examples([['status' => 0, 'paid' => 1], ['order_id' => 'wx202608011234567890']]);
                $t->query('order_id', 'string', false, '订单编号（精确）');
                $t->query('real_name', 'string', false, '收货人姓名（模糊）');
                $t->query('paid', 'integer', false, '支付状态：1=已支付 0=未支付');
                $t->query('status', 'integer', false, '订单状态：0=待发货 1=待收货 2=待评价 3=已完成 4=待核销');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10，最大50');
            });

        $spec->tool('order_detail', 'GET', $p['order_detail'], 'order.read', '查询订单详情',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查看某个订单的完整信息（商品明细/收货地址/支付/物流/备注）时使用')
                  ->returns('{code:1,data:{order:{...},cart_info:[{product_id,store_name,price,cart_num}]}}')
                  ->examples([['id' => 1], ['order_id' => 'wx202608011234567890']]);
                $t->query('id', 'integer', false, '订单ID（与 order_id 二选一）');
                $t->query('order_id', 'string', false, '订单编号');
            });

        $spec->tool('order_status_stats', 'GET', $p['order_status_stats'], 'order.read', '订单状态统计',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询各状态订单数量（待支付/待发货/待收货/待评价/退款中等经营概览）时使用')
                  ->returns('{code:1,data:{unpaid,paid_unshipped,shipped,received,finished,refunding,total}}')
                  ->examples([[]]);
            });

        $spec->tool('order_delivery', 'POST', $p['order_delivery'], 'order.delivery', '订单发货',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('为已支付待发货的订单填写物流单号并发货时使用。物流公司名称可用 express_list 查询')
                  ->returns('{code:1,data:{id,status,delivery_name,delivery_id}}')
                  ->examples([['id' => 1, 'delivery_name' => '顺丰速运', 'delivery_id' => 'SF1234567890']]);
                $t->body('id', 'integer', true, '订单ID');
                $t->body('delivery_name', 'string', true, '物流公司名称（如 顺丰速运）');
                $t->body('delivery_id', 'string', true, '物流单号');
            });

        $spec->tool('order_remark', 'POST', $p['order_remark'], 'order.update', '修改订单备注',
            function (ToolDef $t) {
                $t->risk('low')->idempotent()->requiresSubject()
                  ->whenToUse('给订单添加/修改商家备注时使用')
                  ->returns('{code:1,data:{id,remark}}')
                  ->examples([['id' => 1, 'remark' => '客户要求周末配送']]);
                $t->body('id', 'integer', true, '订单ID');
                $t->body('remark', 'string', true, '备注内容');
            });

        $spec->tool('order_take_delivery', 'POST', $p['order_take_delivery'], 'order.update', '订单确认收货',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('用户已线下收到货，需要商家代确认收货时使用')
                  ->returns('{code:1,data:{id,status}}')
                  ->examples([['id' => 1]]);
                $t->body('id', 'integer', true, '订单ID');
            });
    }
}
