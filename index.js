import { defineTool } from '@deepseek-ai/dsh-tools'
import {
  KashijieClient, jsonOutput, memberSuccess, assertConfirmed, requiredString,
} from './lib/client.js'

export const name = 'kashijie-member-reseller'
export const inject = ['tools']

export function createTools(config = {}) {
  const client = new KashijieClient({
    baseUrl: config.baseUrl || process.env.KASHIJIE_MEMBER_API_BASE_URL || 'https://kashijie.com/api/open',
    appKey: config.appKey || process.env.KASHIJIE_APP_KEY || '',
    appSecret: config.appSecret || process.env.KASHIJIE_APP_SECRET || '',
    fetchImpl: config.fetchImpl || fetch,
  })
  const call = (options, exec) => client.request({ ...options, success: memberSuccess }, exec)

  return [
    {
      name: 'kashijie_member_goods',
      description: '查询卡世界会员权益货源列表和代理成本价，包括视频、音乐、网盘、外卖等虚拟会员商品。',
      parameters: {
        category_id: { type: 'integer', description: '可选商品分类 ID' },
        current: { type: 'integer', description: '页码，默认 1' },
        size: { type: 'integer', description: '每页数量，默认 20，最大 100' },
      },
      output: jsonOutput,
      timeoutMs: 30000,
      isConcurrencySafe: () => true,
      execute: (args, exec) => call({
        method: 'GET', path: '/v1/goods',
        query: {
          category_id: args.category_id,
          current: Math.max(1, args.current ?? 1),
          size: Math.min(100, Math.max(1, args.size ?? 20)),
        },
      }, exec),
    },
    {
      name: 'kashijie_member_goods_detail',
      description: '查询卡世界指定会员商品的规格、标签、供货价和销售参考信息。',
      parameters: { goods_id: { type: 'integer', required: true, description: '货源列表返回的 goods_id' } },
      output: jsonOutput,
      timeoutMs: 30000,
      isConcurrencySafe: () => true,
      execute: (args, exec) => call({ method: 'GET', path: '/v1/goods/detail', query: { goods_id: args.goods_id } }, exec),
    },
    {
      name: 'kashijie_member_balance',
      description: '使用代理自己的密钥查询卡世界开放平台预充值余额，金额单位为分。',
      parameters: {},
      output: jsonOutput,
      timeoutMs: 30000,
      execute: (_args, exec) => call({ method: 'GET', path: '/v1/balance', signed: true }, exec),
    },
    {
      name: 'kashijie_member_order_query',
      description: '使用代理自己的密钥查询会员充值订单状态，只能查询该密钥所属账号的订单。',
      parameters: { order_no: { type: 'string', required: true, description: '卡世界会员订单号' } },
      output: jsonOutput,
      timeoutMs: 30000,
      execute: (args, exec) => call({
        path: '/v1/order/query', signed: true,
        body: { order_no: requiredString(args.order_no, '订单号') },
      }, exec),
    },
    {
      name: 'kashijie_member_order_create',
      description: '创建真实会员充值订单并扣代理预充值余额。仅在用户明确同意扣款时调用，confirm 必须为 true；充值失败由平台自动退款。',
      parameters: {
        goods_id: { type: 'integer', required: true, description: '会员商品 goods_id' },
        recharge_account: { type: 'string', required: true, description: '实际充值账号，必须先向用户核对' },
        attach: { type: 'string', description: '代理自定义附加信息' },
        confirm: { type: 'boolean', required: true, description: '用户明确同意真实扣款时才为 true' },
      },
      output: jsonOutput,
      timeoutMs: 45000,
      execute: (args, exec) => {
        assertConfirmed(args.confirm)
        return call({
          path: '/v1/order/create', signed: true,
          body: {
            goods_id: args.goods_id,
            recharge_account: requiredString(args.recharge_account, '充值账号'),
            attach: args.attach,
          },
        }, exec)
      },
    },
  ]
}

export function apply(ctx, config = {}) {
  for (const definition of createTools(config)) ctx.tools.register(defineTool(definition))
}
