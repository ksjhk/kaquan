<?php

/*
 * 支付宝支付配置
 * 放到 Laravel 项目的 config/alipay.php；密钥在 .env 中维护（ALIPAY_*），不要写死在此文件
 */
return [
    // 应用 APPID
    'app_id' => env('ALIPAY_APP_ID', ''),

    // 应用私钥（单行 base64，PKCS#8/PKCS#1 均可，AlipayService 自动适配）
    'private_key' => env('ALIPAY_PRIVATE_KEY', ''),

    // 支付宝公钥（验签回调用，开放平台「接口加签方式」处下载）
    'public_key' => env('ALIPAY_PUBLIC_KEY', ''),

    // 网关：正式 https://openapi.alipay.com/gateway.do ；沙箱 https://openapi.alipaydev.com/gateway.do
    'gateway' => env('ALIPAY_GATEWAY', 'https://openapi.alipay.com/gateway.do'),

    // H5 支付完成后的同步回跳兜底地址
    'return_url' => env('ALIPAY_RETURN_URL', ''),
];
