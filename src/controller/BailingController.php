<?php
// +----------------------------------------------------------------------
// | BailingHub 工具源控制器
// | 1. 发布 tools.json（工具源 spec）
// | 2. 授权探针（authz-probe）
// | 3. 工具执行端点（验签 + CRMEB 原生权限裁决 + 调用业务逻辑）
// | 工具实现覆盖 CRMEB 后台主要业务能力（商品/订单/售后/用户/营销/财务/统计/门店）
// +----------------------------------------------------------------------
namespace app\bailing\controller;

// 兜底自动加载：composer 安装时 vendor autoload 会注册 Bailing\Connect；
// 但 zip/网页安装场景 composer 映射表里没有该命名空间，需要本地注册，
// 否则报 Class 'Bailing\Connect\ToolSpec' not found
spl_autoload_register(function ($class) {
    $prefix = 'Bailing\\Connect\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/../connect/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Bailing\Connect\SpecServer;
use Bailing\Connect\Verify;
use app\bailing\BailingSpec;
use app\bailing\auth\CrmebAuthorization;
use app\bailing\settings\SecretStore;
use think\facade\Db;

class BailingController
{
    /**
     * 读取工具源签名密钥。秘密只存在 CRMEB runtime 下的插件私密存储，
     * 不经过 system_config / sys_config，避免 generic 配置 API 按名称回显。
     */
    protected function secret()
    {
        try {
            $value = (new SecretStore())->get(SecretStore::SIGN_SECRET);
            return $value === '' ? null : $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 工具源 spec（GET /bailing/tools.json）
     * 中枢配置 spec_url 指向此地址，访问策略固定为 signed_required。
     * 正确签名才能读取；未签名/错误签名均返回 401，成功响应禁止缓存。
     * 这只保护能力清单的发现面，工具调用仍独立执行验签、CRMEB 权限裁决和审计。
     */
    public function tools()
    {
        $secret = $this->secret();
        if ($secret === null) {
            return json(['status' => 0, 'msg' => 'bailing plugin not configured: 请先填写工具源签名密钥'], 503);
        }
        $spec = BailingSpec::build();
        return SpecServer::respond($spec, $secret);
    }

    /**
     * 授权探针（POST /bailing/authz-probe）
     * 中枢用不存在的主体探测，业务侧默认拒绝。
     * 探针协议没有具体 tool，只验证该主体当前是否仍是有效 CRMEB 管理员；
     * 每个工具的细粒度原生权限在 dispatch() 中实时裁决。
     * 未配置签名密钥时 fail-closed（空密钥的签名任何人都可伪造）
     */
    public function authzProbe()
    {
        $secret = $this->secret();
        if ($secret === null) {
            return json(['status' => 0, 'msg' => 'bailing plugin not configured'], 503);
        }
        return SpecServer::respondAuthzProbe($secret, function ($subject) {
            if (empty($subject)) {
                return false;
            }
            $admin = Db::name('system_admin')->where('id', (int)$subject)->where('status', 1)->where('is_del', 0)->find();
            return !empty($admin);
        }, '/bailing/authz-probe');
    }

    /**
     * 聊天组件登录票据（/bailing/chat-ticket）
     * CRMEB 后台管理员凭 Authori-zation(JWT) 换取百灵访客票据：
     * 1. 用 CRMEB 自己的 AdminAuthServices::parseToken 验证管理员登录态
     * 2. 用接入方 token 签发 data-ticket（Ticket::sign），uid = 管理员 ID
     *    ——与工具端点的 X-Bailing-On-Behalf-Of 主体对齐，同一条权限裁决链
     * 未配置接入方 token 时返回 503（fail-closed）
     */
    public function chatTicket()
    {
        // 0. 必须已配置接入方 token（签名材料），否则 fail-closed
        try {
            $accessToken = (new SecretStore())->get(SecretStore::ACCESS_TOKEN);
        } catch (\Throwable $e) {
            $accessToken = '';
        }
        if ($accessToken === '') {
            return json(['status' => 0, 'msg' => 'bailing plugin not configured: 请先填写接入方 token'], 503);
        }

        // 1. 取 CRMEB 管理员 token（前端固定 Authori-zation 头，兼容 ?token=）
        $authHeader = trim((string)request()->header('Authori-zation', ''));
        $token = stripos($authHeader, 'Bearer ') === 0 ? trim(substr($authHeader, 7)) : $authHeader;
        if ($token === '') {
            $token = trim((string)request()->get('token', ''));
        }
        if ($token === '') {
            return json(['status' => 0, 'msg' => '请登录'], 401);
        }

        // 2. 用 CRMEB 自己的服务验证管理员登录态（签名+过期+Redis bucket 一体校验）
        try {
            /** @var \app\services\system\admin\AdminAuthServices $authService */
            $authService = app()->make(\app\services\system\admin\AdminAuthServices::class);
            $adminInfo = $authService->parseToken($token);
        } catch (\Throwable $e) {
            return json(['status' => 0, 'msg' => '登录已过期，请重新登录'], 401);
        }
        $adminId = (int)($adminInfo['id'] ?? 0);
        if (!$adminId) {
            return json(['status' => 0, 'msg' => '登录状态无效'], 401);
        }

        // 3. 管理员仍有效
        $admin = Db::name('system_admin')->where('id', $adminId)->where('status', 1)->where('is_del', 0)->find();
        if (empty($admin)) {
            return json(['status' => 0, 'msg' => '账号不存在或已禁用'], 403);
        }

        // 4. 签发访客票据（uid 与工具端点 On-Behalf-Of 主体一致：管理员 ID）
        $ticket = \Bailing\Connect\Ticket::sign($accessToken, (string)$adminId);
        return json(['status' => 1, 'data' => ['ticket' => $ticket]]);
    }

    /**
     * 工具执行入口（/bailing/tools/{cat}/{action}）
     * 所有 AI 工具调用都走这里：验签 → 权限 → 分发
     * 未配置签名密钥时 fail-closed，绝不默认放行
     */
    public function dispatch()
    {
        // 0. 未配置密钥 = 插件未完成配置，全部拒绝（fail-closed）
        $secret = $this->secret();
        if ($secret === null) {
            return json(['status' => 0, 'msg' => 'bailing plugin not configured: 请先在后台「系统设置 → 百灵中枢配置」填写签名密钥'], 503);
        }

        // 1. 解析工具名。权限适配器只负责把它映射到等价的 CRMEB 原生操作。
        $tool = $this->toolName();
        $authorization = new CrmebAuthorization();

        // 2. 强制关卡：验中枢签名，并用 CRMEB 当前账号/角色/system_menus 实时裁决。
        // 票据只提供 subject；插件不接收、不缓存、更不维护第二套角色或权限快照。
        $gate = Verify::gate($secret, function ($operator, $calledTool, $params) use ($authorization) {
            return $authorization->allows($operator, $calledTool, $params);
        }, $tool, $this->specPath());
        if (!$gate['ok']) {
            if ((int)$gate['code'] === 401) {
                return json(['status' => 0, 'msg' => 'bad signature', 'reason' => $gate['error']], 401);
            }
            return json([
                'status' => 0,
                'msg' => $authorization->lastError() ?: 'CRMEB 账号没有该操作权限',
            ], 403);
        }

        // 3. 权限通过后才解析业务参数并分发。
        $operator = (int)$gate['operator'];
        $params = array_merge(request()->get(), request()->post());
        // JSON body 支持
        $contentType = (string)request()->contentType();
        if (strpos($contentType, 'json') !== false) {
            $body = json_decode(request()->getContent(), true);
            if (is_array($body)) {
                $params = array_merge($params, $body);
            }
        }

        try {
            $data = $this->execute($tool, $params, $operator);
            return json(['status' => 1, 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['status' => 0, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 工具分发执行
     */
    protected function execute($tool, $params, $operator)
    {
        $map = [
            // 商品
            'product_list' => 'productList',
            'product_detail' => 'productDetail',
            'product_category_tree' => 'productCategoryTree',
            'product_create' => 'productCreate',
            'product_set_show' => 'productSetShow',
            'product_update_stock' => 'productUpdateStock',
            'product_update_price' => 'productUpdatePrice',
            'product_reply_list' => 'productReplyList',
            'product_reply_audit' => 'productReplyAudit',
            'product_reply_answer' => 'productReplyAnswer',
            // 订单
            'order_list' => 'orderList',
            'order_detail' => 'orderDetail',
            'order_status_stats' => 'orderStatusStats',
            'order_delivery' => 'orderDelivery',
            'order_remark' => 'orderRemark',
            'order_take_delivery' => 'orderTakeDelivery',
            // 售后
            'refund_list' => 'refundList',
            'refund_agree' => 'refundAgree',
            'refund_refuse' => 'refundRefuse',
            // 用户
            'user_list' => 'userList',
            'user_detail' => 'userDetail',
            'user_set_status' => 'userSetStatus',
            'user_level_list' => 'userLevelList',
            // 营销
            'coupon_issue_list' => 'couponIssueList',
            'coupon_grant' => 'couponGrant',
            'seckill_list' => 'seckillList',
            'combination_list' => 'combinationList',
            'bargain_list' => 'bargainList',
            // 财务
            'extract_list' => 'extractList',
            'extract_refuse' => 'extractRefuse',
            'balance_bill_list' => 'balanceBillList',
            // 统计
            'stats_overview' => 'statsOverview',
            'stats_trade_trend' => 'statsTradeTrend',
            'stats_product_rank' => 'statsProductRank',
            // 分销与门店
            'agent_list' => 'agentList',
            'store_list' => 'storeList',
            'express_list' => 'expressList',
        ];
        if (!isset($map[$tool])) {
            throw new \Exception('未知工具: ' . $tool);
        }
        return $this->{$map[$tool]}($params, $operator);
    }

    // ==================== 商品模块 ====================

    protected function productList($params)
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('store_product')
            ->where('is_del', 0)
            ->field('id,store_name,image,price,ot_price,stock,sales,is_show,unit_name,cate_id');
        if ($keyword !== '') {
            $query->whereLike('store_name', '%' . $keyword . '%');
        }
        if (isset($params['is_show']) && $params['is_show'] !== '') {
            $query->where('is_show', (int)$params['is_show']);
        }
        if (!empty($params['cate_id'])) {
            $query->where('cate_id', (int)$params['cate_id']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function productDetail($params)
    {
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            throw new \Exception('商品ID必填');
        }
        $row = Db::name('store_product')
            ->where('id', $id)->where('is_del', 0)
            ->field('id,store_name,store_info,keyword,image,slider_image,price,ot_price,cost,stock,sales,is_show,unit_name,cate_id,postage,give_integral,sort,add_time')
            ->find();
        if (!$row) {
            throw new \Exception('商品不存在');
        }
        return $row;
    }

    protected function productCategoryTree()
    {
        $list = Db::name('store_category')
            ->where('is_show', 1)
            ->field('id,cate_name,pid,sort')
            ->order('sort desc,id asc')
            ->select()->toArray();
        return ['list' => $this->buildTree($list)];
    }

    protected function productCreate($params, $operator)
    {
        $storeName = trim((string)($params['store_name'] ?? ''));
        $price = (float)($params['price'] ?? 0);
        $stock = (int)($params['stock'] ?? 0);

        if ($storeName === '') {
            throw new \Exception('商品名称必填');
        }
        if ($price <= 0) {
            throw new \Exception('商品价格必须大于0');
        }
        if ($stock < 0) {
            throw new \Exception('库存不能为负数');
        }

        // 分类校验
        $cateId = (int)($params['cate_id'] ?? 0);
        if ($cateId) {
            $cate = Db::name('store_category')->where('id', $cateId)->where('is_show', 1)->find();
            if (!$cate) {
                throw new \Exception('分类不存在或已隐藏，请用 product_category_tree 查询有效分类');
            }
        }

        // 运费模板：取第一个模板，没有则 0（包邮）
        $tempId = 0;
        try {
            $temp = Db::name('shipping_templates')->order('id asc')->find();
            if ($temp) {
                $tempId = (int)$temp['id'];
            }
        } catch (\Throwable $e) {
            // 表不存在则按包邮处理
        }

        $image = trim((string)($params['image'] ?? ''));
        $data = [
            'store_name' => $storeName,
            'store_info' => trim((string)($params['store_info'] ?? ($params['description'] ?? ''))),
            'keyword' => trim((string)($params['keyword'] ?? '')),
            'image' => $image,
            'slider_image' => $image !== '' ? json_encode([$image]) : json_encode([]),
            'price' => $price,
            'ot_price' => (float)($params['ot_price'] ?? $price),
            'vip_price' => $price,
            'cost' => (float)($params['cost'] ?? $price),
            'stock' => $stock,
            'unit_name' => trim((string)($params['unit_name'] ?? '件')) ?: '件',
            'cate_id' => $cateId,
            'is_show' => isset($params['is_show']) ? (int)$params['is_show'] : 1,
            'is_del' => 0,
            'add_time' => time(),
            'temp_id' => $tempId,
            'postage' => 0,
            'is_postage' => 0,
            'give_integral' => 0,
            'spec_type' => 0,
        ];
        $id = Db::name('store_product')->insertGetId($data);
        return ['id' => $id, 'store_name' => $storeName, 'price' => $price, 'stock' => $stock];
    }

    protected function productSetShow($params)
    {
        [$id, $product] = $this->requireProduct($params);
        if (!isset($params['is_show']) || !in_array((int)$params['is_show'], [0, 1], true)) {
            throw new \Exception('is_show 必填：1=上架 0=下架');
        }
        $isShow = (int)$params['is_show'];
        Db::name('store_product')->where('id', $id)->update(['is_show' => $isShow]);
        return ['id' => $id, 'is_show' => $isShow];
    }

    protected function productUpdateStock($params)
    {
        [$id, $product] = $this->requireProduct($params);
        if (!isset($params['stock'])) {
            throw new \Exception('stock 必填');
        }
        $stock = (int)$params['stock'];
        if ($stock < 0) {
            throw new \Exception('库存不能为负数');
        }
        Db::name('store_product')->where('id', $id)->update(['stock' => $stock]);
        return ['id' => $id, 'stock' => $stock];
    }

    protected function productUpdatePrice($params)
    {
        [$id, $product] = $this->requireProduct($params);
        $price = (float)($params['price'] ?? 0);
        if ($price <= 0) {
            throw new \Exception('price 必填且必须大于0');
        }
        $update = ['price' => $price, 'vip_price' => $price];
        if (isset($params['ot_price']) && (float)$params['ot_price'] > 0) {
            $update['ot_price'] = (float)$params['ot_price'];
        }
        if (isset($params['cost']) && (float)$params['cost'] >= 0) {
            $update['cost'] = (float)$params['cost'];
        }
        Db::name('store_product')->where('id', $id)->update($update);
        return array_merge(['id' => $id], $update);
    }

    protected function productReplyList($params)
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('store_product_reply')
            ->where('is_del', 0)
            ->field('id,uid,product_id,nickname,comment,product_score,service_score,status,is_reply,merchant_reply_content,add_time');
        if (!empty($params['product_id'])) {
            $query->where('product_id', (int)$params['product_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function productReplyAudit($params)
    {
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            throw new \Exception('评论ID必填');
        }
        if (!isset($params['status']) || !in_array((int)$params['status'], [0, 1], true)) {
            throw new \Exception('status 必填：1=通过 0=驳回');
        }
        $reply = Db::name('store_product_reply')->where('id', $id)->where('is_del', 0)->find();
        if (!$reply) {
            throw new \Exception('评论不存在');
        }
        $status = (int)$params['status'];
        Db::name('store_product_reply')->where('id', $id)->update(['status' => $status]);
        return ['id' => $id, 'status' => $status];
    }

    protected function productReplyAnswer($params)
    {
        $id = (int)($params['id'] ?? 0);
        $content = trim((string)($params['content'] ?? ''));
        if (!$id || $content === '') {
            throw new \Exception('评论ID和回复内容必填');
        }
        $reply = Db::name('store_product_reply')->where('id', $id)->where('is_del', 0)->find();
        if (!$reply) {
            throw new \Exception('评论不存在');
        }
        Db::name('store_product_reply')->where('id', $id)->update([
            'merchant_reply_content' => $content,
            'merchant_reply_time' => time(),
            'is_reply' => 1,
        ]);
        return ['id' => $id, 'merchant_reply_content' => $content];
    }

    // ==================== 订单模块 ====================

    protected function orderList($params)
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('store_order')
            ->where('is_del', 0)->where('is_system_del', 0)
            ->field('id,order_id,uid,real_name,user_phone,pay_price,total_price,total_num,paid,pay_time,pay_type,status,refund_status,add_time');
        if (!empty($params['order_id'])) {
            $query->where('order_id', trim((string)$params['order_id']));
        }
        if (!empty($params['real_name'])) {
            $query->whereLike('real_name', '%' . trim((string)$params['real_name']) . '%');
        }
        if (isset($params['paid']) && $params['paid'] !== '') {
            $query->where('paid', (int)$params['paid']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function orderDetail($params)
    {
        $id = (int)($params['id'] ?? 0);
        $orderId = trim((string)($params['order_id'] ?? ''));
        if (!$id && $orderId === '') {
            throw new \Exception('id 或 order_id 必填其一');
        }
        $query = Db::name('store_order')->where('is_del', 0)->where('is_system_del', 0);
        if ($id) {
            $query->where('id', $id);
        } else {
            $query->where('order_id', $orderId);
        }
        $order = $query->find();
        if (!$order) {
            throw new \Exception('订单不存在');
        }
        // 商品明细
        $cartInfo = Db::name('store_order_cart_info')->where('oid', $order['id'])->select()->toArray();
        $items = [];
        foreach ($cartInfo as $row) {
            $info = is_string($row['cart_info']) ? json_decode($row['cart_info'], true) : (array)$row['cart_info'];
            $items[] = [
                'product_id' => $info['product_id'] ?? 0,
                'store_name' => $info['productInfo']['store_name'] ?? ($info['store_name'] ?? ''),
                'price' => $info['truePrice'] ?? ($info['price'] ?? 0),
                'cart_num' => $row['cart_num'] ?? 1,
            ];
        }
        // 状态变更日志
        $logs = Db::name('store_order_status')->where('oid', $order['id'])->order('id asc')->select()->toArray();

        return ['order' => $order, 'cart_info' => $items, 'status_logs' => $logs];
    }

    protected function orderStatusStats()
    {
        $base = function () {
            return Db::name('store_order')->where('is_del', 0)->where('is_system_del', 0);
        };
        return [
            'unpaid' => $base()->where('paid', 0)->count(),
            'paid_unshipped' => $base()->where('paid', 1)->where('status', 0)->where('refund_status', 0)->count(),
            'shipped' => $base()->where('status', 1)->count(),
            'received' => $base()->where('status', 2)->count(),
            'finished' => $base()->where('status', 3)->count(),
            'refunding' => $base()->where('refund_status', 1)->count(),
            'total' => $base()->count(),
        ];
    }

    protected function orderDelivery($params, $operator)
    {
        [$id, $order] = $this->requireOrder($params);
        $deliveryName = trim((string)($params['delivery_name'] ?? ''));
        $deliveryId = trim((string)($params['delivery_id'] ?? ''));
        if ($deliveryName === '' || $deliveryId === '') {
            throw new \Exception('物流公司名称(delivery_name)和物流单号(delivery_id)必填');
        }
        if (!$order['paid']) {
            throw new \Exception('订单未支付，不能发货');
        }
        if ((int)$order['status'] !== 0) {
            throw new \Exception('当前订单状态不允许发货（仅待发货订单可发货）');
        }

        Db::startTrans();
        try {
            Db::name('store_order')->where('id', $id)->update([
                'status' => 1,
                'delivery_type' => 'express',
                'delivery_name' => $deliveryName,
                'delivery_id' => $deliveryId,
            ]);
            $this->logOrderStatus($id, 'delivery_goods', '已发货 物流公司：' . $deliveryName . ' 单号：' . $deliveryId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return ['id' => $id, 'status' => 1, 'delivery_name' => $deliveryName, 'delivery_id' => $deliveryId];
    }

    protected function orderRemark($params)
    {
        [$id, $order] = $this->requireOrder($params);
        $remark = trim((string)($params['remark'] ?? ''));
        if ($remark === '') {
            throw new \Exception('备注内容必填');
        }
        Db::name('store_order')->where('id', $id)->update(['remark' => $remark]);
        return ['id' => $id, 'remark' => $remark];
    }

    protected function orderTakeDelivery($params, $operator)
    {
        [$id, $order] = $this->requireOrder($params);
        if ((int)$order['status'] !== 1) {
            throw new \Exception('仅待收货（已发货）订单可确认收货');
        }
        Db::startTrans();
        try {
            Db::name('store_order')->where('id', $id)->update(['status' => 2]);
            $this->logOrderStatus($id, 'take_delivery', '已收货');
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return ['id' => $id, 'status' => 2];
    }

    // ==================== 售后模块 ====================

    protected function refundList($params)
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('store_order_refund')
            ->where('is_del', 0)->where('is_cancel', 0)->where('is_system_del', 0)
            ->field('id,store_order_id,order_id,uid,refund_type,refund_num,refund_price,refunded_price,refund_reason,refuse_reason,refund_explain,add_time');
        if (isset($params['refund_type']) && $params['refund_type'] !== '') {
            $query->where('refund_type', (int)$params['refund_type']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function refundAgree($params, $operator)
    {
        [$id, $refund] = $this->requireRefund($params);
        if ((int)$refund['refund_type'] !== 1) {
            throw new \Exception('仅"申请中"的售后单可执行同意操作');
        }
        Db::startTrans();
        try {
            // 同意 → 待退货/待退款
            Db::name('store_order_refund')->where('id', $id)->update(['refund_type' => 2]);
            $this->logOrderStatus((int)$refund['store_order_id'], 'refund_agree', '商家同意售后申请');
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return ['id' => $id, 'refund_type' => 2, 'msg' => '已同意。实际资金原路退回请在后台「订单退款」执行'];
    }

    protected function refundRefuse($params, $operator)
    {
        [$id, $refund] = $this->requireRefund($params);
        $reason = trim((string)($params['refuse_reason'] ?? ''));
        if ($reason === '') {
            throw new \Exception('拒绝理由必填');
        }
        if ((int)$refund['refund_type'] !== 1) {
            throw new \Exception('仅"申请中"的售后单可执行拒绝操作');
        }
        Db::startTrans();
        try {
            Db::name('store_order_refund')->where('id', $id)->update([
                'refund_type' => 3,
                'refuse_reason' => $reason,
            ]);
            $this->logOrderStatus((int)$refund['store_order_id'], 'refund_refuse', '商家拒绝售后：' . $reason);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return ['id' => $id, 'refund_type' => 3, 'refuse_reason' => $reason];
    }

    // ==================== 用户模块 ====================

    protected function userList($params)
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('user')
            ->where('is_del', 0)
            ->field('uid,account,nickname,phone,avatar,now_money,brokerage_price,integral,level,is_promoter,status,add_time');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('nickname', '%' . $keyword . '%')
                  ->whereOr('phone', $keyword)
                  ->whereOr('uid', (int)$keyword);
            });
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('uid', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function userDetail($params)
    {
        $uid = (int)($params['uid'] ?? 0);
        if (!$uid) {
            throw new \Exception('用户UID必填');
        }
        $user = Db::name('user')
            ->where('uid', $uid)->where('is_del', 0)
            ->field('uid,account,nickname,real_name,phone,avatar,now_money,brokerage_price,integral,exp,level,agent_level,is_promoter,spread_uid,pay_count,spread_count,status,add_time,last_time')
            ->find();
        if (!$user) {
            throw new \Exception('用户不存在');
        }
        return $user;
    }

    protected function userSetStatus($params)
    {
        $uid = (int)($params['uid'] ?? 0);
        if (!$uid) {
            throw new \Exception('用户UID必填');
        }
        if (!isset($params['status']) || !in_array((int)$params['status'], [0, 1], true)) {
            throw new \Exception('status 必填：1=启用 0=禁用');
        }
        $user = Db::name('user')->where('uid', $uid)->where('is_del', 0)->find();
        if (!$user) {
            throw new \Exception('用户不存在');
        }
        $status = (int)$params['status'];
        Db::name('user')->where('uid', $uid)->update(['status' => $status]);
        return ['uid' => $uid, 'status' => $status];
    }

    protected function userLevelList()
    {
        $list = Db::name('system_user_level')
            ->where('is_del', 0)->where('is_show', 1)
            ->field('id,name,grade,discount,icon,image')
            ->order('grade asc')
            ->select()->toArray();
        return ['list' => $list];
    }

    // ==================== 营销模块 ====================

    protected function couponIssueList($params)
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('store_coupon_issue')
            ->where('is_del', 0)
            ->field('id,cid,title,coupon_title,coupon_price,use_min_price,total_count,remain_count,receive_limit,is_permanent,status,start_time,end_time');
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function couponGrant($params, $operator)
    {
        $couponId = (int)($params['coupon_id'] ?? 0);
        $uid = (int)($params['uid'] ?? 0);
        $num = min(10, max(1, (int)($params['num'] ?? 1)));
        if (!$couponId || !$uid) {
            throw new \Exception('coupon_id 和 uid 必填');
        }
        $coupon = Db::name('store_coupon_issue')->where('id', $couponId)->where('is_del', 0)->find();
        if (!$coupon) {
            throw new \Exception('优惠券不存在');
        }
        if ((int)$coupon['status'] !== 1) {
            throw new \Exception('优惠券已关闭，不能发放');
        }
        if ((int)$coupon['remain_count'] < $num) {
            throw new \Exception('优惠券剩余数量不足');
        }
        $user = Db::name('user')->where('uid', $uid)->where('is_del', 0)->find();
        if (!$user) {
            throw new \Exception('目标用户不存在');
        }

        // 有效期计算
        $startUse = (int)$coupon['start_use_time'];
        $endUse = (int)$coupon['end_use_time'];
        if (!$coupon['is_permanent'] && (int)$coupon['coupon_time'] > 0) {
            $startUse = time();
            $endUse = time() + (int)$coupon['coupon_time'] * 86400;
        }

        Db::startTrans();
        try {
            for ($i = 0; $i < $num; $i++) {
                Db::name('store_coupon_user')->insert([
                    'cid' => (int)$coupon['cid'],
                    'uid' => $uid,
                    'coupon_title' => $coupon['coupon_title'],
                    'coupon_price' => $coupon['coupon_price'],
                    'use_min_price' => $coupon['use_min_price'],
                    'start_time' => $startUse,
                    'end_time' => $endUse,
                    'add_time' => time(),
                    'type' => 'send',
                    'status' => 0,
                    'is_fail' => 0,
                ]);
                // 发放记录 pivot
                Db::name('store_coupon_issue_user')->insert([
                    'uid' => $uid,
                    'issue_coupon_id' => $couponId,
                    'add_time' => time(),
                ]);
            }
            Db::name('store_coupon_issue')->where('id', $couponId)->dec('remain_count', $num)->update();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return ['coupon_id' => $couponId, 'uid' => $uid, 'num' => $num, 'remain_count' => (int)$coupon['remain_count'] - $num];
    }

    protected function seckillList($params)
    {
        return $this->activityList('store_seckill', $params,
            'id,title,info,price,ot_price,stock,sales,status,start_time,stop_time,add_time');
    }

    protected function combinationList($params)
    {
        return $this->activityList('store_combination', $params,
            'id,title,info,price,people,stock,sales,status,start_time,stop_time,add_time');
    }

    protected function bargainList($params)
    {
        return $this->activityList('store_bargain', $params,
            'id,title,info,price,min_price,stock,sales,status,start_time,stop_time,add_time');
    }

    // ==================== 财务模块 ====================

    protected function extractList($params)
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('user_extract')
            ->field('id,uid,real_name,user_name,extract_type,extract_price,balance,mark,fail_msg,status,add_time');
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function extractRefuse($params, $operator)
    {
        $id = (int)($params['id'] ?? 0);
        $failMsg = trim((string)($params['fail_msg'] ?? ''));
        if (!$id || $failMsg === '') {
            throw new \Exception('提现申请ID和拒绝原因必填');
        }
        $extract = Db::name('user_extract')->where('id', $id)->find();
        if (!$extract) {
            throw new \Exception('提现申请不存在');
        }
        if ((int)$extract['status'] !== 0) {
            throw new \Exception('仅待审核的提现申请可拒绝');
        }

        Db::startTrans();
        try {
            Db::name('user_extract')->where('id', $id)->update([
                'status' => -1,
                'fail_msg' => $failMsg,
                'fail_time' => time(),
            ]);
            // 退回金额到用户余额 + 流水
            $price = (float)$extract['extract_price'];
            if ($price > 0) {
                Db::name('user')->where('uid', (int)$extract['uid'])->inc('now_money', $price)->update();
                $balance = Db::name('user')->where('uid', (int)$extract['uid'])->value('now_money');
                Db::name('user_bill')->insert([
                    'uid' => (int)$extract['uid'],
                    'link_id' => $id,
                    'pm' => 1,
                    'title' => '提现申请被拒绝退回',
                    'category' => 'now_money',
                    'type' => 'extract',
                    'number' => $price,
                    'balance' => $balance,
                    'mark' => $failMsg,
                    'add_time' => time(),
                    'status' => 1,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return ['id' => $id, 'status' => -1];
    }

    protected function balanceBillList($params)
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('user_bill')
            ->field('id,uid,link_id,pm,title,category,type,number,balance,mark,add_time');
        if (!empty($params['uid'])) {
            $query->where('uid', (int)$params['uid']);
        }
        if (!empty($params['category'])) {
            $query->where('category', trim((string)$params['category']));
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    // ==================== 统计模块 ====================

    protected function statsOverview()
    {
        $todayStart = strtotime(date('Y-m-d'));
        $orderBase = function () {
            return Db::name('store_order')->where('is_del', 0)->where('is_system_del', 0)->where('paid', 1);
        };
        return [
            'today' => [
                'sales' => round((float)$orderBase()->where('pay_time', '>=', $todayStart)->sum('pay_price'), 2),
                'orders' => $orderBase()->where('pay_time', '>=', $todayStart)->count(),
                'new_users' => Db::name('user')->where('add_time', '>=', $todayStart)->count(),
            ],
            'total' => [
                'sales' => round((float)$orderBase()->sum('pay_price'), 2),
                'orders' => $orderBase()->count(),
                'users' => Db::name('user')->where('is_del', 0)->count(),
                'products' => Db::name('store_product')->where('is_del', 0)->count(),
            ],
        ];
    }

    protected function statsTradeTrend($params)
    {
        $days = min(30, max(1, (int)($params['days'] ?? 7)));
        $list = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $start = strtotime(date('Y-m-d', strtotime("-{$i} days")));
            $end = $start + 86400;
            $base = Db::name('store_order')
                ->where('is_del', 0)->where('is_system_del', 0)->where('paid', 1)
                ->where('pay_time', '>=', $start)->where('pay_time', '<', $end);
            $list[] = [
                'date' => date('Y-m-d', $start),
                'sales' => round((float)(clone $base)->sum('pay_price'), 2),
                'orders' => (clone $base)->count(),
            ];
        }
        return ['list' => $list];
    }

    protected function statsProductRank($params)
    {
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));
        $list = Db::name('store_product')
            ->where('is_del', 0)
            ->field('id,store_name,image,price,stock,sales')
            ->order('sales desc')
            ->limit($limit)
            ->select()->toArray();
        return ['list' => $list];
    }

    // ==================== 分销与门店 ====================

    protected function agentList($params)
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name('user')
            ->where('is_del', 0)->where('is_promoter', 1)
            ->field('uid,nickname,avatar,brokerage_price,spread_count,pay_count,spread_time,add_time');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('nickname', '%' . $keyword . '%')->whereOr('uid', (int)$keyword);
            });
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('brokerage_price', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    protected function storeList()
    {
        $list = Db::name('system_store')
            ->where('is_del', 0)
            ->field('id,name,phone,address,detailed_address,image,is_show,day_time')
            ->order('id desc')
            ->select()->toArray();
        return ['list' => $list, 'total' => count($list)];
    }

    protected function expressList()
    {
        $list = Db::name('express')
            ->where('is_show', 1)
            ->field('id,name,code,sort')
            ->order('sort asc,id asc')
            ->select()->toArray();
        return ['list' => $list];
    }

    // ==================== 辅助方法 ====================

    /**
     * 当前工具对应的 spec path（验签用，与 BailingSpec 单一数据源）
     */
    protected function specPath()
    {
        $tool = $this->toolName();
        $paths = BailingSpec::toolPaths();
        return isset($paths[$tool]) ? $paths[$tool] : '/bailing/tools/' . str_replace('_', '/', $tool);
    }

    /**
     * 从 URL 提取工具名（/bailing/tools/product/list → product_list）
     */
    protected function toolName()
    {
        $path = request()->pathinfo();
        if (!is_string($path)) {
            return '';
        }
        $path = preg_replace('#^/?bailing/#', '', $path);
        if (preg_match('#^tools/(.+)$#', $path, $m)) {
            return str_replace('/', '_', trim($m[1], '/'));
        }
        return '';
    }

    /**
     * 必须存在的商品（返回 [id, row]）
     */
    protected function requireProduct($params)
    {
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            throw new \Exception('商品ID必填');
        }
        $row = Db::name('store_product')->where('id', $id)->where('is_del', 0)->find();
        if (!$row) {
            throw new \Exception('商品不存在');
        }
        return [$id, $row];
    }

    /**
     * 必须存在的订单（返回 [id, row]）
     */
    protected function requireOrder($params)
    {
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            throw new \Exception('订单ID必填');
        }
        $row = Db::name('store_order')->where('id', $id)->where('is_del', 0)->where('is_system_del', 0)->find();
        if (!$row) {
            throw new \Exception('订单不存在');
        }
        return [$id, $row];
    }

    /**
     * 必须存在的售后单（返回 [id, row]）
     */
    protected function requireRefund($params)
    {
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            throw new \Exception('售后单ID必填');
        }
        $row = Db::name('store_order_refund')->where('id', $id)->where('is_del', 0)->where('is_cancel', 0)->find();
        if (!$row) {
            throw new \Exception('售后单不存在');
        }
        return [$id, $row];
    }

    /**
     * 记录订单状态变更日志（eb_store_order_status）
     */
    protected function logOrderStatus($oid, $changeType, $message)
    {
        try {
            Db::name('store_order_status')->insert([
                'oid' => $oid,
                'change_type' => $changeType,
                'change_message' => $message,
                'change_time' => time(),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不阻断主流程
        }
    }

    /**
     * 通用营销活动列表查询
     */
    protected function activityList($table, $params, $fields)
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));

        $query = Db::name($table)->where('is_del', 0)->field($fields);
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 一维分类列表转树
     */
    protected function buildTree($list, $pid = 0)
    {
        $tree = [];
        foreach ($list as $item) {
            if ((int)$item['pid'] === (int)$pid) {
                $children = $this->buildTree($list, $item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }
}
