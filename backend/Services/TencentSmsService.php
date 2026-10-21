<?php

namespace App\Http\Services;

use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;
use TencentCloud\Sms\V20210111\SmsClient;

/**
 * 腾讯云短信服务（开放平台专用精简版）
 * 凭证与模板全部走 .env（原实现读商城 system_config 表，开源版改为环境变量）：
 *   SMS_SECRET_ID / SMS_SECRET_KEY   腾讯云 API 密钥
 *   SMS_SDK_APP_ID                   短信应用 SdkAppId
 *   SMS_SIGN_NAME                    已审核签名
 *   SMS_TEMPLATE_ID                  验证码模板ID（模板含 1 个变量：验证码）
 * 依赖：composer require tencentcloud/tencentcloud-sdk-php
 */
class TencentSmsService
{
    /**
     * 发送验证码短信
     * @return array ['ok'=>bool, 'msg'=>string]
     */
    public function sendCaptcha($mobile, $captcha)
    {
        $secretId = (string)env('SMS_SECRET_ID', '');
        $secretKey = (string)env('SMS_SECRET_KEY', '');
        if (!$secretId || !$secretKey) {
            return ['ok' => false, 'msg' => '短信服务未配置'];
        }
        try {
            $cred = new Credential($secretId, $secretKey);
            $httpProfile = new HttpProfile();
            $httpProfile->setReqMethod("GET");
            $httpProfile->setReqTimeout(30);
            $httpProfile->setEndpoint("sms.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setSignMethod("TC3-HMAC-SHA256");
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, "ap-guangzhou", $clientProfile);

            $req = new SendSmsRequest();
            $req->SmsSdkAppId = (string)env('SMS_SDK_APP_ID', '');
            $req->SignName = (string)env('SMS_SIGN_NAME', '');
            $req->TemplateId = (string)env('SMS_TEMPLATE_ID', '');
            $req->TemplateParamSet = [(string)$captcha];
            $req->PhoneNumberSet = ['+86' . $mobile];
            $req->SessionContext = "";
            $req->ExtendCode = "";
            $req->SenderId = "";

            $resp = $client->SendSms($req);
            $res = json_decode($resp->toJsonString(), true);
            $status = $res['SendStatusSet'][0] ?? [];
            if (($status['Code'] ?? '') === 'Ok') {
                return ['ok' => true, 'msg' => 'ok'];
            }
            return ['ok' => false, 'msg' => ($status['Message'] ?? 'send error')];
        } catch (TencentCloudSDKException $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}
