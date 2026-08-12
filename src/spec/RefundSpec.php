<?php
// +----------------------------------------------------------------------
// | 售后域能力声明（元能力：状态流转类，不含资金打款——打款走原支付通道由后台人工执行）
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class RefundSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'refund_list' => '/bailing/tools/refund/list',
            'refund_agree' => '/bailing/tools/refund/agree',
            'refund_refuse' => '/bailing/tools/refund/refuse',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('refund_list', 'GET', $p['refund_list'], 'refund.read', '查询售后/退款申请列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询退款/退货申请、待处理售后单时使用')
                  ->returns('{code:1,data:{list:[{id,order_id,refund_type,refund_price,refund_reason,refuse_reason,add_time}],total}}')
                  ->examples([['refund_type' => 1]]);
                $t->query('refund_type', 'integer', false, '售后状态：1=申请中 2=已同意待退货 3=已拒绝 6=已退款完成，不传查全部');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('refund_agree', 'POST', $p['refund_agree'], 'refund.audit', '同意售后申请',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('审核通过用户的退款/退货申请时使用。注意：实际资金原路退回需在后台「订单退款」人工执行，本工具只做状态流转')
                  ->returns('{code:1,data:{id,refund_type}}')
                  ->examples([['id' => 1]]);
                $t->body('id', 'integer', true, '售后单ID');
            });

        $spec->tool('refund_refuse', 'POST', $p['refund_refuse'], 'refund.audit', '拒绝售后申请',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('驳回用户的退款/退货申请时使用，必须填写拒绝理由')
                  ->returns('{code:1,data:{id,refund_type,refuse_reason}}')
                  ->examples([['id' => 1, 'refuse_reason' => '商品已影响二次销售']]);
                $t->body('id', 'integer', true, '售后单ID');
                $t->body('refuse_reason', 'string', true, '拒绝理由');
            });
    }
}
