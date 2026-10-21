-- =============================================================
-- 卡世界开放平台（kashijie-open-platform）数据库结构
-- 表前缀：ml_（与 Laravel .env 的 DB_PREFIX 对应，可全局替换）
-- 说明：open_ 前缀表为开放平台自有表；ml_verify_captchas 为短信
--       验证码表（短信服务依赖，若你已有验证码体系可自行替换）。
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE `ml_open_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT 'bcrypt密码',
  `name` varchar(100) NOT NULL DEFAULT '',
  `avatar` varchar(500) NOT NULL DEFAULT '',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `invite_code` varchar(16) NOT NULL DEFAULT '' COMMENT '本人邀请码',
  `from_invite_code` varchar(16) NOT NULL DEFAULT '' COMMENT '注册来源邀请码',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1正常0禁用',
  `role` tinyint NOT NULL DEFAULT '0' COMMENT '0普通用户 1管理员',
  `created_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mobile` (`mobile`),
  UNIQUE KEY `uk_invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='开放平台用户';
CREATE TABLE `ml_open_api_key` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `app_key` varchar(40) NOT NULL DEFAULT '',
  `app_secret` varchar(64) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1启用0禁用',
  `remark` varchar(200) NOT NULL DEFAULT '',
  `price_rate` decimal(8,2) NOT NULL DEFAULT '1.00' COMMENT '加价倍数',
  `last_used_time` int unsigned NOT NULL DEFAULT '0',
  `created_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_key` (`app_key`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='开放平台API密钥';
CREATE TABLE `ml_open_order` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `order_no` varchar(32) NOT NULL DEFAULT '',
  `goods_id` int NOT NULL DEFAULT '0',
  `goods_name` varchar(255) NOT NULL DEFAULT '',
  `cost_price` int NOT NULL DEFAULT '0' COMMENT '商品成本价 单位分',
  `recharge_account` varchar(255) NOT NULL DEFAULT '',
  `attach` text COMMENT '下单附加参数 JSON',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending待提交/recharging充值中/success已到账/failed充值失败/refunded已退款',
  `supplier_order_no` varchar(64) NOT NULL DEFAULT '' COMMENT '供货方订单号',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_time` (`user_id`,`created_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='开放平台订单';
CREATE TABLE `ml_open_balance_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `change_type` varchar(16) NOT NULL DEFAULT '' COMMENT 'consume扣款/recharge充值/refund退款',
  `amount` int NOT NULL DEFAULT '0' COMMENT '单位分 带正负',
  `balance_after` int NOT NULL DEFAULT '0' COMMENT '操作后余额 单位分',
  `order_no` varchar(32) NOT NULL DEFAULT '',
  `remark` varchar(128) NOT NULL DEFAULT '',
  `created_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='开放平台余额流水';
CREATE TABLE `ml_open_recharge` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `order_no` varchar(32) NOT NULL DEFAULT '' COMMENT '充值单号 RC开头',
  `amount` int NOT NULL DEFAULT '0' COMMENT '充值金额 单位分',
  `pay_type` varchar(16) NOT NULL DEFAULT '' COMMENT 'wechat/alipay',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0待支付1已支付2已关闭',
  `transaction_id` varchar(64) NOT NULL DEFAULT '' COMMENT '支付平台流水号',
  `paid_time` int unsigned NOT NULL DEFAULT '0',
  `created_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_status_time` (`status`,`created_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='开放平台余额充值单';
CREATE TABLE `ml_open_notice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL DEFAULT '',
  `content` text,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1正常0下线',
  `created_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='开放平台公告';

CREATE TABLE `ml_open_api_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `app_key` varchar(40) NOT NULL DEFAULT '',
  `api` varchar(255) NOT NULL DEFAULT '' COMMENT '接口路径',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT '1',
  `created_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='开放平台API调用日志';
CREATE TABLE `ml_verify_captchas` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `type` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '类别 0下单验证码',
  `mobile` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '手机号',
  `code` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '验证码',
  `created_time` int unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  `delete_time` int unsigned NOT NULL DEFAULT '0' COMMENT '删除时间(作废时间)',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_mobile` (`mobile`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC COMMENT='验证码';
