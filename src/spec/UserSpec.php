<?php
// +----------------------------------------------------------------------
// | 用户域能力声明（元能力：单资源单动作）
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class UserSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'user_list' => '/bailing/tools/user/list',
            'user_detail' => '/bailing/tools/user/detail',
            'user_set_status' => '/bailing/tools/user/set_status',
            'user_level_list' => '/bailing/tools/user/level_list',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('user_list', 'GET', $p['user_list'], 'user.read', '查询用户列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询商城用户、按手机号/昵称搜用户时使用')
                  ->returns('{code:1,data:{list:[{uid,nickname,phone,now_money,integral,level,status,add_time}],total}}')
                  ->examples([['keyword' => '13800138000']]);
                $t->query('keyword', 'string', false, '手机号/昵称/UID（模糊）');
                $t->query('status', 'integer', false, '状态：1=正常 0=禁用');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('user_detail', 'GET', $p['user_detail'], 'user.read', '查询用户详情',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查看某个用户的余额/积分/等级/分销资格等完整信息时使用')
                  ->returns('{code:1,data:{uid,nickname,phone,now_money,brokerage_price,integral,level,is_promoter,pay_count,add_time}}')
                  ->examples([['uid' => 1]]);
                $t->query('uid', 'integer', true, '用户UID');
            });

        $spec->tool('user_set_status', 'POST', $p['user_set_status'], 'user.update', '启用/禁用用户',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('封禁或恢复某个商城用户时使用')
                  ->returns('{code:1,data:{uid,status}}')
                  ->examples([['uid' => 1, 'status' => 0]]);
                $t->body('uid', 'integer', true, '用户UID');
                $t->body('status', 'integer', true, '1=启用 0=禁用');
            });

        $spec->tool('user_level_list', 'GET', $p['user_level_list'], 'user.read', '查询会员等级列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询商城会员等级体系时使用')
                  ->returns('{code:1,data:{list:[{id,name,grade,discount,icon}]}}')
                  ->examples([[]]);
            });
    }
}
