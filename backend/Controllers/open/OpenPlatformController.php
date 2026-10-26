<?php
namespace App\Http\Controllers\open;

use App\Http\Controllers\Controller;
use App\Http\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 卡世界开放平台 · 用户端接口
 * 路由前缀 /api/open/platform，独立 token 体系（与商城买家、后台管理员完全隔离）。
 * token：随机 64 位字符串，存 Cache，有效期 7 天，Header 带 `token: xxx`。
 * 纯新增控制器，不影响现有任何业务。
 */
class OpenPlatformController extends Controller
{
    /** token 有效期：7 天（秒） */
    const TOKEN_TTL = 604800;

    /** 短信验证码类型：开放平台注册（VerifyCaptcha 已用 0-5，这里用 6） */
    const SMS_TYPE_OPEN_REGISTER = 6;

    /** 短信验证码类型：开放平台验证码登录（未注册自动注册） */
    const SMS_TYPE_OPEN_LOGIN = 7;

    /** 每人最多启用中的密钥数 */
    const MAX_ACTIVE_KEYS = 5;

    /**
     * POST /api/open/platform/sms 发送注册验证码（腾讯云真实短信，SmsService 自带限频）
     * 入：{mobile}
     */
    public function sms(Request $request)
    {
        $mobile = trim((string)$request->input('mobile', ''));
        if (!preg_match('/^1\d{10}$/', $mobile)) {
            return resp(ERROR_PARAMS, null, '手机号格式不正确');
        }
        $re = (new SmsService())->sendLocalCaptcha($mobile, self::SMS_TYPE_OPEN_REGISTER);
        if (!$re || ($re['code'] ?? ERROR_UN_KNOW) !== SUCCESS) {
            return resp(ERROR_PARAMS, null, ($re['msg'] ?? '') ?: '发送失败');
        }
        return resp(SUCCESS, !empty($re['debug_code']) ? ['debug_code' => $re['debug_code']] : null);
    }

    /**
     * POST /api/open/platform/register 注册
     * 入：{mobile, code, password, name?, invite_code?}
     */
    public function register(Request $request)
    {
        $mobile = trim((string)$request->input('mobile', ''));
        $code = trim((string)$request->input('code', ''));
        $password = (string)$request->input('password', '');
        $name = trim((string)$request->input('name', ''));
        $inviteCode = trim((string)$request->input('invite_code', ''));

        if (!preg_match('/^1\d{10}$/', $mobile)) {
            return resp(ERROR_PARAMS, null, '手机号格式不正确');
        }
        if (strlen($password) < 6) {
            return resp(ERROR_PARAMS, null, '密码长度不能少于6位');
        }
        // 校验短信验证码（ml_verify_captchas，5分钟有效）
        if (!(new SmsService())->verifyCaptcha($mobile, $code, self::SMS_TYPE_OPEN_REGISTER)) {
            return resp(ERROR_CODE, null, '验证码错误或已过期');
        }
        // 手机号唯一
        if (DB::table('open_user')->where('mobile', $mobile)->where('delete_time', 0)->exists()) {
            return resp(ERROR_PARAMS, null, '该手机号已注册');
        }
        // 邀请码存在则记录来源
        $fromInviteCode = '';
        if ($inviteCode !== '') {
            $inviter = DB::table('open_user')->where('invite_code', $inviteCode)->where('delete_time', 0)->first();
            if (!$inviter) {
                return resp(ERROR_PARAMS, null, '邀请码不存在');
            }
            $fromInviteCode = $inviteCode;
        }

        $now = time();
        $uid = DB::table('open_user')->insertGetId([
            'mobile' => $mobile,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'name' => $name !== '' ? $name : ('用户' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6))),
            'avatar' => '',
            'balance' => 0.00,
            'invite_code' => $this->genInviteCode(),
            'from_invite_code' => $fromInviteCode,
            'status' => 1,
            'created_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);

        $user = DB::table('open_user')->where('id', $uid)->first();
        $token = $this->issueToken($uid);

        return resp(SUCCESS, [
            'token' => $token,
            'user' => [
                'id' => (int)$user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'mobile' => $user->mobile,
            ],
        ]);
    }

    /**
     * POST /api/open/platform/login 登录
     * 入：{mobile, password}
     */
    public function login(Request $request)
    {
        $mobile = trim((string)$request->input('mobile', ''));
        $password = (string)$request->input('password', '');
        if ($mobile === '' || $password === '') {
            return resp(ERROR_PARAMS, null, '手机号和密码不能为空');
        }
        $user = DB::table('open_user')->where('mobile', $mobile)->where('delete_time', 0)->first();
        if (!$user || !password_verify($password, $user->password)) {
            return resp(ERROR_PARAMS, null, '手机号或密码错误');
        }
        if ((int)$user->status !== 1) {
            return resp(ERROR_PARAMS, null, '账号已被禁用');
        }
        $token = $this->issueToken((int)$user->id);

        return resp(SUCCESS, [
            'token' => $token,
            'user' => [
                'id' => (int)$user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'mobile' => $user->mobile,
                'balance' => (float)$user->balance,
            ],
        ]);
    }

    /**
     * POST /api/open/platform/sms-login-code 发送登录验证码（未注册手机号也发，登录时自动注册）
     * 入：{mobile}
     */
    public function smsLoginCode(Request $request)
    {
        $mobile = trim((string)$request->input('mobile', ''));
        if (!preg_match('/^1\d{10}$/', $mobile)) {
            return resp(ERROR_PARAMS, null, '手机号格式不正确');
        }
        $re = (new SmsService())->sendLocalCaptcha($mobile, self::SMS_TYPE_OPEN_LOGIN);
        if (!$re || ($re['code'] ?? ERROR_UN_KNOW) !== SUCCESS) {
            return resp(ERROR_PARAMS, null, ($re['msg'] ?? '') ?: '发送失败');
        }
        return resp(SUCCESS, !empty($re['debug_code']) ? ['debug_code' => $re['debug_code']] : null);
    }

    /**
     * POST /api/open/platform/login-sms 验证码登录（未注册自动注册）
     * 入：{mobile, code}
     * 出：{token, is_new, user:{...}}（结构与账号密码登录一致，is_new 标识是否新注册）
     */
    public function loginSms(Request $request)
    {
        $mobile = trim((string)$request->input('mobile', ''));
        $code = trim((string)$request->input('code', ''));
        if (!preg_match('/^1\d{10}$/', $mobile)) {
            return resp(ERROR_PARAMS, null, '手机号格式不正确');
        }
        if (!(new SmsService())->verifyCaptcha($mobile, $code, self::SMS_TYPE_OPEN_LOGIN)) {
            return resp(ERROR_CODE, null, '验证码错误或已过期');
        }

        $user = DB::table('open_user')->where('mobile', $mobile)->where('delete_time', 0)->first();
        $isNew = 0;
        if ($user) {
            if ((int)$user->status !== 1) {
                return resp(ERROR_PARAMS, null, '账号已被禁用');
            }
        } else {
            // 未注册自动注册：随机密码（用户后续可改）、随机昵称、邀请码
            $now = time();
            $uid = DB::table('open_user')->insertGetId([
                'mobile' => $mobile,
                'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'name' => '用户' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)),
                'avatar' => '',
                'balance' => 0.00,
                'invite_code' => $this->genInviteCode(),
                'from_invite_code' => '',
                'status' => 1,
                'role' => 0,
                'created_time' => $now,
                'update_time' => $now,
                'delete_time' => 0,
            ]);
            $user = DB::table('open_user')->where('id', $uid)->first();
            $isNew = 1;
        }

        $token = $this->issueToken((int)$user->id);
        return resp(SUCCESS, [
            'token' => $token,
            'is_new' => $isNew,
            'user' => [
                'id' => (int)$user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'mobile' => $user->mobile,
                'balance' => (float)$user->balance,
            ],
        ]);
    }

    /**
     * GET /api/open/platform/profile 用户资料（需 token）
     */
    public function profile(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        return resp(SUCCESS, [
            'id' => (int)$user->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'mobile' => $user->mobile,
            'balance' => (float)$user->balance,
            'invite_code' => $user->invite_code,
            'role' => (int)$user->role, // 0普通 1管理员
            'created_time' => (int)$user->created_time,
        ]);
    }

    /**
     * POST /api/open/platform/profile 修改资料（需 token）
     * 入：{name?, avatar?}
     */
    public function updateProfile(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $update = ['update_time' => time()];
        if ($request->has('name')) {
            $name = trim((string)$request->input('name', ''));
            if ($name === '') return resp(ERROR_PARAMS, null, '昵称不能为空');
            $update['name'] = mb_substr($name, 0, 50);
        }
        if ($request->has('avatar')) {
            $update['avatar'] = trim((string)$request->input('avatar', ''));
        }
        DB::table('open_user')->where('id', $user->id)->update($update);

        return resp(SUCCESS, []);
    }

    /**
     * GET /api/open/platform/dashboard 仪表盘（需 token）
     */
    public function dashboard(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayCalls = DB::table('open_api_log')
            ->where('user_id', $user->id)->where('created_time', '>=', $todayStart)->count();
        $totalCalls = DB::table('open_api_log')->where('user_id', $user->id)->count();
        $keyCount = DB::table('open_api_key')->where('user_id', $user->id)->where('status', 1)->count();
        $inviteCount = DB::table('open_user')
            ->where('from_invite_code', $user->invite_code)->where('delete_time', 0)->count();

        return resp(SUCCESS, [
            'balance' => (float)$user->balance,
            'today_calls' => $todayCalls,
            'total_calls' => $totalCalls,
            'key_count' => $keyCount,
            'invite_count' => $inviteCount,
        ]);
    }

    /**
     * GET /api/open/platform/apikey 密钥列表（需 token）
     */
    public function apiKeyList(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $rows = DB::table('open_api_key')->where('user_id', $user->id)
            ->orderBy('id', 'desc')->get();
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => (int)$row->id,
                'app_key' => $row->app_key,
                'app_secret_masked' => 'sk_****' . substr($row->app_secret, -4),
                'app_secret' => $row->app_secret, // 明文返回（巍哥确认：密钥需可随时复制）
                'price_rate' => (float)$row->price_rate,
                'status' => (int)$row->status,
                'remark' => $row->remark,
                'last_used_time' => (int)$row->last_used_time,
                'created_time' => (int)$row->created_time,
            ];
        }
        return resp(SUCCESS, ['list' => $list]);
    }

    /**
     * POST /api/open/platform/apikey 创建密钥（需 token）
     * 入：{remark?}，每人最多 5 个启用中的密钥，secret 仅此一次完整返回
     */
    public function apiKeyCreate(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $active = DB::table('open_api_key')->where('user_id', $user->id)->where('status', 1)->count();
        if ($active >= self::MAX_ACTIVE_KEYS) {
            return resp(ERROR_PARAMS, null, '最多只能创建' . self::MAX_ACTIVE_KEYS . '个启用中的密钥');
        }

        // 加价倍数：1.00~99.99，最多两位小数，缺省 1.00
        $priceRate = trim((string)$request->input('price_rate', ''));
        if ($priceRate === '') $priceRate = '1';
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $priceRate) || (float)$priceRate < 1 || (float)$priceRate > 99.99) {
            return resp(ERROR_PARAMS, null, 'price_rate 须为 1.00~99.99 的数字，最多两位小数');
        }

        $now = time();
        $appKey = 'ak_' . $this->randomStr(21);   // ak_ 开头共 24 位
        $appSecret = 'sk_' . $this->randomStr(29); // sk_ 开头共 32 位
        DB::table('open_api_key')->insert([
            'user_id' => $user->id,
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'status' => 1,
            'remark' => mb_substr(trim((string)$request->input('remark', '')), 0, 100),
            'price_rate' => number_format((float)$priceRate, 2, '.', ''),
            'last_used_time' => 0,
            'created_time' => $now,
            'update_time' => $now,
        ]);

        return resp(SUCCESS, [
            'app_key' => $appKey,
            'app_secret' => $appSecret,
        ]);
    }

    /**
     * POST /api/open/platform/apikey/status 启停密钥（需 token）
     * 入：{id, status}（0禁用/1启用）
     */
    public function apiKeyStatus(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $id = (int)$request->input('id', 0);
        $status = (int)$request->input('status', -1);
        if (!$id || !in_array($status, [0, 1], true)) {
            return resp(ERROR_PARAMS, null, '参数错误');
        }
        $key = DB::table('open_api_key')->where('id', $id)->where('user_id', $user->id)->first();
        if (!$key) {
            return resp(NULL_DATA, null, '密钥不存在');
        }
        if ($status === 1 && (int)$key->status !== 1) {
            $active = DB::table('open_api_key')->where('user_id', $user->id)->where('status', 1)->count();
            if ($active >= self::MAX_ACTIVE_KEYS) {
                return resp(ERROR_PARAMS, null, '最多只能启用' . self::MAX_ACTIVE_KEYS . '个密钥');
            }
        }
        DB::table('open_api_key')->where('id', $id)->update([
            'status' => $status,
            'update_time' => time(),
        ]);
        return resp(SUCCESS, []);
    }

    /**
     * GET /api/open/platform/logs 使用记录（需 token）
     * 入：{current=1, size=20}
     */
    public function logs(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $current = max(1, (int)$request->input('current', 1));
        $size = min(100, max(1, (int)$request->input('size', 20)));

        $query = DB::table('open_api_log')->where('user_id', $user->id);
        $total = (clone $query)->count();
        $rows = $query->orderBy('id', 'desc')
            ->offset(($current - 1) * $size)->limit($size)
            ->get(['id', 'api', 'ip', 'status', 'created_time']);
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => (int)$row->id,
                'api' => $row->api,
                'ip' => $row->ip,
                'status' => (int)$row->status,
                'created_time' => (int)$row->created_time,
            ];
        }
        return resp(SUCCESS, [
            'list' => $list,
            'pagination' => [
                'total' => $total,
                'size' => $size,
                'current' => $current,
                'pages' => (int)ceil($total / $size),
            ],
        ]);
    }

    /**
     * GET /api/open/platform/invite 邀请链接（需 token）
     */
    public function invite(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $inviteCount = DB::table('open_user')
            ->where('from_invite_code', $user->invite_code)->where('delete_time', 0)->count();

        return resp(SUCCESS, [
            'invite_code' => $user->invite_code,
            'invite_url' => rtrim((string)env('OPEN_INVITE_URL_BASE', 'https://your-domain.com'), '/') . '/API/register.html?invite=' . $user->invite_code,
            'invite_count' => $inviteCount,
        ]);
    }

    /**
     * GET /api/open/platform/notices 公告列表（需 token）
     * 出：{list:[{id,title,content,created_time}]}，status=1，id 倒序 20 条
     */
    public function notices(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);

        $rows = DB::table('open_notice')->where('status', 1)
            ->orderBy('id', 'desc')->limit(20)
            ->get(['id', 'title', 'content', 'created_time']);
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id' => (int)$r->id,
                'title' => $r->title,
                'content' => $r->content,
                'created_time' => (int)$r->created_time,
            ];
        }
        return resp(SUCCESS, ['list' => $list]);
    }

    /**
     * POST /api/open/platform/admin/notices 发布公告（需 token + role=1 管理员）
     * 入：{title, content}
     */
    public function adminNoticeCreate(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return $this->authFail($request);
        if ((int)$user->role !== 1) {
            return resp(NULL_DATA, null, '无权限');
        }

        $title = trim((string)$request->input('title', ''));
        $content = trim((string)$request->input('content', ''));
        if ($title === '' || $content === '') {
            return resp(ERROR_PARAMS, null, '标题和内容不能为空');
        }
        if (mb_strlen($title) > 100) {
            return resp(ERROR_PARAMS, null, '标题不能超过100字');
        }
        $now = time();
        $id = DB::table('open_notice')->insertGetId([
            'title' => $title,
            'content' => $content,
            'status' => 1,
            'created_time' => $now,
            'update_time' => $now,
        ]);
        return resp(SUCCESS, ['id' => (int)$id]);
    }

    /**
     * 生成 8 位唯一邀请码
     */
    private function genInviteCode()
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 10; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            if (!DB::table('open_user')->where('invite_code', $code)->exists()) {
                return $code;
            }
        }
        return strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * 生成随机字母数字串
     */
    private function randomStr($len)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * 签发 token：随机 64 位字符串存 Cache，有效期 7 天
     */
    private function issueToken($uid)
    {
        $token = bin2hex(random_bytes(32));
        Cache::put('open_platform_token_' . $token, (int)$uid, self::TOKEN_TTL);
        return $token;
    }

    /**
     * token 认证：Header 带 token，查 Cache 取 user_id 再取用户
     */
    private function authUser(Request $request)
    {
        $token = trim((string)$request->header('token', ''));
        if ($token === '') return null;
        $uid = Cache::get('open_platform_token_' . $token);
        if (!$uid) return null;
        $user = DB::table('open_user')->where('id', (int)$uid)->where('delete_time', 0)->first();
        if (!$user || (int)$user->status !== 1) return null;
        return $user;
    }

    /**
     * 认证失败统一返回
     */
    private function authFail(Request $request)
    {
        $token = trim((string)$request->header('token', ''));
        if ($token === '') return resp(MISS_TOKEN, null, 'token为空');
        return resp(ERROR_TOKEN, null, '登录失效，请重新登录');
    }
}
