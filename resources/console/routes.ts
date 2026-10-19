import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 渠道管理
  { path: 'channels', name: 'ChannelList', component: view('channel', 'channels/ChannelList'), meta: { title: '渠道管理', permission: 'channel.view' } },
  { path: 'channels/new', name: 'ChannelCreate', component: view('channel', 'channels/ChannelDetail'), meta: { title: '新建渠道', permission: 'channel.manage' } },
  { path: 'channels/:id', name: 'ChannelDetail', component: view('channel', 'channels/ChannelDetail'), meta: { title: '渠道配置', permission: 'channel.manage' } },
  // 活码管理
  { path: 'live-codes', name: 'LiveCodeList', component: view('channel', 'live-codes/LiveCodeList'), meta: { title: '活码管理', permission: 'channel.view' } },
  { path: 'live-codes/new', name: 'LiveCodeCreate', component: view('channel', 'live-codes/LiveCodeCreate'), meta: { title: '新建活码', permission: 'channel.manage' } },
  { path: 'live-codes/:id', name: 'LiveCodeDetail', component: view('channel', 'live-codes/LiveCodeDetail'), meta: { title: '活码详情', permission: 'channel.manage' } },
  // 微信服务号配置
  { path: 'wechat/welcome', name: 'WelcomeMessage', component: view('channel', 'WelcomeMessage'), meta: { title: '欢迎语', permission: 'channel.view' } },
  // 欢迎语（兼容旧导航路径，正式路径为 wechat/welcome）
  { path: 'welcome', redirect: '/wechat/welcome' },
]

export default routes
