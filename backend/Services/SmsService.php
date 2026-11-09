<?php

namespace App\Http\Services;

use App\Models\VerifyCaptcha;
use Illuminate\Support\Facades\Cache;

/**
 * 短信验证码服务（开放平台专用精简版）
 * - 验证码存 verify_captchas 表，5 分钟有效
 * - 自带限频：同手机号同类型 60 秒 1 条、同 IP 每小时 20 条
 * - 发送通道：腾讯云短信（TencentSmsService，模板走 .env）
 */
class SmsService
{
    /**
     * 生成并发送验证码
     * @param string $mobile 手机号
     * @param int    $type   验证码类型（业务方自定义，如 6=开放平台注册 7=验证码登录）
     * @return array ['code'=>0成功, 'msg'=>...]
     */
    public function sendLocalCaptcha($mobile, $type)
    {
        $ip = request()->ip();

        // 同手机号同类型 60 秒内只发 1 条
        $recent = VerifyCaptcha::query()
            ->where('mobile', $mobile)
            ->where('type', $type)
            ->where('delete_time', 0)
            ->where('created_time', '>', time() - 60)
            ->exists();
        if ($recent) {
            return ['code' => ERROR_UN_KNOW, 'msg' => '请求频繁，请60秒后再试'];
        }
        // 同 IP 每小时最多 20 条验证码
        try {
            $ipKey = 'sms_ip_' . md5((string)$ip);
            Cache::add($ipKey, 0, 3600);
            $ipCnt = Cache::increment($ipKey);
            if ($ipCnt === false || $ipCnt > 20) {
                return ['code' => ERROR_UN_KNOW, 'msg' => '发送过于频繁，请稍后再试'];
            }
        } catch (\Exception $e) {}

        $captcha = generate_code();
        $id = VerifyCaptcha::query()->insertGetId([
            'mobile' => $mobile,
            'code' => $captcha,
            'type' => $type,
            'created_time' => time(),
            'update_time' => time(),
        ]);
        if (!$id) {
            return ['code' => ERROR_UN_KNOW, 'msg' => '发送失败'];
        }
        // 短信通道：自己的腾讯云短信（SMS_* 已配置）→ 卡世界托管短信转发（开箱即用）。
        // 通道失败必须失败关闭，验证码绝不通过接口返回。
        if (!(string)env('SMS_SECRET_ID') || !(string)env('SMS_SECRET_KEY')) {
            $relay = $this->sendViaRelay($mobile, $captcha);
            if ($relay['ok']) {
                return ['code' => SUCCESS, 'msg' => '发送成功'];
            }
            VerifyCaptcha::query()->where('id', $id)->update(['update_time' => time(), 'delete_time' => time()]);
            \Illuminate\Support\Facades\Log::warning('sms relay failed', ['mobile' => $mobile, 'type' => $type]);
            return ['code' => ERROR_UN_KNOW, 'msg' => '短信发送失败，请稍后重试'];
        }
        $re = (new TencentSmsService())->sendCaptcha($mobile, $captcha);
        if (empty($re['ok'])) {
            // 发送失败：作废该验证码
            VerifyCaptcha::query()->where('id', $id)->update([
                'update_time' => time(),
                'delete_time' => time(),
            ]);
            \Illuminate\Support\Facades\Log::warning('sms provider failed', ['mobile' => $mobile, 'type' => $type]);
            return ['code' => ERROR_UN_KNOW, 'msg' => '短信发送失败，请稍后重试'];
        }
        return ['code' => SUCCESS, 'msg' => '发送成功'];
    }

    /**
     * 卡世界托管短信转发：部署方未配置自己的短信时的默认通道。
     * 转发端服务端限制：同手机号 60s 1 条 & 10 条/天，同来源 IP 50 条/天，全局 2000 条/天。
     * 本地另有：同手机号同类型 60s 1 条、同 IP 每小时 20 条（见上方 sendLocalCaptcha）。
     * @return array ['ok'=>bool, 'msg'=>string]
     */
    private function sendViaRelay($mobile, $captcha)
    {
        $url = (string)env('SMS_RELAY_URL', 'https://www.kashijie.com/open-api/platform/sms-relay');
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['mobile' => $mobile, 'captcha' => (string)$captcha]),
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            if ($raw === false) return ['ok' => false, 'msg' => 'relay unreachable'];
            $res = json_decode($raw, true);
            if (is_array($res) && ($res['code'] ?? -1) === 0) return ['ok' => true, 'msg' => 'ok'];
            return ['ok' => false, 'msg' => is_array($res) ? (string)($res['message'] ?? 'relay error') : 'relay bad response'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 校验验证码（5 分钟有效）
     */
    public function verifyCaptcha($mobile, $captcha, $type)
    {
        $attemptKey = 'sms_verify_attempts_' . md5((string)$mobile . '|' . (string)$type);
        Cache::add($attemptKey, 0, 600);
        $attempts = Cache::increment($attemptKey);
        if ($attempts === false || $attempts > 10) return false;

        $data = VerifyCaptcha::query()
            ->where('mobile', $mobile)
            ->where('delete_time', 0)
            ->where('type', $type)
            ->orderBy('created_time', 'desc')
            ->first();
        if (!$data) return false;
        if ($data['created_time'] * 1 + 60 * 5 < time()) return false;
        if (!hash_equals((string)$data['code'], (string)$captcha)) return false;

        // 条件更新保证并发请求也只有一次校验成功，成功后立即作废。
        return VerifyCaptcha::query()->where('id', $data['id'])->where('delete_time', 0)->update([
            'update_time' => time(),
            'delete_time' => time(),
        ]) === 1;
    }
}
