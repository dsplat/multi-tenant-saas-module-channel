import { extractListResult, http, moduleApiPrefix } from '@multi-tenant-saas/console/shared/http'

/**
 * 欢迎语控制台 API（框架 Channel 模块默认皮肤）
 *
 * 归位说明：本文件原在下游（scrm）项目层 `app/Modules/Channel/api/welcome.ts`，随 Channel 模块
 * 物理归位一并收回框架模块（模块前端契约 C1：模块资源用 `@modules/Channel/api/*` 自解释引用，
 * 不许落到下游项目路径）。请求前缀不写死（契约 C2）：框架默认 `''` → `/api/v1/welcome-messages`；
 * 下游 scrm 设 `__MODULE_API_PREFIXES__.channel = '/biz'` → `/api/v1/biz/welcome-messages`（逐字不变）。
 */

/** 欢迎语配置（后端 welcome-messages，按渠道区分，如企微官方入群欢迎） */
export interface WelcomeMessage {
  message_id: number
  name: string
  channel: string
  content: string
  materials: WelcomeMaterial[]
  status: 'active' | 'inactive'
  created_at: string
  updated_at: string
  // 后端 snake_case 原始字段（容错）
  messageId?: number
  createdAt?: string
}

export interface WelcomeMaterial {
  type: 'text' | 'image' | 'link' | 'miniprogram'
  content: string
  title?: string
  url?: string
  thumbUrl?: string
}

export interface WelcomeMessageListParams {
  page: number
  pageSize: number
  name?: string
  channel?: string
}

export interface WelcomeMessageListResult {
  data: WelcomeMessage[]
  total: number
}

export interface CreateWelcomeMessageData {
  name: string
  channel: string
  content: string
  materials?: WelcomeMaterial[]
  status?: 'active' | 'inactive'
}

export interface UpdateWelcomeMessageData {
  name?: string
  channel?: string
  content?: string
  materials?: WelcomeMaterial[]
  status?: 'active' | 'inactive'
}

export const WELCOME_CHANNELS = [
  { label: '企业微信', value: 'wechat_work' },
  { label: '微信公众号', value: 'wechat_official' },
  { label: 'Telegram', value: 'telegram' },
  { label: '短信', value: 'sms' },
] as const

export async function getWelcomeMessageList(
  params: WelcomeMessageListParams,
): Promise<WelcomeMessageListResult> {
  const res = await http.get<WelcomeMessage[]>(`${moduleApiPrefix('channel')}/welcome-messages`, { params })
  return extractListResult(res)
}

export async function getWelcomeMessageDetail(id: number): Promise<WelcomeMessage> {
  const res = await http.get<WelcomeMessage>(`${moduleApiPrefix('channel')}/welcome-messages/${id}`)
  return res.data
}

export async function createWelcomeMessage(
  data: CreateWelcomeMessageData,
): Promise<WelcomeMessage> {
  const res = await http.post<WelcomeMessage>(`${moduleApiPrefix('channel')}/welcome-messages`, data)
  return res.data
}

export async function updateWelcomeMessage(
  id: number,
  data: UpdateWelcomeMessageData,
): Promise<WelcomeMessage> {
  const res = await http.put<WelcomeMessage>(`${moduleApiPrefix('channel')}/welcome-messages/${id}`, data)
  return res.data
}

export async function deleteWelcomeMessage(id: number): Promise<void> {
  await http.delete(`${moduleApiPrefix('channel')}/welcome-messages/${id}`)
}
