<?php

namespace App\Http\Services\api;

use EasyWeChat\Factory;

/**
 * 微信支付服务（开放平台专用精简版，V2 接口，EasyWeChat SDK）
 * 商户配置全部走 .env：
 *   OPEN_WX_APPID      公众号/开放平台 appid
 *   OPEN_WX_MCH_ID     商户号
 *   OPEN_WX_API_KEY    V2 API 密钥
 *   OPEN_WX_CERT_PATH  apiclient_cert.pem 路径（仅退款等需要，下单/查单不用）
 *   OPEN_WX_KEY_PATH   apiclient_key.pem 路径
 * 依赖：composer require overtrue/wechat:~5.0
 */
class WxpayService
{
    private $appid;
    private $mch_id;
    private $api_key;

    public function __construct()
    {
        $this->appid = (string)env('OPEN_WX_APPID', '');
        $this->mch_id = (string)env('OPEN_WX_MCH_ID', '');
        $this->api_key = (string)env('OPEN_WX_API_KEY', '');
    }

    /**
     * 统一下单（默认 NATIVE 扫码）
     * @param string $openid     NATIVE 模式传 ''
     * @param string $order_no   商户订单号
     * @param string $goods_name 商品描述
     * @param int    $goods_price 金额（分）
     * @param string $pay_type   NATIVE / JSAPI
     * @param string $notifyUrl  异步通知地址
     * @return array|false 成功返回统一下单响应（NATIVE 含 code_url）
     */
    public function wechatpay($openid, $order_no, $goods_name, $goods_price, $pay_type = 'NATIVE', $notifyUrl = '')
    {
        $app = $this->paymentApp();
        $attributes = [
            'body' => $goods_name,
            'out_trade_no' => $order_no,
            'total_fee' => intval($goods_price),
            'notify_url' => $notifyUrl,
            'trade_type' => $pay_type,
        ];
        if ($openid) $attributes['openid'] = $openid;
        $result = $app->order->unify($attributes);
        if (($result['return_code'] ?? '') === 'SUCCESS' && ($result['result_code'] ?? '') === 'SUCCESS') {
            return $result;
        }
        return false;
    }

    /**
     * 按商户订单号查单
     * @return array ['paid'=>bool, 'transaction_id'=>string]
     */
    public function queryByOutTradeNo($orderNo)
    {
        $result = $this->paymentApp()->order->queryByOutTradeNumber($orderNo);
        if (($result['return_code'] ?? '') === 'SUCCESS'
            && ($result['result_code'] ?? '') === 'SUCCESS'
            && ($result['trade_state'] ?? '') === 'SUCCESS') {
            return ['paid' => true, 'transaction_id' => (string)($result['transaction_id'] ?? '')];
        }
        return ['paid' => false, 'transaction_id' => ''];
    }

    private function paymentApp()
    {
        return Factory::payment([
            'app_id' => $this->appid,
            'mch_id' => $this->mch_id,
            'key' => $this->api_key,
            'cert_path' => env('OPEN_WX_CERT_PATH', storage_path('app/apiclient_cert.pem')),
            'key_path' => env('OPEN_WX_KEY_PATH', storage_path('app/apiclient_key.pem')),
            'log' => [
                'default' => 'single',
                'channels' => ['single' => ['driver' => 'single', 'path' => storage_path('logs/easywechat.log'), 'level' => 'error']],
            ],
        ]);
    }
}
