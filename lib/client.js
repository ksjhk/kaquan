import { createHash } from 'node:crypto'

export const jsonOutput = {
  schema: { type: 'object', additionalProperties: true },
  render: (_args, value) => [{ type: 'text', text: JSON.stringify(value) }],
}

export function compact(params = {}) {
  return Object.fromEntries(Object.entries(params).filter(([, value]) => (
    value !== undefined && value !== null && value !== ''
  )))
}

export function signature(params, appSecret) {
  const pairs = Object.keys(params)
    .filter((key) => key !== 'sign')
    .sort()
    .flatMap((key) => {
      const value = params[key]
      if (value === undefined || value === null || value === '' || typeof value === 'object') return []
      return [`${key}=${String(value)}`]
    })
  return createHash('md5').update(`${pairs.join('&')}&secret=${appSecret}`, 'utf8').digest('hex')
}

export function signedParams(params, appKey, appSecret, timestamp = Math.floor(Date.now() / 1000)) {
  if (!appKey || !appSecret) {
    throw new Error('缺少卡世界密钥。请配置 KASHIJIE_APP_KEY 和 KASHIJIE_APP_SECRET。')
  }
  const all = { ...params, app_key: appKey, timestamp }
  return { ...all, sign: signature(all, appSecret) }
}

export function assertConfirmed(confirm) {
  if (confirm !== true) {
    throw new Error('未获得用户对真实扣款订单的明确确认，未创建订单。')
  }
}

export function requiredString(value, label) {
  const text = String(value ?? '').trim()
  if (!text) throw new Error(`${label}不能为空。`)
  return text
}

export function validDate(value, label) {
  const text = requiredString(value, label)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) throw new Error(`${label}必须使用 YYYY-MM-DD 格式。`)
  return text
}

export function validMobile(value, label = '手机号') {
  const text = requiredString(value, label)
  if (!/^1\d{10}$/.test(text)) throw new Error(`${label}必须是 11 位中国大陆手机号。`)
  return text
}

export function safeMessage(value, appSecret = '') {
  let text = String(value ?? '接口调用失败').slice(0, 300)
  if (appSecret) text = text.split(appSecret).join('[已隐藏]')
  return text
  .replace(/https?:\/\/[^\s]+/gi, '[链接已隐藏]')
  .replace(/\b(?:github_pat_|ghp_|sk-)[A-Za-z0-9_-]+/g, '[已隐藏]')
}

export class KashijieClient {
  constructor({ baseUrl, appKey = '', appSecret = '', fetchImpl = fetch }) {
    this.baseUrl = String(baseUrl).replace(/\/+$/, '')
    this.appKey = appKey
    this.appSecret = appSecret
    this.fetchImpl = fetchImpl
  }

  async request({ method = 'POST', path, query = {}, body, signed = false, success }, exec = {}) {
    const queryParams = compact(query)
    let bodyParams = body === undefined ? undefined : compact(body)
    if (signed) {
      const merged = { ...queryParams, ...(bodyParams ?? {}) }
      const auth = signedParams(merged, this.appKey, this.appSecret)
      if (method === 'GET') Object.assign(queryParams, auth)
      else {
        bodyParams = bodyParams ?? {}
        Object.assign(bodyParams, auth)
      }
    }

    const url = new URL(this.baseUrl + path)
    for (const [key, value] of Object.entries(queryParams)) {
      if (typeof value !== 'object') url.searchParams.set(key, String(value))
    }

    let response
    try {
      response = await this.fetchImpl(url, {
        method,
        headers: {
          Accept: 'application/json',
          ...(bodyParams === undefined ? {} : { 'Content-Type': 'application/json' }),
        },
        body: bodyParams === undefined ? undefined : JSON.stringify(bodyParams),
        redirect: 'error',
        signal: exec.signal,
      })
    } catch (error) {
      throw new Error(`卡世界接口连接失败：${safeMessage(error?.message, this.appSecret)}`)
    }
    if (!response.ok) throw new Error(`卡世界接口返回 HTTP ${response.status}。`)

    const text = await response.text()
    if (text.length > 4_000_000) throw new Error('卡世界接口响应过大，已停止处理。')
    let result
    try {
      result = JSON.parse(text)
    } catch {
      throw new Error('卡世界接口未返回有效 JSON。')
    }
    if (success) success(result, this.appSecret)
    return { ok: true, result }
  }
}

export function memberSuccess(result, appSecret) {
  if (result?.code !== 0) throw new Error(safeMessage(result?.message ?? result?.msg, appSecret))
}

export function supplySuccess(result, appSecret) {
  if (result?.success === false || (result?.code !== undefined && Number(result.code) !== 200)) {
    throw new Error(safeMessage(result?.msg ?? result?.message, appSecret))
  }
}
