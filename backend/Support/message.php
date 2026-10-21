<?php
/**
 * 响应 code 映射（开放平台使用到的子集，与商城同口径）
 * helpers.php 加载时按 'code' 名自动 define 为常量。
 */
return [
    0 => ['code' => 'SUCCESS', 'msg' => '操作成功'],
    1 => ['code' => 'ERROR_UN_KNOW', 'msg' => '操作失败，请稍后重试'],
    2 => ['code' => 'ERROR_CODE', 'msg' => '验证码错误或已过期'],
    5 => ['code' => 'ERROR_PARAMS', 'msg' => '参数错误'],
    6 => ['code' => 'NULL_DATA', 'msg' => '查无此数据'],
    7 => ['code' => 'MISS_TOKEN', 'msg' => 'token为空'],
    10 => ['code' => 'ERROR_TOKEN', 'msg' => '登录失效，请重新登录'],
    11 => ['code' => 'PERMISSION_DENIED', 'msg' => '没有权限'],
    -9001 => ['code' => 'OPEN_BALANCE_NOT_ENOUGH', 'msg' => '余额不足，请充值'],
];
