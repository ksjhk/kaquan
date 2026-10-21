<?php

namespace App\Http\Services\api;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * 支付宝支付（手机网站支付 alipay.trade.wap.pay）
 * 不引入第三方 SDK：WAP 支付 = 签名拼接跳转 URL，回调 = RSA2 验签，退款 = 一次表单 POST
 * 配置全部走 config/alipay.php（.env），不硬编码
 */
class AlipayService
{
    private $appId;
    private $privateKey;
    private $publicKey;
    private $gateway;

    public function __construct()
    {
        $this->appId      = (string)config('alipay.app_id', '');
        $this->privateKey = (string)config('alipay.private_key', '');
        $this->publicKey  = (string)config('alipay.public_key', '');
        $this->gateway    = (string)config('alipay.gateway', 'https://openapi.alipay.com/gateway.do');
    }

    /**
     * 手机网站支付：生成跳转支付宝收银台的完整 URL（前端 window.location 跳转即可）
     * @param string $orderNo   我方订单号（out_trade_no）
     * @param string $subject   商品标题
     * @param int    $amountFen 金额，单位：分
     * @param string $notifyUrl 异步回调地址（用 url('/api/open_api/alipay/order/notify') 按当前域名生成）
     * @param string $returnUrl 同步回跳地址（可为空）
     * @return string
     */
    public function wapPay($orderNo, $subject, $amountFen, $notifyUrl, $returnUrl = '')
    {
        $bizContent = json_encode([
            'out_trade_no' => $orderNo,
            'total_amount' => number_format($amountFen / 100, 2, '.', ''),
            'subject'      => $subject,
            'product_code' => 'QUICK_WAP_WAY',
        ], JSON_UNESCAPED_UNICODE);

        $params = [
            'app_id'      => $this->appId,
            'method'      => 'alipay.trade.wap.pay',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => $notifyUrl,
            'biz_content' => $bizContent,
        ];
        if ($returnUrl) {
            $params['return_url'] = $returnUrl;
        }
        $params['sign'] = $this->sign($params);

        return $this->gateway . '?' . http_build_query($params);
    }

    /**
     * 当面付预下单（alipay.trade.precreate）：生成可被支付宝App直接扫码付款的二维码内容
     * 用于代客下单的扫码收款场景（WAP支付URL是浏览器跳转链接，支付宝扫码不识别，表现为"扫码无响应"）
     * 注意：应用需在支付宝开放平台签约"当面付"产品，否则返回 isv 权限类错误
     * @return array alipay_trade_precreate_response 节点；成功 code=10000 且含 qr_code
     */
    public function precreate($orderNo, $subject, $amountFen, $notifyUrl)
    {
        try {
            // 不加 JSON_UNESCAPED_UNICODE：中文转 \uXXXX 纯 ASCII 传输。
            // 实测该网关解析 POST 表单时不按 body 里的 charset 解码多字节参数，
            // 裸中文会导致支付宝验签字节与我方签名字节不一致（isv.invalid-signature）
            $bizContent = json_encode([
                'out_trade_no' => $orderNo,
                'total_amount' => number_format($amountFen / 100, 2, '.', ''),
                'subject'      => $subject,
            ]);

            $params = [
                'app_id'      => $this->appId,
                'method'      => 'alipay.trade.precreate',
                'format'      => 'JSON',
                'charset'     => 'utf-8',
                'sign_type'   => 'RSA2',
                'timestamp'   => date('Y-m-d H:i:s'),
                'version'     => '1.0',
                'notify_url'  => $notifyUrl,
                'biz_content' => $bizContent,
            ];
            $params['sign'] = $this->sign($params);

            $client = new Client(['timeout' => 15]);
            $response = $client->post($this->gateway, ['form_params' => $params]);
            $raw = (string)$response->getBody();
            // 落原始报文，排查签约/签名/配置问题全靠它
            $this->safeLog('info', '支付宝当面付预下单-响应', ['order_no' => $orderNo, 'http' => $response->getStatusCode(), 'raw' => mb_substr($raw, 0, 2000)]);

            $body = json_decode($raw, true);
            if (!is_array($body)) {
                // 兼容 BOM / 非法 UTF-8 字节导致的解析失败
                $body = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', trim($raw)), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            }
            if (!is_array($body)) {
                $this->safeLog('error', '支付宝当面付预下单-响应非JSON', ['order_no' => $orderNo, 'raw' => mb_substr($raw, 0, 2000)]);
                return [];
            }
            $res = $body['alipay_trade_precreate_response'] ?? [];
            // 网关级错误（签名错误/无权限/参数非法）返回的是 error_response 节点，一并透出
            if (!$res && isset($body['error_response'])) {
                $res = $body['error_response'];
                $this->safeLog('error', '支付宝当面付预下单-网关错误', ['order_no' => $orderNo, 'result' => $res]);
            }
            if (($res['code'] ?? '') !== '10000') {
                $this->safeLog('error', '支付宝当面付预下单-业务失败', ['order_no' => $orderNo, 'result' => $res]);
            }
            return $res;
        } catch (\Throwable $e) {
            $this->safeLog('error', '支付宝当面付预下单-异常', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'app_id' => $this->appId,
                'gateway' => $this->gateway,
                'private_key_len' => strlen($this->privateKey),
                'public_key_len' => strlen($this->publicKey),
            ]);
            return [];
        }
    }

    /**
     * 异步通知验签（RSA2 / SHA256）
     * @param array $params 回调全部 POST 参数
     */
    public function verifyNotify(array $params): bool
    {
        $sign = $params['sign'] ?? '';
        if (!$sign || !$this->publicKey) {
            return false;
        }
        unset($params['sign'], $params['sign_type']);
        $content = $this->buildSignContent($params);

        $pem = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($this->publicKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        $res = openssl_pkey_get_public($pem);
        if (!$res) {
            $this->safeLog('error', '支付宝回调验签：公钥解析失败');
            return false;
        }
        $ok = openssl_verify($content, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    /**
     * 退款（alipay.trade.refund，资金原路退回）
     * @param string $orderNo   我方订单号
     * @param float  $amountYuan 退款金额，单位：元
     * @param string $refundNo  退款请求号（幂等，同一单重复退款用同一号）
     * @return array alipay_trade_refund_response 节点；成功且资金变动时 code=10000 且 fund_change=Y
     */
    public function refund($orderNo, $amountYuan, $refundNo)
    {
        $bizContent = json_encode([
            'out_trade_no'   => $orderNo,
            'refund_amount'  => number_format($amountYuan, 2, '.', ''),
            'out_request_no' => $refundNo,
        ], JSON_UNESCAPED_UNICODE);

        $params = [
            'app_id'      => $this->appId,
            'method'      => 'alipay.trade.refund',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => $bizContent,
        ];
        $params['sign'] = $this->sign($params);

        try {
            $client = new Client(['timeout' => 15]);
            $response = $client->post($this->gateway, ['form_params' => $params]);
            $raw = (string)$response->getBody();
            // 网关应答含中文 sub_msg 时实际是 GBK 字节，直接 json_decode 会 Malformed UTF-8 返 null（20260822 实测）
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');
            }
            $body = json_decode($raw, true);
            return $body['alipay_trade_refund_response'] ?? [];
        } catch (\Throwable $e) {
            $this->safeLog('error', '支付宝退款请求异常', ['order_no' => $orderNo, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 支付/回调链路安全日志：storage 权限或磁盘异常时静默跳过，绝不让日志问题打断支付主流程
     */
    private function safeLog($level, $message, $context = [])
    {
        try {
            Log::$level($message, $context);
        } catch (\Throwable $e) {
            // 日志失败不影响支付主流程
        }
    }

    /**
     * RSA2 签名
     */
    private function sign(array $params): string
    {
        $content = $this->buildSignContent($params);
        $res = $this->loadPrivateKey();
        openssl_sign($content, $sign, $res, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /**
     * 待签名内容：参数按 key 升序，key=value 原始值用 & 连接（不做 urlencode）
     */
    private function buildSignContent(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return implode('&', $pairs);
    }

    /**
     * 加载应用私钥：支付宝密钥工具导出的可能是 PKCS#1（BEGIN RSA PRIVATE KEY）
     * 或 PKCS#8（BEGIN PRIVATE KEY），两种头都试，取第一个能解析的
     */
    private function loadPrivateKey()
    {
        $body = wordwrap(trim($this->privateKey), 64, "\n", true);
        foreach (['RSA PRIVATE KEY', 'PRIVATE KEY'] as $label) {
            $pem = "-----BEGIN {$label}-----\n{$body}\n-----END {$label}-----";
            $res = openssl_pkey_get_private($pem);
            if ($res) {
                return $res;
            }
        }
        // 让 openssl 抛出可读错误，便于排查密钥格式问题
        throw new \RuntimeException('支付宝应用私钥解析失败，请检查 ALIPAY_PRIVATE_KEY 格式');
    }

    /**
     * 向 URL 追加查询参数（兼容 hash 路由：参数追加到 # 之后的片段里）
     * 例：https://x.cn/#/pages/paydone?a=1 + order_no=2 → https://x.cn/#/pages/paydone?a=1&order_no=2
     */
    public static function appendQuery($url, array $params): string
    {
        $query = http_build_query($params);
        if (!$query) {
            return $url;
        }
        $hashPos = strpos($url, '#');
        $hash = $hashPos === false ? '' : substr($url, $hashPos);
        $base = $hashPos === false ? $url : substr($url, 0, $hashPos);

        if ($hash !== '') {
            // hash 路由：支付宝回跳追加的参数也会落在 hash 片段后，保持一致
            return $base . $hash . (strpos($hash, '?') === false ? '?' : '&') . $query;
        }
        return $base . (strpos($base, '?') === false ? '?' : '&') . $query;
    }
}
