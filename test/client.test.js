import test from 'node:test'
import assert from 'node:assert/strict'
import {
  KashijieClient, signature, signedParams, compact, safeMessage,
  memberSuccess, supplySuccess, jsonOutput,
} from '../lib/client.js'

const jsonResponse = (value) => new Response(JSON.stringify(value), {
  status: 200,
  headers: { 'Content-Type': 'application/json' },
})

test('signature uses sorted scalar params and skips sign, empty and object values', () => {
  const params = {
    b: 2,
    a: 1,
    sign: 'ignored',
    empty: '',
    nil: null,
    missing: undefined,
    arr: [1],
    obj: { x: 1 },
    zero: 0,
    bool: false,
  }
  assert.equal(signature(params, 'test-secret'), 'ae79a24347b882f51ce9cb467d73f58e')
})

test('signedParams uses a fixed timestamp and fails closed without both credentials', () => {
  const result = signedParams({ goods_id: 7 }, 'key', 'secret', 1700000000)
  assert.equal(result.app_key, 'key')
  assert.equal(result.timestamp, 1700000000)
  assert.equal(result.sign, signature({ goods_id: 7, app_key: 'key', timestamp: 1700000000 }, 'secret'))
  assert.throws(() => signedParams({}, '', 'secret'), /缺少卡世界密钥/)
  assert.throws(() => signedParams({}, 'key', ''), /缺少卡世界密钥/)
})

test('compact keeps zero and false but removes empty values', () => {
  assert.deepEqual(compact({ a: 0, b: false, c: '', d: null, e: undefined, f: 'x' }), { a: 0, b: false, f: 'x' })
})

test('signed GET puts credentials and signature in the query string', async () => {
  let captured
  const controller = new AbortController()
  const client = new KashijieClient({
    baseUrl: 'https://mock.local/open-api', appKey: 'key', appSecret: 'secret',
    fetchImpl: async (url, options) => {
      captured = { url, options }
      return jsonResponse({ code: 0 })
    },
  })
  await client.request({ method: 'GET', path: '/v1/balance', query: { unused: ['skipped'] }, signed: true }, { signal: controller.signal })
  const params = captured.url.searchParams
  assert.equal(captured.options.method, 'GET')
  assert.equal(captured.options.redirect, 'error')
  assert.equal(captured.options.signal, controller.signal)
  assert.equal(captured.options.body, undefined)
  assert.equal(params.has('unused'), false)
  assert.equal(params.get('app_key'), 'key')
  const timestamp = Number(params.get('timestamp'))
  assert.equal(params.get('sign'), signature({ app_key: 'key', timestamp }, 'secret'))
})

test('signed POST signs merged query and body scalars and keeps business fields', async () => {
  let captured
  const client = new KashijieClient({
    baseUrl: 'https://mock.local/open-api', appKey: 'key', appSecret: 'secret',
    fetchImpl: async (url, options) => {
      captured = { url, options }
      return jsonResponse({ code: 0 })
    },
  })
  await client.request({
    path: '/v1/order/create', signed: true,
    query: { z: 'last', a: 'first', ignored: [1, 2] },
    body: { b: 'body', zero: 0, nested: { ignored: true }, empty: '' },
  })
  const body = JSON.parse(captured.options.body)
  assert.equal(captured.url.searchParams.get('a'), 'first')
  assert.equal(captured.url.searchParams.has('ignored'), false)
  assert.equal(body.b, 'body')
  assert.equal(body.zero, 0)
  assert.equal(body.app_key, 'key')
  assert.deepEqual(body.nested, { ignored: true })
  assert.equal('empty' in body, false)
  assert.equal(body.sign, signature({ z: 'last', a: 'first', b: 'body', zero: 0, app_key: 'key', timestamp: body.timestamp }, 'secret'))
})

test('signed POST without a business body still sends all auth params', async () => {
  let captured
  const client = new KashijieClient({
    baseUrl: 'https://mock.local', appKey: 'key', appSecret: 'secret',
    fetchImpl: async (_url, options) => {
      captured = options
      return jsonResponse({ code: 0 })
    },
  })
  await client.request({ path: '/v1/test', signed: true })
  const body = JSON.parse(captured.body)
  assert.equal(body.app_key, 'key')
  assert.ok(body.timestamp)
  assert.match(body.sign, /^[a-f0-9]{32}$/)
})

test('missing credentials fail before any network request', async () => {
  let calls = 0
  const client = new KashijieClient({
    baseUrl: 'https://mock.local', appKey: '', appSecret: '',
    fetchImpl: async () => { calls += 1; return jsonResponse({ code: 0 }) },
  })
  await assert.rejects(client.request({ path: '/v1/balance', method: 'GET', signed: true }), /缺少卡世界密钥/)
  assert.equal(calls, 0)
})

test('HTTP errors do not read or expose the response body', async () => {
  const client = new KashijieClient({
    baseUrl: 'https://mock.local',
    fetchImpl: async () => ({
      ok: false,
      status: 503,
      text: async () => { throw new Error('response body must not be read') },
    }),
  })
  await assert.rejects(client.request({ path: '/v1/test' }), /HTTP 503/)
})

test('invalid JSON and oversized responses are rejected', async () => {
  const invalid = new KashijieClient({ baseUrl: 'https://mock.local', fetchImpl: async () => new Response('not-json') })
  await assert.rejects(invalid.request({ path: '/v1/test' }), /有效 JSON/)
  const oversized = new KashijieClient({ baseUrl: 'https://mock.local', fetchImpl: async () => new Response('x'.repeat(4_000_001)) })
  await assert.rejects(oversized.request({ path: '/v1/test' }), /响应过大/)
})

test('connection errors and business errors are sanitized', async () => {
  const client = new KashijieClient({
    baseUrl: 'https://mock.local', appSecret: 'very-secret',
    fetchImpl: async () => { throw new Error('failed https://internal.local/path very-secret') },
  })
  await assert.rejects(client.request({ path: '/v1/test' }), (error) => {
    assert.doesNotMatch(error.message, /internal\.local|very-secret/)
    assert.match(error.message, /链接已隐藏/)
    assert.match(error.message, /已隐藏/)
    return true
  })
  assert.throws(() => memberSuccess({ code: 400, message: 'bad very-secret https://internal.local' }, 'very-secret'), (error) => {
    assert.doesNotMatch(error.message, /very-secret|internal\.local/)
    return true
  })
  assert.throws(() => supplySuccess({ success: false, msg: 'failed' }), /failed/)
  assert.throws(() => supplySuccess({ code: 500, message: 'failed' }), /failed/)
  assert.doesNotThrow(() => memberSuccess({ code: 0 }))
  assert.doesNotThrow(() => supplySuccess({ code: 200 }))
})

test('safeMessage limits length and hides common token forms', () => {
  const message = safeMessage(`github_pat_abcdefgh sk-abcdefgh ${'x'.repeat(400)}`)
  assert.equal(message.length <= 300, true)
  assert.doesNotMatch(message, /github_pat_|sk-/)
})

test('jsonOutput render is a pure JSON text projection', () => {
  assert.deepEqual(jsonOutput.render({}, { ok: true, result: { id: 7 } }), [
    { type: 'text', text: '{"ok":true,"result":{"id":7}}' },
  ])
})
