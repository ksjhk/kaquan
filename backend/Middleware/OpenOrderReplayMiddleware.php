<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

/** 阻止同一签名请求在网络重试时重复下单、重复扣款。 */
class OpenOrderReplayMiddleware
{
    public function handle($request, Closure $next)
    {
        $appKey = trim((string)$request->input('app_key', ''));
        $sign = strtolower(trim((string)$request->input('sign', '')));
        if ($appKey === '' || !preg_match('/^[a-f0-9]{32}$/', $sign)) {
            return $next($request);
        }

        // 用路由动作而不是 URL 前缀：线上 api/open/v1 与开源包 open-api/v1 必须得到同一接口键。
        $route = $request->route();
        $endpoint = is_object($route) && method_exists($route, 'getActionName')
            ? $route->getActionName() : $request->path();
        $key = 'open_order_replay_' . hash('sha256', $appKey . '|' . $endpoint . '|' . $sign);
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['content'])) {
            return response($cached['content'], 200)->header('Content-Type', 'application/json');
        }

        $lockKey = $key . '_lock';
        if (!Cache::add($lockKey, 1, 300)) {
            return response()->json(['code' => 5, 'message' => '相同订单请求正在处理，请稍后使用原请求重试或查询订单', 'data' => null]);
        }

        try {
            $response = $next($request);
            $content = $response->getContent();
            $data = is_string($content) ? json_decode($content, true) : null;
            if (is_array($data) && !empty($data['data']['order_no'])) {
                Cache::put($key, ['content' => $content], 900);
            }
            return $response;
        } finally {
            Cache::forget($lockKey);
        }
    }
}
