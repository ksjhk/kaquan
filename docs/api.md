# API 文档

开放平台接口分两组：

| 分组 | 前缀 | 鉴权 | 说明 |
|---|---|---|---|
| 对外业务 API | `/api/open/v1/` | app_key 签名（货源查询公开） | 货源、下单、订单、余额 |
| 平台账号 API | `/api/open/platform/` | token（Header `token`） | 注册登录、控制台、充值、管理 |

统一响应：`{"code": 0, "message": "...", "data": {...}}`，`code=0` 成功，其余失败。
**金额单位：接口中所有金额字段（cost_price / sell_price / balance / amount）一律为「分」。**

---

## 一、签名算法（v1 交易类接口）

所有签名请求必带三个参数：

| 参数 | 说明 |
|---|---|
| `app_key` | 控制台创建的密钥 Key |
| `timestamp` | 秒级时间戳，与服务器偏差超过 10 分钟拒绝 |
| `sign` | 签名串 |

计算方式：

```
1. 取全部请求参数（GET query 或 POST body），排除 sign 本身
2. 跳过空值与数组
3. 按参数名升序拼接：k1=v1&k2=v2
4. 末尾追加 &secret=你的app_secret
5. sign = md5(拼接串)（小写 32 位 hex）
```

---

## 二、v1 货源接口（公开，无需签名）

> 匿名调用 `price_rate=1`，同 IP 限 60 次/分钟；带密钥签名调用则按密钥加价倍数返回 cost_price。

### GET /api/open/v1/goods 货源列表

参数：`category_id?`、`current=1`、`size=20`（匿名调用时签名参数可全不带）

响应 `data`：

```json
{
  "list": [{
    "goods_id": 4353, "goods_name": "饿了么外卖红包10元", "goods_img": "https://...",
    "face_value": "10元", "cost_price": 798, "sell_price": 999,
    "stock_num": 1234, "category_name": "外卖"
  }],
  "pagination": {"total": 106, "size": 20, "current": 1, "pages": 6},
  "amount_unit": "fen"
}
```

### GET /api/open/v1/goods/detail 商品详情

参数：`goods_id`。响应在列表字段基础上增加 `spec_value` / `tags` / `tabs`。

---

## 三、v1 交易接口（需签名）

### GET /api/open/v1/balance 余额查询

响应：`{"balance": 597}`（分）

### POST /api/open/v1/order/create 创建订单

参数：`goods_id`、`recharge_account`（充值账号）、`attach?`

逻辑：向供货方实时校验商品/库存 → 余额校验（不足返回 `-9001 余额不足，请充值`）→ 事务原子扣款（防并发超扣）+ 建单 + 流水 → 供货方直充发货，失败自动退款。

响应：`{"order_no": "OP...", "status": "recharging", "cost_price": 798, "balance": 202}`

> cost_price 为底价×密钥加价倍数（对外报价）；实际扣款与订单落库按真实底价。

### POST /api/open/v1/order/query 订单查询

参数：`order_no`（仅可查本人订单）。充值中的订单会实时同步供货方状态；供货方失败自动退款（幂等）。

订单状态：`pending` 待提交 / `recharging` 充值中 / `success` 已到账 / `failed` 充值失败 / `refunded` 已退款。

---

## 四、platform 账号接口

### 注册 / 登录

| 接口 | 说明 |
|---|---|
| `POST /sms` | 注册验证码 `{mobile}`（真实短信，60s 限频） |
| `POST /register` | `{mobile, code, password, name?, invite_code?}` → `{token, user}` |
| `POST /login` | 账号密码登录 → `{token, user}` |
| `POST /sms-login-code` | 登录验证码（未注册手机号也发） |
| `POST /login-sms` | 验证码登录，未注册自动注册 → `{token, is_new, user}` |

token 有效期 7 天，后续请求 Header 带 `token: xxx`。

### 控制台（需 token）

| 接口 | 说明 |
|---|---|
| `GET /profile` / `POST /profile` | 查看 / 修改资料（name, avatar） |
| `GET /dashboard` | 余额、今日/累计调用量、密钥数、邀请数 |
| `GET /apikey` / `POST /apikey` | 密钥列表（含 app_secret 明文、price_rate）/ 创建（最多 5 个启用中，price_rate 1.00~99.99） |
| `POST /apikey/status` | 启停密钥 `{id, status}` |
| `GET /logs` | API 调用记录分页 |
| `GET /invite` | 邀请链接与邀请数 |
| `GET /notices` | 平台公告（最近 20 条） |

### 余额充值（需 token）

| 接口 | 说明 |
|---|---|
| `POST /recharge` | `{amount(元,≥1), pay_type(wechat/alipay)}` → `{order_no, pay_qrcode, pay_url?}`（微信 Native code_url / 支付宝当面付 qr_code） |
| `GET /recharge/status` | 轮询查单；后端每次被轮询时主动向微信/支付宝查单，已支付则幂等结算加余额 |
| `GET /recharge/list` | 我的充值记录分页 |

### 管理接口（需 token 且 role=1）

| 接口 | 说明 |
|---|---|
| `GET /admin/users` | 用户分页（keyword 搜索） |
| `POST /admin/balance` | 手动调余额 `{user_id, amount(正负), remark}`，事务防负余额 |
| `GET /admin/recharges` | 全部充值记录分页（status 过滤） |
| `POST /admin/notices` | 发布公告 `{title, content}` |

---

## 五、错误码

| code | 说明 |
|---|---|
| 0 | 成功 |
| 1 | 操作失败（通用） |
| 2 | 验证码错误或已过期 |
| 5 | 参数错误（含签名错误、限频） |
| 6 | 查无此数据 / 无权限 |
| 7 | token 为空 |
| 10 | 登录失效 |
| 11 | app_key 无效或已禁用 |
| -9001 | 余额不足，请充值 |
