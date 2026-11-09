<?php

namespace App\Http\Controllers\open;

use App\Http\Controllers\Controller;
use App\Http\Services\SupplierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 酒店、电影票只读查询。
 * 本开源包不直接提供酒店/电影扣款下单，避免部署方余额与上游余额的扣费顺序不一致。
 */
class OpenSupplyV1Controller extends Controller
{
    const SIGN_TS_TOLERANCE = 600;

    public function hotelSearch(Request $r) { return $this->hotelQuery($r, 'search'); }
    public function hotelDetail(Request $r) { return $this->hotelQuery($r, 'detail'); }
    public function hotelRooms(Request $r) { return $this->hotelQuery($r, 'rooms'); }
    public function hotelValidate(Request $r) { return $this->hotelQuery($r, 'validate'); }

    public function movieCity(Request $r) { return $this->movieQuery($r, 'city'); }
    public function movieFilms(Request $r) { return $this->movieQuery($r, 'films'); }
    public function movieWaitList(Request $r) { return $this->movieQuery($r, 'wait-list'); }
    public function movieRegion(Request $r) { return $this->movieQuery($r, 'region'); }
    public function movieBrand(Request $r) { return $this->movieQuery($r, 'brand'); }
    public function movieCinemas(Request $r) { return $this->movieQuery($r, 'cinemas'); }
    public function movieSchedule(Request $r) { return $this->movieQuery($r, 'schedule'); }
    public function movieShowdate(Request $r) { return $this->movieQuery($r, 'showdate'); }
    public function movieFilmInfo(Request $r) { return $this->movieQuery($r, 'film-info'); }
    public function movieCinemaInfo(Request $r) { return $this->movieQuery($r, 'cinema-info'); }
    public function movieShow(Request $r) { return $this->movieQuery($r, 'show'); }
    public function movieSeats(Request $r) { return $this->movieQuery($r, 'seats'); }

    private function hotelQuery(Request $request, $endpoint)
    {
        $fail = $this->authPublicQuery($request);
        if ($fail) return $fail;
        $result = (new SupplierService())->hotelQuery($endpoint, $this->businessParams($request));
        if (!is_array($result)) {
            return resp(ERROR_UN_KNOW, null, '酒店货源暂时不可用，请稍后重试');
        }
        return response()->json($this->sanitizeUpstreamMessages($result));
    }

    private function movieQuery(Request $request, $endpoint)
    {
        $fail = $this->authPublicQuery($request);
        if ($fail) return $fail;
        $result = (new SupplierService())->movieQuery($endpoint, $this->businessParams($request));
        if (!is_array($result)) {
            return resp(ERROR_UN_KNOW, null, '电影票货源暂时不可用，请稍后重试');
        }
        return response()->json($this->sanitizeUpstreamMessages($result));
    }

    /** 保留正常业务错误；上游把内部地址或凭证放进 msg/message 时只返回通用错误并记录日志。 */
    private function sanitizeUpstreamMessages(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeUpstreamMessages($value);
                continue;
            }
            $lower = strtolower((string)$key);
            if (in_array($lower, ['msg', 'message'], true) && is_string($value)
                && preg_match('#https?://|(?:\d{1,3}\.){3}\d{1,3}|token|secret#i', $value)) {
                \Illuminate\Support\Facades\Log::warning('open supply sensitive upstream message hidden');
                $data[$key] = '货源返回异常，请稍后重试';
            }
        }
        return $data;
    }

    private function businessParams(Request $request)
    {
        return $request->except(['app_key', 'timestamp', 'sign', 'app_secret', 'token', 'password', 'attach']);
    }

    /** 匿名同 IP 每分钟 60 次；主动携带 app_key 时必须完整验签。 */
    private function authPublicQuery(Request $request)
    {
        $appKey = trim((string)$request->input('app_key', ''));
        if ($appKey === '') {
            $cacheKey = 'openv1_supply_pub_limit_' . md5((string)$request->ip()) . '_' . (int)floor(time() / 60);
            Cache::add($cacheKey, 0, 70);
            $count = Cache::increment($cacheKey);
            if ($count === false || $count > 60) {
                return resp(ERROR_PARAMS, null, '请求过于频繁');
            }
            return null;
        }
        list($user, $fail) = $this->authApi($request);
        return $fail;
    }

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

    private function apiLog($userId, $appKey, Request $request, $status)
    {
        try {
            DB::table('open_api_log')->insert([
                'user_id' => (int)$userId,
                'app_key' => (string)$appKey,
                'api' => '/' . ltrim($request->path(), '/'),
                'method' => $request->method(),
                'ip' => (string)$request->ip(),
                'params' => json_encode($request->except(['sign', 'app_secret', 'token', 'password']), JSON_UNESCAPED_UNICODE),
                'status' => (int)$status,
                'created_time' => time(),
            ]);
        } catch (\Throwable $e) {}
    }
}
