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
            $ipCnt = (int)Cache::get($ipKey, 0);
            if ($ipCnt >= 20) {
                return ['code' => ERROR_UN_KNOW, 'msg' => '发送过于频繁，请稍后再试'];
            }
            Cache::put($ipKey, $ipCnt + 1, 3600);
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
        // 调试模式：未配置短信服务（SMS_SECRET_ID/SMS_SECRET_KEY 为空）时，
        // 验证码不作废、随接口直接返回，部署方没接短信也能跑通注册登录全流程。
        // 正式上线必须配置短信，否则任何人都能看到验证码。
        if (!(string)env('SMS_SECRET_ID') || !(string)env('SMS_SECRET_KEY')) {
            \Illuminate\Support\Facades\Log::warning('sms debug mode: captcha returned via api', ['mobile' => $mobile, 'type' => $type]);
            return ['code' => SUCCESS, 'msg' => '调试模式：未配置短信服务，验证码已直接返回', 'debug_code' => $captcha];
        }
        $re = (new TencentSmsService())->sendCaptcha($mobile, $captcha);
        if (empty($re['ok'])) {
            // 发送失败：作废该验证码
            VerifyCaptcha::query()->where('id', $id)->update([
                'update_time' => time(),
                'delete_time' => time(),
            ]);
            return ['code' => ERROR_UN_KNOW, 'msg' => $re['msg'] ?? '发送失败'];
        }
        return ['code' => SUCCESS, 'msg' => '发送成功'];
    }

    /**
     * 校验验证码（5 分钟有效）
     */
    public function verifyCaptcha($mobile, $captcha, $type)
    {
        $data = VerifyCaptcha::query()
            ->where('mobile', $mobile)
            ->where('delete_time', 0)
            ->where('type', $type)
            ->orderBy('created_time', 'desc')
            ->first();
        if (!$data) return false;
        if ($data['created_time'] * 1 + 60 * 5 < time()) return false;
        return $captcha == $data['code'];
    }
}
