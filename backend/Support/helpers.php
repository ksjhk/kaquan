<?php
/**
 * 卡世界开放平台 · 最小辅助函数集（从商城 helpers.php 抽取）
 * 使用方式：composer.json autoload.files 加入本文件，或 AppServiceProvider::register() 中 require。
 *
 * 说明：原商城体系通过 AppServiceProvider 读取 config('message') 自动 define 常量。
 * 本开源版自带同一份 code 映射（Support/message.php），在此直接 define，零依赖可用。
 */

if (!defined('SUCCESS')) {
    foreach (require __DIR__ . '/message.php' as $code => $item) {
        define($item['code'], $code);
    }
}

if (!function_exists('get_message')) {
    /**
     * 按 code 取默认提示语
     */
    function get_message($code)
    {
        $map = require __DIR__ . '/message.php';
        return $map[$code]['msg'] ?? '未知错误';
    }
}

if (!function_exists('resp')) {
    /**
     * 统一响应：code=0 成功
     */
    function resp($code, $data = [], $msg = '')
    {
        if (!$msg) {
            $msg = get_message($code);
        }
        $map = require __DIR__ . '/message.php';
        if (!array_key_exists($code, $map)) $code = ERROR_UN_KNOW;
        return response(['code' => (int)$code, 'message' => $msg, 'data' => $data]);
    }
}

if (!function_exists('serviceOrderNo')) {
    /**
     * 生成唯一订单号：前缀 + YYYYMMDDHHIISS + 4位随机 + 2位校验码（共 pre+20 位）
     */
    function serviceOrderNo($pre = '')
    {
        $order_id_main = date('YmdHis') . rand(1000, 9999);
        $order_id_sum = 0;
        for ($i = 0, $len = strlen($order_id_main); $i < $len; $i++) {
            $order_id_sum += (int)substr($order_id_main, $i, 1);
        }
        return $pre . $order_id_main . str_pad((100 - $order_id_sum % 100) % 100, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('curl_query')) {
    /**
     * 通用 POST JSON 请求，返回数组（JSON 解码失败则返回原始字符串）
     */
    function curl_query($url, $data = [], $dataType = 'array', $header = [])
    {
        if (is_array($data)) $data = json_encode($data);
        $protocol = substr($url, 0, 5);
        $attributes = ['Content-Type:application/json'];
        if ($header) {
            $options = [];
            foreach ($header as $key => $value) {
                $options[] = $key . ":" . $value;
            }
            $attributes = array_merge($options, $attributes);
        }
        $ch = curl_init();
        if ('https' == $protocol) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $attributes);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($dataType === 'json') {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        } else {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($ch);
        curl_close($ch);
        $re = json_decode($result, true);
        return $re ?: $result;
    }
}

if (!function_exists('generate_code')) {
    /**
     * 生成指定位数数字验证码
     */
    function generate_code($length = 6)
    {
        return rand(pow(10, ($length - 1)), pow(10, $length) - 1);
    }
}
