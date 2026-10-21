<?php
namespace App\Http\Controllers\open;

use App\Http\Controllers\Controller;
use App\Http\Services\SupplierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 卡世界开放平台 · 对外业务 API v1（B 端代理商 app_key/app_secret 鉴权）
 * 路由前缀 /api/open/v1。
 *
 * 鉴权策略：
 *   公开接口（无需签名，匿名可调，price_rate=1，同 IP 限 60 次/分钟防爬）：goods、goods/detail
 *   签名接口（必须 app_key + timestamp + sign）：order/create、order/query、balance
 * 签名算法：sign = md5(参数名升序 k=v 拼接（不含sign、空值跳过） + "&secret=" + app_secret)，
 *           timestamp 与服务器时间差超 10 分钟拒绝。
 *
 * 供货链路：货源与发货全部来自「卡世界供货 API」（SupplierService，凭证走 .env）。
 *   下单：校验余额 → 事务扣 open_user.balance + 写 open_balance_log + 建 open_order
 *         → 调供货方创建订单直充发货 → 结果写回 open_order；发货失败自动退款（幂等）。
 *
 * 金额单位约定：接口里 cost_price/sell_price/balance 一律为「分」；
 * open_user.balance 列为 decimal(10,2) 元，扣款时按 分/100 精确换算（两位小数无精度损失）。
 * 纯新增控制器，不影响现有任何业务。
 */
class OpenApiV1Controller extends Controller
{
    /** 货源缓存 5 分钟 */
    const GOODS_CACHE_TTL = 300;

    /** 签名时间戳允许偏差 10 分钟 */
    const SIGN_TS_TOLERANCE = 600;

    /** open_order 状态 */
    const ORDER_PENDING = 'pending';       // 待提交供货方
    const ORDER_RECHARGING = 'recharging'; // 已提交供货方，充值中
    const ORDER_SUCCESS = 'success';       // 已到账
    const ORDER_FAILED = 'failed';         // 充值失败
    const ORDER_REFUNDED = 'refunded';     // 已退款

    /**
     * GET /api/open/v1/goods 卡券货源列表（公开接口）
     * 参数：category_id?, current=1, size=20（匿名可不带签名参数）
     * 金额字段（cost_price/sell_price）单位：分
     */
    public function goods(Request $request)
    {
        list($rate, $fail) = $this->authPublicGoods($request); // 公开接口：匿名放行(rate=1,IP限频)，带key走签名+倍数
        if ($fail) return $fail;

        $categoryId = trim((string)$request->input('category_id', ''));
        $current = max(1, (int)$request->input('current', 1));
        $size = min(50, max(1, (int)$request->input('size', 20)));

        $cacheKey = 'openv1_goods_' . md5($categoryId . '_' . $current . '_' . $size);
        $data = Cache::remember($cacheKey, self::GOODS_CACHE_TTL, function () use ($categoryId, $current, $size) {
            return (new SupplierService())->goodsList($categoryId, $current, $size);
        });

        if (!$data) return resp(ERROR_UN_KNOW, null, '货源数据获取失败，请稍后重试');

        $list = [];
        foreach (($data['list'] ?? []) as $g) {
            $list[] = [
                'goods_id' => (int)($g['goods_id'] ?? 0),
                'goods_name' => $g['goods_name'] ?? '',
                'goods_img' => $g['goods_img'] ?? '',
                'face_value' => $g['face_value'] ?? '',
                'cost_price' => $this->ratePrice((int)($g['cost_price'] ?? 0), $rate), // 分，供货价×倍数
                'sell_price' => (int)($g['sell_price'] ?? 0), // 分
                'stock_num' => (int)($g['stock_num'] ?? 0),
                'category_name' => $g['category_name'] ?? '',
            ];
        }
        return resp(SUCCESS, [
            'list' => $list,
            'pagination' => $data['pagination'] ?? ['total' => 0, 'size' => $size, 'current' => $current, 'pages' => 0],
            'amount_unit' => 'fen', // 金额单位：分
        ]);
    }

    /**
     * GET /api/open/v1/goods/detail 商品详情（公开接口）
     * 参数：goods_id
     */
    public function goodsDetail(Request $request)
    {
        list($rate, $fail) = $this->authPublicGoods($request); // 公开接口：匿名放行(rate=1,IP限频)，带key走签名+倍数
        if ($fail) return $fail;

        $goodsId = (int)$request->input('goods_id', 0);
        if (!$goodsId) return resp(ERROR_PARAMS, null, 'goods_id 必填');

        $cacheKey = 'openv1_goods_detail_' . $goodsId;
        $data = Cache::remember($cacheKey, self::GOODS_CACHE_TTL, function () use ($goodsId) {
            return (new SupplierService())->goodsDetail($goodsId);
        });

        if (!$data) return resp(NULL_DATA, null, '商品不存在或已下架');

        return resp(SUCCESS, [
            'goods_id' => (int)$data['goods_id'],
            'goods_name' => $data['goods_name'] ?? '',
            'goods_img' => $data['goods_img'] ?? '',
            'face_value' => $data['face_value'] ?? '',
            'cost_price' => $this->ratePrice((int)($data['cost_price'] ?? 0), $rate), // 分，供货价×倍数
            'sell_price' => (int)($data['sell_price'] ?? 0), // 分
            'stock_num' => (int)($data['stock_num'] ?? 0),
            'category_name' => $data['category_name'] ?? '',
            'spec_value' => $data['spec_value'] ?? '',
            'tags' => $data['tags'] ?? [],
            'tabs' => $data['tabs'] ?? [],
            'amount_unit' => 'fen',
        ]);
    }

    /**
     * GET /api/open/v1/balance 账户余额（分，需签名）
     */
    public function balance(Request $request)
    {
        list($user, $fail) = $this->authApi($request);
        if ($fail) return $fail;

        return resp(SUCCESS, ['balance' => $this->balanceFen($user->balance)]);
    }

    /**
     * POST /api/open/v1/order/create 创建卡券订单（余额扣费 + 供货方直充发货，需签名）
     * 参数：goods_id, recharge_account, attach?（+鉴权参数）
     * 返回：{order_no, status, cost_price, balance}（金额单位分）
     */
    public function orderCreate(Request $request)
    {
        list($user, $fail, $keyRow) = $this->authApi($request);
        if ($fail) return $fail;
        $rate = $this->keyRate($keyRow); // 加价倍数：仅用于对外报价展示，扣款/落库仍按供货价

        $goodsId = (int)$request->input('goods_id', 0);
        $account = trim((string)$request->input('recharge_account', ''));
        if (!$goodsId) return resp(ERROR_PARAMS, null, 'goods_id 必填');
        if ($account === '') return resp(ERROR_PARAMS, null, '请输入充值账号');

        // 实时向供货方查询商品（下单不走缓存，保证价格/库存最新）
        $goods = (new SupplierService())->goodsDetail($goodsId);
        if (!$goods) return resp(NULL_DATA, null, '商品不存在或已下架');
        if ((int)($goods['stock_num'] ?? 0) <= 0) {
            return resp(ERROR_PARAMS, null, '商品库存不足');
        }
        $costFen = (int)($goods['cost_price'] ?? 0); // 供货价 分
        if ($costFen <= 0) return resp(ERROR_PARAMS, null, '商品价格异常');

        // 余额校验（明确错误码）
        if ($this->balanceFen($user->balance) < $costFen) {
            return resp(OPEN_BALANCE_NOT_ENOUGH, null, '余额不足，请充值');
        }
        $costYuan = number_format($costFen / 100, 2, '.', ''); // decimal(10,2) 两位小数精确换算

        $orderNo = serviceOrderNo('OP');
        $now = time();

        // 事务：原子扣减（WHERE balance >= cost 防并发超扣）+ 建单 + 写流水
        try {
            DB::beginTransaction();
            $affected = DB::table('open_user')
                ->where('id', $user->id)
                ->where('balance', '>=', $costYuan)
                ->update(['balance' => DB::raw('balance - ' . $costYuan), 'update_time' => $now]);
            if ($affected !== 1) {
                DB::rollBack();
                return resp(OPEN_BALANCE_NOT_ENOUGH, null, '余额不足，请充值');
            }
            DB::table('open_order')->insert([
                'user_id' => $user->id,
                'order_no' => $orderNo,
                'goods_id' => $goodsId,
                'goods_name' => (string)($goods['goods_name'] ?? ''),
                'cost_price' => $costFen,
                'recharge_account' => $account,
                'attach' => (string)$request->input('attach', ''),
                'status' => self::ORDER_PENDING,
                'supplier_order_no' => '',
                'remark' => 'open_api:' . $keyRow->app_key,
                'created_time' => $now,
                'update_time' => $now,
            ]);
            $balanceAfter = $this->balanceFen(DB::table('open_user')->where('id', $user->id)->value('balance'));
            DB::table('open_balance_log')->insert([
                'user_id' => $user->id,
                'change_type' => 'consume',
                'amount' => -$costFen,
                'balance_after' => $balanceAfter,
                'order_no' => $orderNo,
                'remark' => '开放平台下单扣款:' . $goods['goods_name'],
                'created_time' => $now,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return resp(ERROR_UN_KNOW, null, '下单失败：' . $e->getMessage());
        }

        // 调供货方创建订单直充发货
        $supplier = (new SupplierService())->orderCreate($goodsId, $account);
        if ($supplier['ok']) {
            DB::table('open_order')->where('order_no', $orderNo)->update([
                'status' => self::ORDER_RECHARGING,
                'supplier_order_no' => $supplier['order_no'],
                'update_time' => time(),
            ]);
            $status = self::ORDER_RECHARGING;
        } else {
            // 供货方下单失败 → 余额原路退回（退款流水），订单置 refunded
            $this->refundOrder($user->id, $orderNo, $costFen, '供货方下单失败:' . $supplier['msg']);
            return resp(ERROR_UN_KNOW, [
                'order_no' => $orderNo,
                'status' => self::ORDER_REFUNDED,
                'cost_price' => $this->ratePrice($costFen, $rate), // 分，对外报价=供货价×倍数
                'balance' => $this->balanceFen(DB::table('open_user')->where('id', $user->id)->value('balance')),
            ], '供货方发货提交失败，已退款：' . $supplier['msg']);
        }

        return resp(SUCCESS, [
            'order_no' => $orderNo,
            'status' => $status,
            'cost_price' => $this->ratePrice($costFen, $rate), // 分，对外报价=供货价×倍数（扣款按供货价）
            'balance' => $balanceAfter,
        ]);
    }

    /**
     * POST /api/open/v1/order/query 订单查询（归属校验 + 供货方状态同步，需签名）
     * 参数：order_no（+鉴权参数）
     */
    public function orderQuery(Request $request)
    {
        list($user, $fail, $keyRow) = $this->authApi($request);
        if ($fail) return $fail;
        $rate = $this->keyRate($keyRow);

        $orderNo = trim((string)$request->input('order_no', ''));
        if ($orderNo === '') return resp(ERROR_PARAMS, null, 'order_no 必填');

        // 归属校验：只查本人订单
        $order = DB::table('open_order')->where('order_no', $orderNo)->where('user_id', $user->id)->first();
        if (!$order) return resp(NULL_DATA, null, '订单不存在');

        // 充值中的订单向供货方同步最新状态
        if ($order->status === self::ORDER_RECHARGING) {
            $this->syncSupplierStatus($order);
            $order = DB::table('open_order')->where('order_no', $orderNo)->first();
        }

        return resp(SUCCESS, [
            'order_no' => $order->order_no,
            'goods_id' => (int)$order->goods_id,
            'goods_name' => $order->goods_name,
            'cost_price' => $this->ratePrice((int)$order->cost_price, $rate), // 分，供货价×倍数（库内存供货价）
            'recharge_account' => $order->recharge_account,
            'status' => $order->status,
            'supplier_order_no' => $order->supplier_order_no,
            'remark' => $order->remark,
            'created_time' => (int)$order->created_time,
            'update_time' => (int)$order->update_time,
        ]);
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 货源接口公开鉴权：匿名（不带 app_key）直接放行（price_rate=1.00），同 IP 限 60 次/分钟防爬；
     * 带 app_key 则走完整签名校验，返回该密钥的加价倍数。带 key 请求不受匿名限频限制。
     * 返回 [rate, 失败响应|null]
     */
    private function authPublicGoods(Request $request)
    {
        $appKey = trim((string)$request->input('app_key', ''));
        if ($appKey === '') {
            $cacheKey = 'openv1_pub_limit_' . md5((string)$request->ip()) . '_' . (int)floor(time() / 60);
            Cache::add($cacheKey, 0, 70);
            $count = Cache::increment($cacheKey);
            if ($count === false || $count > 60) {
                return [null, resp(ERROR_PARAMS, null, '请求过于频繁')];
            }
            return [1.0, null];
        }
        list($user, $fail, $keyRow) = $this->authApi($request);
        if ($fail) return [null, $fail];
        return [$this->keyRate($keyRow), null];
    }

    /**
     * app_key + sign 鉴权。返回 [user, 失败响应|null, keyRow|null]
     */
    private function authApi(Request $request)
    {
        $all = $request->all();
        $appKey = trim((string)($all['app_key'] ?? ''));
        $timestamp = trim((string)($all['timestamp'] ?? ''));
        $sign = strtolower(trim((string)($all['sign'] ?? '')));

        if ($appKey === '' || $timestamp === '' || $sign === '') {
            return [null, resp(ERROR_PARAMS, null, '缺少必填参数 app_key/timestamp/sign'), null];
        }
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > self::SIGN_TS_TOLERANCE) {
            return [null, resp(ERROR_PARAMS, null, 'timestamp 已过期或与服务器时间偏差过大'), null];
        }
        $keyRow = DB::table('open_api_key')->where('app_key', $appKey)->where('status', 1)->first();
        if (!$keyRow) {
            $this->apiLog(0, $appKey, $request, 0);
            return [null, resp(PERMISSION_DENIED, null, 'app_key 无效或已禁用'), null];
        }
        // 验签：参数名升序拼接 k=v（不含 sign、空值/数组跳过）+ &secret=app_secret
        $data = $all;
        unset($data['sign']);
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            if ($v === null || $v === '' || is_array($v) || is_object($v)) continue;
            $pairs[] = $k . '=' . $v;
        }
        $calc = md5(implode('&', $pairs) . '&secret=' . $keyRow->app_secret);
        if (!hash_equals($calc, $sign)) {
            $this->apiLog((int)$keyRow->user_id, $appKey, $request, 0);
            return [null, resp(ERROR_PARAMS, null, '签名错误'), null];
        }
        $user = DB::table('open_user')->where('id', $keyRow->user_id)->where('delete_time', 0)->first();
        if (!$user || (int)$user->status !== 1) {
            return [null, resp(PERMISSION_DENIED, null, '账户不可用'), null];
        }
        DB::table('open_api_key')->where('id', $keyRow->id)->update([
            'last_used_time' => time(),
            'update_time' => time(),
        ]);
        $this->apiLog((int)$user->id, $appKey, $request, 1);
        return [$user, null, $keyRow];
    }

    /**
     * 写调用日志
     */
    private function apiLog($userId, $appKey, Request $request, $status)
    {
        try {
            DB::table('open_api_log')->insert([
                'user_id' => (int)$userId,
                'app_key' => (string)$appKey,
                'api' => '/' . ltrim($request->path(), '/'),
                'method' => $request->method(),
                'ip' => (string)$request->ip(),
                'params' => json_encode($request->except(['sign', 'app_secret']), JSON_UNESCAPED_UNICODE),
                'status' => (int)$status,
                'created_time' => time(),
            ]);
        } catch (\Throwable $e) {}
    }

    /**
     * decimal(10,2) 元 → 分（两位小数无精度损失，直接乘 100 取整）
     */
    private function balanceFen($balanceYuan)
    {
        return (int)round(((float)$balanceYuan) * 100);
    }

    /**
     * 密钥加价倍数（异常/缺失值兜底 1.00）
     */
    private function keyRate($keyRow)
    {
        $rate = (float)($keyRow->price_rate ?? 1);
        if ($rate <= 0) $rate = 1.0;
        return $rate;
    }

    /**
     * 价格（分）按倍数换算，四舍五入取整
     */
    private function ratePrice($fen, $rate)
    {
        return (int)round($fen * $rate);
    }

    /**
     * 退款（幂等）：余额原路退回 + 退款流水 + 订单置 refunded。
     * 只在订单当前不是终态 success/refunded 时执行，由调用方控制并发语义。
     */
    private function refundOrder($userId, $orderNo, $costFen, $reason)
    {
        $costYuan = number_format($costFen / 100, 2, '.', '');
        $now = time();
        try {
            DB::beginTransaction();
            DB::table('open_user')->where('id', $userId)->update([
                'balance' => DB::raw('balance + ' . $costYuan),
                'update_time' => $now,
            ]);
            $balanceAfter = $this->balanceFen(DB::table('open_user')->where('id', $userId)->value('balance'));
            DB::table('open_balance_log')->insert([
                'user_id' => $userId,
                'change_type' => 'refund',
                'amount' => $costFen,
                'balance_after' => $balanceAfter,
                'order_no' => $orderNo,
                'remark' => $reason,
                'created_time' => $now,
            ]);
            DB::table('open_order')->where('order_no', $orderNo)->update([
                'status' => self::ORDER_REFUNDED,
                'remark' => $reason,
                'update_time' => $now,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
        }
    }

    /**
     * 向供货方同步订单状态并回写 open_order。
     * 供货方返回 failed/refunded → 条件更新占位（防并发重复退款）后自动退款。
     */
    private function syncSupplierStatus($order)
    {
        if (empty($order->supplier_order_no)) return;
        try {
            $supplier = (new SupplierService())->orderQuery($order->supplier_order_no);
            if (!$supplier) return;
            $status = (string)($supplier['status'] ?? '');
            if ($status === 'success') {
                DB::table('open_order')->where('order_no', $order->order_no)
                    ->where('status', self::ORDER_RECHARGING)
                    ->update(['status' => self::ORDER_SUCCESS, 'update_time' => time()]);
            } elseif ($status === 'failed' || $status === 'refunded') {
                // 条件更新占位：只有从 recharging 成功翻转为 failed 的请求才负责退款（幂等防重）
                $claimed = DB::table('open_order')->where('order_no', $order->order_no)
                    ->where('status', self::ORDER_RECHARGING)
                    ->update(['status' => self::ORDER_FAILED, 'update_time' => time()]);
                if ($claimed === 1) {
                    $this->refundOrder($order->user_id, $order->order_no, (int)$order->cost_price,
                        '供货方充值失败，自动退款');
                }
            }
        } catch (\Throwable $e) {
            // 同步失败不影响查询返回，下次再同步
        }
    }
}
