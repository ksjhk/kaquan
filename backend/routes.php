<?php

/**
 * 卡世界开放平台 · 路由片段
 * 使用方式：把本文件内容追加到 Laravel 项目的 routes/api.php 末尾即可。
 * （Laravel 8+ 写法；控制器命名空间 App\Http\Controllers\open）
 */

/**
 * 卡世界开放平台 · 用户端接口（独立 token 体系）
 * 前缀 /api/open/platform，token 存 Cache 有效期 7 天，Header 带 `token: xxx`。
 * 与商城买家、后台管理员体系完全隔离。纯新增段落，不影响现有任何业务。
 */
Route::prefix('open/platform')->group(function () {
    Route::post('sms', [\App\Http\Controllers\open\OpenPlatformController::class, 'sms']);
    Route::post('register', [\App\Http\Controllers\open\OpenPlatformController::class, 'register']);
    Route::post('login', [\App\Http\Controllers\open\OpenPlatformController::class, 'login']);
    Route::get('profile', [\App\Http\Controllers\open\OpenPlatformController::class, 'profile']);
    Route::post('profile', [\App\Http\Controllers\open\OpenPlatformController::class, 'updateProfile']);
    Route::get('dashboard', [\App\Http\Controllers\open\OpenPlatformController::class, 'dashboard']);
    Route::get('apikey', [\App\Http\Controllers\open\OpenPlatformController::class, 'apiKeyList']);
    Route::post('apikey', [\App\Http\Controllers\open\OpenPlatformController::class, 'apiKeyCreate']);
    Route::post('apikey/status', [\App\Http\Controllers\open\OpenPlatformController::class, 'apiKeyStatus']);
    Route::get('logs', [\App\Http\Controllers\open\OpenPlatformController::class, 'logs']);
    Route::get('invite', [\App\Http\Controllers\open\OpenPlatformController::class, 'invite']);


    // 余额充值（主动查单结算）+ 管理员（role=1）
    Route::post('recharge', [\App\Http\Controllers\open\OpenRechargeController::class, 'create']);
    Route::get('recharge/status', [\App\Http\Controllers\open\OpenRechargeController::class, 'status']);
    Route::get('recharge/list', [\App\Http\Controllers\open\OpenRechargeController::class, 'list']);
    Route::post('recharge/notify', [\App\Http\Controllers\open\OpenRechargeController::class, 'notifyAck']); // 支付回调ACK（不依赖回调，仅顺带触发主动查单）
    Route::get('admin/recharges', [\App\Http\Controllers\open\OpenRechargeController::class, 'adminRecharges']);
    Route::post('admin/balance', [\App\Http\Controllers\open\OpenRechargeController::class, 'adminBalance']);
    Route::get('admin/users', [\App\Http\Controllers\open\OpenRechargeController::class, 'adminUsers']);

    // 验证码登录（未注册自动注册）
    Route::post('sms-login-code', [\App\Http\Controllers\open\OpenPlatformController::class, 'smsLoginCode']);
    Route::post('login-sms', [\App\Http\Controllers\open\OpenPlatformController::class, 'loginSms']);

    // 平台公告
    Route::get('notices', [\App\Http\Controllers\open\OpenPlatformController::class, 'notices']);
    Route::post('admin/notices', [\App\Http\Controllers\open\OpenPlatformController::class, 'adminNoticeCreate']);
});


/**
 * 卡世界开放平台 · 对外业务 API v1（B端代理商 app_key/app_secret 签名鉴权）
 * 前缀 /api/open/v1。sign = md5(参数名升序 k1=v1&k2=v2（不含sign、空值跳过） + "&secret=" + app_secret)
 * 货源与发货走「卡世界供货 API」（SupplierService，.env 配置凭证）；
 * 下单扣 open_user.balance + open_balance_log 流水 + open_order 映射，失败自动退款。
 * 纯新增段落，不影响现有任何业务。
 */
Route::prefix('open/v1')->group(function () {
    Route::get('goods', [\App\Http\Controllers\open\OpenApiV1Controller::class, 'goods']);
    Route::get('goods/detail', [\App\Http\Controllers\open\OpenApiV1Controller::class, 'goodsDetail']);
    Route::get('balance', [\App\Http\Controllers\open\OpenApiV1Controller::class, 'balance']);
    Route::post('order/create', [\App\Http\Controllers\open\OpenApiV1Controller::class, 'orderCreate']);
    Route::post('order/query', [\App\Http\Controllers\open\OpenApiV1Controller::class, 'orderQuery']);
});
