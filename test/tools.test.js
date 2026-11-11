import test from 'node:test'
import assert from 'node:assert/strict'
import { apply, createTools, inject } from '../index.js'
import { signature } from '../lib/client.js'

function mockConfig(result = { code: 0, data: { mocked: true } }) {
  const calls = []
  return {
    calls,
    config: {
      baseUrl: 'https://mock.local/api/open', appKey: 'key', appSecret: 'secret',
      fetchImpl: async (url, options) => {
        calls.push({ url, options })
        return new Response(JSON.stringify(result), { status: 200, headers: { 'Content-Type': 'application/json' } })
      },
    },
  }
}

const tool = (tools, name) => {
  const found = tools.find((item) => item.name === name)
  assert.ok(found, `missing tool ${name}`)
  return found
}

test('member plugin exposes exactly five expected tools', () => {
  const { config } = mockConfig()
  const tools = createTools(config)
  assert.deepEqual(tools.map((item) => item.name), [
    'kashijie_member_goods',
    'kashijie_member_goods_detail',
    'kashijie_member_balance',
    'kashijie_member_order_query',
    'kashijie_member_order_create',
  ])
  assert.deepEqual(inject, ['tools'])
})

test('member goods and detail use anonymous GET endpoints', async () => {
  const { config, calls } = mockConfig()
  const tools = createTools({ ...config, appKey: '', appSecret: '' })
  await tool(tools, 'kashijie_member_goods').execute({ category_id: 3, current: 2, size: 200 })
  assert.equal(calls[0].url.pathname, '/api/open/v1/goods')
  assert.equal(calls[0].options.method, 'GET')
  assert.equal(calls[0].url.searchParams.get('category_id'), '3')
  assert.equal(calls[0].url.searchParams.get('current'), '2')
  assert.equal(calls[0].url.searchParams.get('size'), '100')
  assert.equal(calls[0].url.searchParams.has('sign'), false)

  await tool(tools, 'kashijie_member_goods_detail').execute({ goods_id: 88 })
  assert.equal(calls[1].url.pathname, '/api/open/v1/goods/detail')
  assert.equal(calls[1].url.searchParams.get('goods_id'), '88')
  assert.equal(calls[1].url.searchParams.has('sign'), false)
})

test('member balance and order query are signed', async () => {
  const { config, calls } = mockConfig()
  const tools = createTools(config)
  await tool(tools, 'kashijie_member_balance').execute({})
  assert.equal(calls[0].url.pathname, '/api/open/v1/balance')
  assert.equal(calls[0].url.searchParams.get('app_key'), 'key')
  assert.match(calls[0].url.searchParams.get('sign'), /^[a-f0-9]{32}$/)

  await tool(tools, 'kashijie_member_order_query').execute({ order_no: 'order_mock' })
  const body = JSON.parse(calls[1].options.body)
  assert.equal(calls[1].url.pathname, '/api/open/v1/order/query')
  assert.equal(body.order_no, 'order_mock')
  assert.equal(body.sign, signature({ order_no: 'order_mock', app_key: 'key', timestamp: body.timestamp }, 'secret'))
})

test('member order create requires explicit boolean confirmation and sends zero requests otherwise', async () => {
  const { config, calls } = mockConfig()
  const tools = createTools(config)
  const create = tool(tools, 'kashijie_member_order_create')
  for (const confirm of [undefined, false, 'true', 1]) {
    await assert.rejects(async () => create.execute({ goods_id: 88, recharge_account: 'mock-account', confirm }), /明确确认/)
  }
  assert.equal(calls.length, 0)

  await create.execute({ goods_id: 88, recharge_account: 'mock-account', attach: 'mock-attach', confirm: true })
  assert.equal(calls.length, 1)
  assert.equal(calls[0].url.pathname, '/api/open/v1/order/create')
  const body = JSON.parse(calls[0].options.body)
  assert.equal(body.goods_id, 88)
  assert.equal(body.recharge_account, 'mock-account')
  assert.equal(body.attach, 'mock-attach')
  assert.equal('confirm' in body, false)
  assert.equal(body.sign, signature({ goods_id: 88, recharge_account: 'mock-account', attach: 'mock-attach', app_key: 'key', timestamp: body.timestamp }, 'secret'))
})

test('member apply registers valid DSH tool definitions', () => {
  const { config } = mockConfig()
  const registered = []
  apply({ tools: { register: (value) => registered.push(value) } }, config)
  assert.equal(registered.length, 5)
})
