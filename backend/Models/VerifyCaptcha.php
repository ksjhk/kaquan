<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerifyCaptcha extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = [
        'type',
        'mobile',
        'code',
        'created_time',
        'update_time',
        'delete_time'
    ];
    const PLACE_AN_ORDER = 0;   // 下单验证码
    const AGENT_REGISTER = 1;   // 代理注册验证码
    const AGENT_BANK = 2;   // 代理银行卡验证码
    const AGENT_LOGIN = 3;  // 管理员登录验证码
    const AGENT_FORGET = 4; // 忘记密码验证码
    const EDIT_MOBILE = 5;  // 修改手机号
    public static $type = [
        self::PLACE_AN_ORDER => '下单验证码',
        self::AGENT_REGISTER => '代理注册验证码',
        self::AGENT_BANK => '代理银行卡验证码',
        self::AGENT_LOGIN => '管理员登录验证码',
        self::AGENT_FORGET => '忘记密码验证码',
        self::EDIT_MOBILE => '修改手机号'
    ];
}
