<?php
namespace App\Http\Controllers\open;

use App\Http\Controllers\Controller;
use App\Http\Services\api\AlipayService;
use App\Http\Services\api\WxpayService;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 卡世界开放平台 · 余额充值 + 管理员（token 体系同 OpenPlatformController）
 * 路由前缀 /api/open/platform。
 *
 * 充值链路（不用异步回调，主动查单）：
 *   微信 Native 下单拿 code_url / 支付宝当面付 precreate 拿 qr_code（兜底 wapPay 链接）；
 *   前端轮询 recharge/status，后端每次被轮询时主动向微信/支付宝查单，
 *   查到已支付 → 事务：条件更新充值单(防重) + open_user.balance 原子加 + open_balance_log 写 recharge 流水。
 *   微信/支付宝回调地址仅做 ACK + 顺带触发主动查单（不信任回调数据）。
 * 支付配置：微信读 .env（OPEN_WX_*，WxpayService），支付宝读 config/alipay.php（.env）。均无硬编码密钥。
 * 金额统一分计算；open_user.balance 为 decimal(10,2) 元，按 分/100 两位小数精确换算。
 * 纯新增控制器，不影响现有任何业务。
 */
class OpenRechargeController extends Controller
{
    /** 充值单状态 */
    const RC_PENDING = 0;
    const RC_PAID = 1;
    const RC_CLOSED = 2;

    /** 最低充值 1 元 */
    const MIN_AMOUNT_FEN = 100;

    /**
     * POST /api/open/platform/recharge 发起充值（需 token）
     * 入：{amount(元,最低1元,最多两位小数), pay_type(wechat/alipay)}
     * 出：{order_no, pay_qrcode?, pay_url?}
     */
    public function create(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $amount = trim((string)$request->input('amount', ''));
        $payType = trim((string)$request->input('pay_type', ''));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return resp(ERROR_PARAMS, null, '金额格式不正确（元，最多两位小数）');
        }
        $amountFen = (int)round(((float)$amount) * 100);
        if ($amountFen < self::MIN_AMOUNT_FEN) {
            return resp(ERROR_PARAMS, null, '充值金额最低1元');
        }
        if (!in_array($payType, ['wechat', 'alipay'], true)) {
            return resp(ERROR_PARAMS, null, 'pay_type 仅支持 wechat/alipay');
        }

        $now = time();
        $orderNo = serviceOrderNo('RC');
        DB::table('open_recharge')->insert([
            'user_id' => $user->id,
            'order_no' => $orderNo,
            'amount' => $amountFen,
            'pay_type' => $payType,
            'status' => self::RC_PENDING,
            'transaction_id' => '',
            'paid_time' => 0,
            'created_time' => $now,
            'update_time' => $now,
        ]);

        $notifyUrl = url('/api/open/platform/recharge/notify');
        $subject = '开放平台余额充值';

        if ($payType === 'wechat') {
            // 微信 Native 下单（复用现有 WxpayService 商户配置）
            $res = (new WxpayService())->wechatpay('', $orderNo, $subject, $amountFen, 'NATIVE', $notifyUrl);
            if (!$res || empty($res['code_url'])) {
                $this->closeRecharge($orderNo, '微信下单失败');
                return resp(ERROR_UN_KNOW, null, '微信支付下单失败，请稍后重试');
            }
            return resp(SUCCESS, ['order_no' => $orderNo, 'pay_qrcode' => $res['code_url'], 'pay_url' => '']);
        }

        // 支付宝：优先当面付预下单出二维码；未签约/失败则兜底电脑/手机网站支付链接
        $qr = '';
        try {
            $pre = (new AlipayService())->precreate($orderNo, $subject, $amountFen, $notifyUrl);
            $qr = (string)($pre['qr_code'] ?? '');
        } catch (\Throwable $e) {
            $qr = '';
        }
        if ($qr !== '') {
            return resp(SUCCESS, ['order_no' => $orderNo, 'pay_qrcode' => $qr, 'pay_url' => '']);
        }
        $payUrl = (new AlipayService())->wapPay($orderNo, $subject, $amountFen, $notifyUrl);
        if (!$payUrl) {
            $this->closeRecharge($orderNo, '支付宝下单失败');
            return resp(ERROR_UN_KNOW, null, '支付宝下单失败，请稍后重试');
        }
        return resp(SUCCESS, ['order_no' => $orderNo, 'pay_qrcode' => '', 'pay_url' => $payUrl]);
    }

    /**
     * GET /api/open/platform/recharge/status 充值状态（需 token，轮询时主动查单结算）
     * 入：{order_no} 出：{status, paid_time?}
     */
    public function status(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $orderNo = trim((string)$request->input('order_no', ''));
        $rc = DB::table('open_recharge')->where('order_no', $orderNo)->where('user_id', $user->id)->first();
        if (!$rc) return resp(NULL_DATA, null, '充值单不存在');

        if ((int)$rc->status === self::RC_PENDING) {
            $this->queryAndSettle($rc);
            $rc = DB::table('open_recharge')->where('order_no', $orderNo)->first();
        }
        return resp(SUCCESS, [
            'status' => (int)$rc->status,
            'paid_time' => (int)$rc->paid_time,
        ]);
    }

    /**
     * GET /api/open/platform/recharge/list 我的充值记录（需 token）
     */
    public function list(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $current = max(1, (int)$request->input('current', 1));
        $size = min(100, max(1, (int)$request->input('size', 20)));
        $query = DB::table('open_recharge')->where('user_id', $user->id);
        $total = (clone $query)->count();
        $rows = $query->orderBy('id', 'desc')->offset(($current - 1) * $size)->limit($size)->get();
        $list = [];
        foreach ($rows as $r) {
            $list[] = $this->rechargeData($r);
        }
        return resp(SUCCESS, [
            'list' => $list,
            'pagination' => ['total' => $total, 'size' => $size, 'current' => $current, 'pages' => (int)ceil($total / $size)],
        ]);
    }

    /**
     * POST /api/open/platform/recharge/notify 支付回调 ACK（无需 token，不信任回调数据）
     * 平台收款商户的异步通知统一落到这里：只 ACK + 顺带主动查单结算，业务以主动查单为准。
     */
    public function notifyAck(Request $request)
    {
        // 微信 V2 回调为 XML；支付宝为表单。尽力解析出 out_trade_no 后主动查单
        try {
            $orderNo = '';
            $raw = file_get_contents('php://input');
            if ($raw && strpos($raw, '<xml>') !== false) {
                $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
                $data = $xml ? json_decode(json_encode($xml), true) : [];
                $orderNo = (string)($data['out_trade_no'] ?? '');
            } else {
                $orderNo = (string)$request->input('out_trade_no', '');
            }
            if ($orderNo !== '') {
                $rc = DB::table('open_recharge')->where('order_no', $orderNo)->first();
                if ($rc && (int)$rc->status === self::RC_PENDING) {
                    $this->queryAndSettle($rc);
                }
            }
        } catch (\Throwable $e) {
            // ACK 异常不影响返回
        }
        if ($request->isMethod('post') && strpos((string)$request->header('Content-Type'), 'xml') !== false) {
            return response('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>', 200, ['Content-Type' => 'application/xml']);
        }
        // 微信 XML 无 Content-Type 也按 XML 应答；支付宝要求纯文本 success
        $raw = file_get_contents('php://input');
        if ($raw && strpos($raw, '<xml>') !== false) {
            return response('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>', 200, ['Content-Type' => 'application/xml']);
        }
        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    /* ==================== 管理员接口（role=1） ==================== */

    /**
     * GET /api/open/platform/admin/recharges 全部充值记录（role=1）
     * 入：{status?, current=1, size=20}
     */
    public function adminRecharges(Request $request)
    {
        $admin = $this->authAdmin($request);
        if (!$admin) return $this->authFail($request);

        $current = max(1, (int)$request->input('current', 1));
        $size = min(100, max(1, (int)$request->input('size', 20)));
        $query = DB::table('open_recharge')
            ->leftJoin('open_user', 'open_user.id', '=', 'open_recharge.user_id');
        $status = $request->input('status', '');
        if ($status !== '' && in_array((int)$status, [0, 1, 2], true)) {
            $query->where('open_recharge.status', (int)$status);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('open_recharge.id', 'desc')
            ->offset(($current - 1) * $size)->limit($size)
            ->get(['open_recharge.*', 'open_user.mobile as user_mobile', 'open_user.name as user_name']);
        $list = [];
        foreach ($rows as $r) {
            $item = $this->rechargeData($r);
            $item['user_id'] = (int)$r->user_id;
            $item['user_mobile'] = $r->user_mobile ?? '';
            $item['user_name'] = $r->user_name ?? '';
            $list[] = $item;
        }
        return resp(SUCCESS, [
            'list' => $list,
            'pagination' => ['total' => $total, 'size' => $size, 'current' => $current, 'pages' => (int)ceil($total / $size)],
        ]);
    }

    /**
     * POST /api/open/platform/admin/balance 管理员手动调整余额（role=1）
     * 入：{user_id, amount(元,正加负减), remark?}
     * 事务 + 余额不为负校验；流水 change_type=recharge/deduct
     */
    public function adminBalance(Request $request)
    {
        $admin = $this->authAdmin($request);
        if (!$admin) return $this->authFail($request);

        $uid = (int)$request->input('user_id', 0);
        $amount = trim((string)$request->input('amount', ''));
        $remark = mb_substr(trim((string)$request->input('remark', '')), 0, 120);
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $amount) || (float)$amount == 0.0) {
            return resp(ERROR_PARAMS, null, '金额格式不正确（元，正加负减，最多两位小数，不为0）');
        }
        $fen = (int)round(((float)$amount) * 100);
        $target = DB::table('open_user')->where('id', $uid)->where('delete_time', 0)->first();
        if (!$target) return resp(NULL_DATA, null, '用户不存在');

        $now = time();
        try {
            DB::beginTransaction();
            if ($fen > 0) {
                $yuan = number_format($fen / 100, 2, '.', '');
                DB::table('open_user')->where('id', $uid)
                    ->update(['balance' => DB::raw('balance + ' . $yuan), 'update_time' => $now]);
                $changeType = 'recharge';
            } else {
                $yuan = number_format(abs($fen) / 100, 2, '.', '');
                // 原子扣减：余额不足则影响 0 行，保证不为负
                $affected = DB::table('open_user')->where('id', $uid)->where('balance', '>=', $yuan)
                    ->update(['balance' => DB::raw('balance - ' . $yuan), 'update_time' => $now]);
                if ($affected !== 1) {
                    DB::rollBack();
                    return resp(ERROR_PARAMS, null, '扣减失败：用户余额不足（不能扣成负数）');
                }
                $changeType = 'deduct';
            }
            $after = $this->balanceFen(DB::table('open_user')->where('id', $uid)->value('balance'));
            DB::table('open_balance_log')->insert([
                'user_id' => $uid,
                'change_type' => $changeType,
                'amount' => $fen,
                'balance_after' => $after,
                'order_no' => '',
                'remark' => '管理员调整:' . ($remark !== '' ? $remark : ('操作人#' . $admin->id)),
                'created_time' => $now,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return resp(ERROR_UN_KNOW, null, '操作失败：' . $e->getMessage());
        }
        return resp(SUCCESS, ['balance' => $after]); // 分
    }

    /**
     * GET /api/open/platform/admin/users 用户列表（role=1）
     */
    public function adminUsers(Request $request)
    {
        $admin = $this->authAdmin($request);
        if (!$admin) return $this->authFail($request);

        $current = max(1, (int)$request->input('current', 1));
        $size = min(100, max(1, (int)$request->input('size', 20)));
        $query = DB::table('open_user')->where('delete_time', 0);
        $kw = trim((string)$request->input('keyword', ''));
        if ($kw !== '') {
            $query->where(function ($q) use ($kw) {
                $q->where('mobile', 'like', '%' . $kw . '%')->orWhere('name', 'like', '%' . $kw . '%');
            });
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('id', 'desc')->offset(($current - 1) * $size)->limit($size)
            ->get(['id', 'name', 'mobile', 'balance', 'role', 'created_time']);
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id' => (int)$r->id,
                'name' => $r->name,
                'mobile' => $r->mobile,
                'balance' => (float)$r->balance, // 元
                'role' => (int)$r->role,
                'created_time' => (int)$r->created_time,
            ];
        }
        return resp(SUCCESS, [
            'list' => $list,
            'pagination' => ['total' => $total, 'size' => $size, 'current' => $current, 'pages' => (int)ceil($total / $size)],
        ]);
    }

    /* ==================== 查单结算核心 ==================== */

    /**
     * 主动向微信/支付宝查单，已支付则结算（幂等：条件更新充值单占位防重）
     * 供 recharge/status 轮询、回调 ACK、crontab 兜底共用。
     * @return bool 本次是否发生了结算
     */
    public function queryAndSettle($rc)
    {
        if ((int)$rc->status !== self::RC_PENDING) return false;
        try {
            if ($rc->pay_type === 'wechat') {
                $r = $this->wechatQuery($rc->order_no);
                if (!empty($r['paid'])) {
                    return $this->settle($rc, (string)($r['transaction_id'] ?? ''));
                }
            } elseif ($rc->pay_type === 'alipay') {
                $r = $this->alipayQuery($rc->order_no);
                if (!empty($r['paid'])) {
                    return $this->settle($rc, (string)($r['transaction_id'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            // 查单失败下轮再试
        }
        return false;
    }

    /**
     * 结算：事务内 条件更新充值单(防重) → 余额原子加 → recharge 流水
     */
    private function settle($rc, $transactionId)
    {
        $now = time();
        try {
            DB::beginTransaction();
            $claimed = DB::table('open_recharge')
                ->where('order_no', $rc->order_no)->where('status', self::RC_PENDING)
                ->update([
                    'status' => self::RC_PAID,
                    'transaction_id' => mb_substr($transactionId, 0, 64),
                    'paid_time' => $now,
                    'update_time' => $now,
                ]);
            if ($claimed !== 1) {
                DB::rollBack();
                return false; // 已被其他流程结算
            }
            $yuan = number_format(((int)$rc->amount) / 100, 2, '.', '');
            DB::table('open_user')->where('id', $rc->user_id)
                ->update(['balance' => DB::raw('balance + ' . $yuan), 'update_time' => $now]);
            $after = $this->balanceFen(DB::table('open_user')->where('id', $rc->user_id)->value('balance'));
            DB::table('open_balance_log')->insert([
                'user_id' => $rc->user_id,
                'change_type' => 'recharge',
                'amount' => (int)$rc->amount,
                'balance_after' => $after,
                'order_no' => $rc->order_no,
                'remark' => '余额充值(' . $rc->pay_type . ')',
                'created_time' => $now,
            ]);
            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            return false;
        }
    }

    /**
     * 微信查单（V2，WxpayService，配置走 .env）
     * 返回 ['paid'=>bool, 'transaction_id'=>string]
     */
    private function wechatQuery($orderNo)
    {
        return (new WxpayService())->queryByOutTradeNo($orderNo);
    }

    /**
     * 支付宝查单（alipay.trade.query，配置读 config/alipay.php，签名口径同 AlipayService）
     * 返回 ['paid'=>bool, 'transaction_id'=>string]
     */
    private function alipayQuery($orderNo)
    {
        $appId = (string)config('alipay.app_id', '');
        $privateKey = (string)config('alipay.private_key', '');
        $gateway = (string)config('alipay.gateway', 'https://openapi.alipay.com/gateway.do');
        if (!$appId || !$privateKey) return ['paid' => false, 'transaction_id' => ''];

        $params = [
            'app_id' => $appId,
            'method' => 'alipay.trade.query',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode(['out_trade_no' => $orderNo]),
        ];
        // RSA2 签名：参数按 key 升序 key=value 原值 & 连接
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) continue;
            $pairs[] = $k . '=' . $v;
        }
        $body = wordwrap(trim($privateKey), 64, "\n", true);
        $res = false;
        foreach (['RSA PRIVATE KEY', 'PRIVATE KEY'] as $label) {
            $res = openssl_pkey_get_private("-----BEGIN {$label}-----\n{$body}\n-----END {$label}-----");
            if ($res) break;
        }
        if (!$res) return ['paid' => false, 'transaction_id' => ''];
        openssl_sign(implode('&', $pairs), $sign, $res, OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($sign);

        $client = new Client(['timeout' => 15]);
        $raw = (string)$client->post($gateway, ['form_params' => $params])->getBody();
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');
        }
        $resp = json_decode($raw, true);
        $qr = $resp['alipay_trade_query_response'] ?? [];
        if (($qr['code'] ?? '') === '10000'
            && in_array(($qr['trade_status'] ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return ['paid' => true, 'transaction_id' => (string)($qr['trade_no'] ?? '')];
        }
        return ['paid' => false, 'transaction_id' => ''];
    }

    /* ==================== 工具方法 ==================== */

    private function rechargeData($r)
    {
        return [
            'id' => (int)$r->id,
            'order_no' => $r->order_no,
            'amount' => (int)$r->amount, // 分
            'pay_type' => $r->pay_type,
            'status' => (int)$r->status,
            'transaction_id' => $r->transaction_id ?? '',
            'paid_time' => (int)$r->paid_time,
            'created_time' => (int)$r->created_time,
        ];
    }

    private function closeRecharge($orderNo, $reason)
    {
        DB::table('open_recharge')->where('order_no', $orderNo)->where('status', self::RC_PENDING)
            ->update(['status' => self::RC_CLOSED, 'update_time' => time()]);
    }

    private function balanceFen($balanceYuan)
    {
        return (int)round(((float)$balanceYuan) * 100);
    }

    /**
     * token 认证（与 OpenPlatformController 同体系）
     */
    private function authUser(Request $request)
    {
        $token = trim((string)$request->header('token', ''));
        if ($token === '') return null;
        $uid = \Illuminate\Support\Facades\Cache::get('open_platform_token_' . $token);
        if (!$uid) return null;
        $user = DB::table('open_user')->where('id', (int)$uid)->where('delete_time', 0)->first();
        if (!$user || (int)$user->status !== 1) return null;
        return $user;
    }

    /**
     * 管理员认证：token 有效且 role=1，否则返回 null（调用方返回 code=6 无权限）
     */
    private function authAdmin(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return null;
        if ((int)$user->role !== 1) return null;
        return $user;
    }

    private function authFail(Request $request)
    {
        $token = trim((string)$request->header('token', ''));
        if ($token === '') return resp(MISS_TOKEN, null, 'token为空');
        // 区分无效 token 与无权限：有 token 但非管理员 → code=6
        $uid = \Illuminate\Support\Facades\Cache::get('open_platform_token_' . $token);
        if ($uid) return resp(NULL_DATA, null, '无权限');
        return resp(ERROR_TOKEN, null, '登录失效，请重新登录');
    }
}
