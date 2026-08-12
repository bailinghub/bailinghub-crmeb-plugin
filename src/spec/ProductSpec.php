<?php
// +----------------------------------------------------------------------
// | 商品域能力声明（元能力：单资源单动作）
// +----------------------------------------------------------------------
namespace app\bailing\spec;

use Bailing\Connect\ToolDef;
use Bailing\Connect\ToolSpec;

class ProductSpec implements SpecModule
{
    public static function paths()
    {
        return [
            'product_list' => '/bailing/tools/product/list',
            'product_detail' => '/bailing/tools/product/detail',
            'product_category_tree' => '/bailing/tools/product/category_tree',
            'product_create' => '/bailing/tools/product/create',
            'product_set_show' => '/bailing/tools/product/set_show',
            'product_update_stock' => '/bailing/tools/product/update_stock',
            'product_update_price' => '/bailing/tools/product/update_price',
            'product_reply_list' => '/bailing/tools/product/reply_list',
            'product_reply_audit' => '/bailing/tools/product/reply_audit',
            'product_reply_answer' => '/bailing/tools/product/reply_answer',
        ];
    }

    public static function register(ToolSpec $spec)
    {
        $p = self::paths();

        $spec->tool('product_list', 'GET', $p['product_list'], 'product.read', '查询商品列表',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询商品、商品列表、在售/下架商品、按关键词搜商品时使用')
                  ->returns('{code:1,data:{list:[{id,store_name,image,price,stock,sales,is_show,unit_name}],total}}')
                  ->examples([['keyword' => '手机'], ['is_show' => 1, 'page' => 1, 'limit' => 10]]);
                $t->query('keyword', 'string', false, '搜索关键词（商品名称模糊匹配）');
                $t->query('is_show', 'integer', false, '上下架筛选：1=在售 0=已下架，不传查全部');
                $t->query('cate_id', 'integer', false, '商品分类ID筛选');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10，最大50');
            });

        $spec->tool('product_detail', 'GET', $p['product_detail'], 'product.read', '查询商品详情',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查看某个商品的完整信息（价格/库存/分类/描述/单位等）时使用')
                  ->returns('{code:1,data:{id,store_name,store_info,image,price,ot_price,cost,stock,sales,is_show,unit_name,cate_id,keyword}}')
                  ->examples([['id' => 1]]);
                $t->query('id', 'integer', true, '商品ID');
            });

        $spec->tool('product_category_tree', 'GET', $p['product_category_tree'], 'product.read', '查询商品分类树',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查询商品分类、创建商品前需要选择分类ID时使用')
                  ->returns('{code:1,data:{list:[{id,cate_name,pid,children:[...]}]}}')
                  ->examples([[]]);
            });

        $spec->tool('product_create', 'POST', $p['product_create'], 'product.create', '创建新商品',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('用户要求创建/上架新商品时使用。创建前如需要分类ID，先用 product_category_tree 查询')
                  ->returns('{code:1,data:{id,store_name,price,stock}}')
                  ->examples([['store_name' => 'iPhone 15', 'price' => 5999, 'stock' => 100, 'unit_name' => '台']]);
                $t->body('store_name', 'string', true, '商品名称');
                $t->body('price', 'number', true, '售价（元）');
                $t->body('stock', 'integer', true, '库存数量');
                $t->body('image', 'string', false, '主图URL（建议提供，否则商品无图）');
                $t->body('ot_price', 'number', false, '原价（元），默认同售价');
                $t->body('cost', 'number', false, '成本价（元），默认同售价');
                $t->body('store_info', 'string', false, '商品简介');
                $t->body('keyword', 'string', false, '搜索关键词');
                $t->body('unit_name', 'string', false, '单位（件/台/箱...），默认"件"');
                $t->body('cate_id', 'integer', false, '分类ID（可用 product_category_tree 查询）');
                $t->body('is_show', 'integer', false, '是否立即上架：1=上架（默认） 0=下架');
            });

        $spec->tool('product_set_show', 'POST', $p['product_set_show'], 'product.update', '商品上架/下架',
            function (ToolDef $t) {
                $t->risk('low')->idempotent()->requiresSubject()
                  ->whenToUse('上架或下架某个商品时使用')
                  ->returns('{code:1,data:{id,is_show}}')
                  ->examples([['id' => 1, 'is_show' => 0]]);
                $t->body('id', 'integer', true, '商品ID');
                $t->body('is_show', 'integer', true, '1=上架 0=下架');
            });

        $spec->tool('product_update_stock', 'POST', $p['product_update_stock'], 'product.update', '修改商品库存',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('调整商品库存数量时使用')
                  ->returns('{code:1,data:{id,stock}}')
                  ->examples([['id' => 1, 'stock' => 200]]);
                $t->body('id', 'integer', true, '商品ID');
                $t->body('stock', 'integer', true, '新的库存数量（>=0）');
            });

        $spec->tool('product_update_price', 'POST', $p['product_update_price'], 'product.update', '修改商品价格',
            function (ToolDef $t) {
                $t->risk('medium')->requiresSubject()
                  ->whenToUse('调整商品售价/原价/成本价时使用')
                  ->returns('{code:1,data:{id,price,ot_price,cost}}')
                  ->examples([['id' => 1, 'price' => 4999]]);
                $t->body('id', 'integer', true, '商品ID');
                $t->body('price', 'number', true, '新售价（元）');
                $t->body('ot_price', 'number', false, '新原价（元），不传不变');
                $t->body('cost', 'number', false, '新成本价（元），不传不变');
            });

        $spec->tool('product_reply_list', 'GET', $p['product_reply_list'], 'product.read', '查询商品评论',
            function (ToolDef $t) {
                $t->readonly()->idempotent()->requiresSubject()
                  ->whenToUse('查看商品评论、待审核评论时使用')
                  ->returns('{code:1,data:{list:[{id,product_id,nickname,comment,product_score,service_score,status,add_time}],total}}')
                  ->examples([['status' => 0], ['product_id' => 1]]);
                $t->query('product_id', 'integer', false, '商品ID筛选');
                $t->query('status', 'integer', false, '审核状态：0=待审核 1=已通过，不传查全部');
                $t->query('page', 'integer', false, '页码，默认1');
                $t->query('limit', 'integer', false, '每页数量，默认10');
            });

        $spec->tool('product_reply_audit', 'POST', $p['product_reply_audit'], 'product.update', '审核商品评论',
            function (ToolDef $t) {
                $t->risk('low')->requiresSubject()
                  ->whenToUse('审核通过或驳回商品评论时使用')
                  ->returns('{code:1,data:{id,status}}')
                  ->examples([['id' => 1, 'status' => 1]]);
                $t->body('id', 'integer', true, '评论ID');
                $t->body('status', 'integer', true, '1=通过 0=驳回');
            });

        $spec->tool('product_reply_answer', 'POST', $p['product_reply_answer'], 'product.update', '回复商品评论',
            function (ToolDef $t) {
                $t->risk('low')->requiresSubject()
                  ->whenToUse('以商家身份回复某条商品评论时使用')
                  ->returns('{code:1,data:{id,merchant_reply_content}}')
                  ->examples([['id' => 1, 'content' => '感谢您的支持！']]);
                $t->body('id', 'integer', true, '评论ID');
                $t->body('content', 'string', true, '回复内容');
            });
    }
}
