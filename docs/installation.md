# 部署接入指南

卡世界开放平台是一套可嵌入现有 Laravel 8 项目的业务模块 + 一套原生静态前端控制台。本指南从零部署。

## 1. 环境要求

- PHP >= 7.4（8.0/8.1 均可），扩展：curl、openssl、mbstring、bcmath（建议）
- Laravel 8.x
- MySQL 5.7+ / 8.0
- Composer 依赖：
  ```bash
  composer require overtrue/wechat:~5.0          # 微信支付 V2（Native 充值）
  composer require tencentcloud/tencentcloud-sdk-php  # 腾讯云短信
  ```
- Guzzle（Laravel 自带，支付宝网关请求用）

## 2. 后端安装

1. **导入数据库结构**
   ```bash
   mysql -u 用户 -p 数据库名 < sql/schema.sql
   ```
   表前缀默认 `ml_`，与 `.env` 的 `DB_PREFIX` 对应；如需修改前缀请全局替换 schema 里的表名。
   **注意**：`composer create-project` 生成的 `config/database.php` 中 mysql 连接的 `'prefix'` 是硬编码 `''`，
   必须改为 `'prefix' => env('DB_PREFIX', ''),` 前缀机制才会生效。

2. **拷贝代码到 Laravel 项目**

   | 仓库路径 | 目标路径 |
   |---|---|
   | `backend/Controllers/open/` | `app/Http/Controllers/open/` |
   | `backend/Services/` | `app/Http/Services/`（WxpayService/AlipayService 放 `app/Http/Services/api/`） |
   | `backend/Models/VerifyCaptcha.php` | `app/Models/VerifyCaptcha.php` |
   | `backend/Support/helpers.php`、`message.php` | 任意位置，建议 `app/Support/` |
   | `backend/Config/alipay.php` | `config/alipay.php` |

3. **注册辅助函数**：`composer.json` 的 `autoload.files` 加入 helpers，然后 `composer dump-autoload`：
   ```json
   "autoload": {
       "files": ["app/Support/helpers.php"]
   }
   ```

4. **注册路由**：把 `backend/routes.php` 的内容追加到 `routes/api.php` 末尾
   （**去掉片段开头的 `<?php` 行**，否则报语法错误）。
   `backend/Commands/OpenRechargeScanCommand.php` 拷到 `app/Console/Commands/`（骨架若无该目录则新建）。

5. **配置 `.env`**：对照 `.env.example` 填写数据库、卡世界供货（`SUPPLIER_*`，在 kashijie.com 注册创建密钥获得）、微信/支付宝、腾讯云短信配置。 支付商户号逐项配置教程（含资金流说明）：见 [payment.md](payment.md)。

6. **充值兜底定时任务**（支付回调之外的主动查单兜底）：
   ```cron
   * * * * * cd /项目路径 && sudo -u www php artisan open:recharge-scan >> /dev/null 2>&1
   ```
   （`OpenRechargeScanCommand` 位于 `app/Console/Commands/`，Laravel 会自动发现；该文件在 `backend/Commands/` 中，拷贝过去即可。）

## 3. 前端安装

`frontend/` 是纯静态站点（无构建），放到任意 Web 服务器目录即可，包含：

- `index.html` 首页 / `market.html` 货源市场 / `docs.html` API 文档
- `login.html` 登录 / `register.html` 注册 / `console.html` 代理商控制台
- `open-common.css/js`、`open-qrcode.min.js`、`assets/logo-icon.png`

**必改一处**：打开 `open-common.js` 顶部，把 `API_BASE` 改成你的后端域名：

```js
var API_BASE = 'https://your-domain.com'; // 本后端部署地址
```

前端页面调用的货源接口是本平台自己的公开接口（`/open-api/v1/goods`），与后端同源部署时无需额外配置；跨域部署时需对前端站点放行 CORS。

## 4. 初始化账号

1. 打开 `register.html` 注册第一个账号（真实短信验证码，需先配好 `SMS_*`）。
2. 把该账号设为管理员：
   ```sql
   UPDATE ml_open_user SET role = 1 WHERE mobile = '你的手机号';
   ```
3. 登录 `console.html`，创建 API 密钥（app_key / app_secret），即可按 `docs/api.md` 签名调用。

## 5. 短信验证码配置（部署方自己的短信）

注册/登录短信走的是**你自己申请的腾讯云短信**，与作者、与卡世界平台没有任何关系——短信是按条付费的，凭证内置别人的既会烧钱也有盗刷风险，任何开源项目都不会内置作者的短信凭证。

**没配置短信也能先跑通流程**：`SMS_SECRET_ID` / `SMS_SECRET_KEY` 为空时系统自动进入调试模式，点「获取验证码」后验证码会直接显示在页面上（接口返回 `debug_code`，前端自动填入）。正式上线前必须按下表配置，否则任何人都能看到任意手机号的验证码。

| .env 项 | 从哪里拿 |
|---|---|
| `SMS_SECRET_ID` / `SMS_SECRET_KEY` | 腾讯云控制台「访问管理 → API 密钥管理」 |
| `SMS_SDK_APP_ID` | 腾讯云短信控制台「应用管理」的 SdkAppId |
| `SMS_SIGN_NAME` | 「签名管理」里已审核通过的签名 |
| `SMS_TEMPLATE_ID` | 「正文模板管理」里已审核的验证码模板 ID（模板含 1 个变量：验证码） |

签名和模板需要审核（通常几小时到 1 个工作日）。想换阿里云/其他短信通道：只需改写 `backend/Services/TencentSmsService.php` 一个文件。

## 6. 子目录 / 反向代理部署（可选）

若后端挂在某个站点的子路径下（如 `https://主站域名/openpkg-api/` 反代到后端），
URL 生成会丢失路径前缀，导致支付回调地址错误。修复：在 `app/Providers/AppServiceProvider.php`
的 `boot()` 中强制 URL 根：

```php
use Illuminate\Support\Facades\URL;

public function boot()
{
    if (config('app.url')) { URL::forceRootUrl(config('app.url')); }
}
```

并把 `.env` 的 `APP_URL` 设为完整前缀地址（如 `https://主站域名/openpkg-api`）。

## 7. 自检清单

- [ ] `GET /api/open/v1/goods` 匿名可访问（公开接口）
- [ ] 注册收得到短信验证码
- [ ] 创建密钥后带签名访问 `GET /api/open/v1/balance` 返回余额
- [ ] 管理员充值后余额到账，下单扣款、失败自动退款
- [ ] crontab 兜底查单每分钟运行
