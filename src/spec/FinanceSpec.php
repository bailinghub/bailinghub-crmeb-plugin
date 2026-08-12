<?php
// +----------------------------------------------------------------------
// | 财务域能力声明（元能力：查询 + 状态流转；提现通过打款走支付通道由后台人工执行）
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class FinanceSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'extract_list' => '/bailing/tools/extract/list',
            'extract_refuse' => '/bailing/tools/extract/refuse',
            'balance_bill_list' => '/bailing/tools/finance/balance_bill',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('extract_list', 'GET', $p['extract_list'], 'finance.read', '查询提现申请列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询用户提现申请、待审核提现单时使用')
                  ->returns('{code:1,data:{list:[{id,uid,real_name,extract_type,extract_price,status,add_time}],total}}')
                  ->examples([['status' => 0]]);
                $t->query('status', 'integer', false, '状态：0=待审核 1=已通过 -1=已拒绝，不传查全部');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('extract_refuse', 'POST', $p['extract_refuse'], 'finance.audit', '拒绝提现申请',
            function (ToolDef $t) {
                $t->risk('medium')->sensitive()->requiresSubject()
                  ->whenToUse('驳回用户提现申请时使用（金额自动退回用户账户余额）。通过提现涉及资金打款通道，请在后台人工操作')
                  ->returns('{code:1,data:{id,status}}')
                  ->examples([['id' => 1, 'fail_msg' => '收款信息有误']]);
                $t->body('id', 'integer', true, '提现申请ID');
                $t->body('fail_msg', 'string', true, '拒绝原因（展示给用户）');
            });

        $spec->tool('balance_bill_list', 'GET', $p['balance_bill_list'], 'finance.read', '查询用户资金流水',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询用户余额/佣金/积分变动记录（资金流水）时使用')
                  ->returns('{code:1,data:{list:[{id,uid,title,category,pm,number,balance,add_time}],total}}')
                  ->examples([['uid' => 1], ['category' => 'now_money']]);
                $t->query('uid', 'integer', false, '用户UID筛选');
                $t->query('category', 'string', false, '类目：now_money=余额 brokerage=佣金 integral=积分');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });
    }
}
