<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Http;

/**
 * 卡世界供货客户端
 *
 * 对接「卡世界开放平台」对外 API（open-api v1），为本平台提供卡券货源查询与自动发货能力。
 * 供货凭证：前往 kashijie.com 注册开放平台账号、创建 API 密钥（app_key / app_secret）即可获得，
 * 成本价结算，随充随用。
 *
 * 签名规则（与卡世界 v1 文档一致）：
 *   sign = md5( 全部参数按参数名升序拼接 "k1=v1&k2=v2"（排除 sign、空值跳过） + "&secret=" + app_secret )
 *
 * 配置（.env）：
 *   SUPPLIER_API_BASE     供货接口地址，默认 https://www.kashijie.com/open-api/v1
 *   SUPPLIER_APP_KEY      供货密钥 app_key
 *   SUPPLIER_APP_SECRET   供货密钥 app_secret
 */
class SupplierService
{
    private $base;
    private $appKey;
    private $appSecret;

    public function __construct()
    {
        $this->base = rtrim((string)env('SUPPLIER_API_BASE', 'https://www.kashijie.com/open-api/v1'), '/');
        $this->appKey = (string)env('SUPPLIER_APP_KEY', '');
        $this->appSecret = (string)env('SUPPLIER_APP_SECRET', '');
    }

    /**
     * 货源列表 GET /goods
     * @return array|null {list:[...], pagination:{...}}，失败 null
     */
    public function goodsList($categoryId = '', $current = 1, $size = 20)
    {
        $data = $this->get('/goods', [
            'category_id' => (string)$categoryId,
            'current' => (int)$current,
            'size' => (int)$size,
        ]);
        return (is_array($data) && isset($data['list'])) ? $data : null;
    }

    /**
     * 商品详情 GET /goods/detail
     * @return array|null 商品字段数组，失败 null
     */
    public function goodsDetail($goodsId)
    {
        $data = $this->get('/goods/detail', ['goods_id' => (int)$goodsId]);
        return (is_array($data) && !empty($data['goods_id'])) ? $data : null;
    }

    /**
     * 创建订单（供货方直充发货）POST /order/create
     * @return array ['ok'=>bool, 'order_no'=>供货方订单号, 'status'=>..., 'msg'=>...]
     */
    public function orderCreate($goodsId, $rechargeAccount)
    {
        $resp = $this->post('/order/create', [
            'goods_id' => (int)$goodsId,
            'recharge_account' => (string)$rechargeAccount,
        ]);
        if (is_array($resp) && ($resp['code'] ?? -1) === 0) {
            return [
                'ok' => true,
                'order_no' => (string)($resp['data']['order_no'] ?? ''),
                'status' => (string)($resp['data']['status'] ?? ''),
                'msg' => '',
            ];
        }
        return [
            'ok' => false,
            'order_no' => '',
            'status' => '',
            'msg' => (string)($resp['message'] ?? '供货下单失败'),
        ];
    }

    /**
     * 订单查询 POST /order/query
     * @return array|null 供货方订单数组（status: pending/recharging/success/failed/refunded），失败 null
     */
    public function orderQuery($supplierOrderNo)
    {
        $resp = $this->post('/order/query', ['order_no' => (string)$supplierOrderNo]);
        if (is_array($resp) && ($resp['code'] ?? -1) === 0 && !empty($resp['data']['order_no'])) {
            return $resp['data'];
        }
        return null;
    }

    /* ==================== 内部 ==================== */

    private function get($path, array $params)
    {
        try {
            $resp = Http::timeout(10)->get($this->base . $path, $this->signParams($params));
            $json = $resp->json();
            return (is_array($json) && ($json['code'] ?? -1) === 0) ? ($json['data'] ?? null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function post($path, array $params)
    {
        try {
            $resp = Http::timeout(15)->asJson()->post($this->base . $path, $this->signParams($params));
            $json = $resp->json();
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 追加 app_key / timestamp / sign
     */
    private function signParams(array $params)
    {
        $params['app_key'] = $this->appKey;
        $params['timestamp'] = (string)time();
        unset($params['sign']);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '' || is_array($v) || is_object($v)) continue;
            $pairs[] = $k . '=' . $v;
        }
        $params['sign'] = md5(implode('&', $pairs) . '&secret=' . $this->appSecret);
        return $params;
    }
}
