<template>
  <div class="page-container">
    <el-page-header @back="$router.push('/channels')">
      <template #content>
        <span class="page-title">{{ isNew ? '新建渠道' : '渠道配置' }}</span>
      </template>
    </el-page-header>

    <!-- 代开发授权态提示：租户消息走平台服务商链路，自建回调配置让位 -->
    <el-alert
      v-if="callbackInfo?.suite_active"
      type="info"
      :closable="false"
      show-icon
      class="mt-16"
      title="当前企业微信为平台代开发授权模式"
      description="企业微信应用由平台服务商代管（消息经平台统一回调接入），无需在此配置自建应用消息回调；如需使用自建应用请先在「企业微信接入」中解除代开发授权。"
    />

    <el-card v-loading="loading" class="mt-20">
      <!-- 向导步骤：应用凭证 → 消息回调 → 连接验证 -->
      <el-steps :active="stepsActive" align-center class="wizard-steps" finish-status="success">
        <el-step title="① 应用凭证" description="企业微信后台取值填写" />
        <el-step v-if="hasCallback" title="② 消息回调" description="URL / Token / AESKey 三件套" />
        <el-step v-else title="② 连接验证" description="保存后测试连接" />
        <el-step title="③ 连接验证" description="后台保存 URL + 测试连接" />
      </el-steps>

      <!-- ═══════════ 步骤① 应用凭证 ═══════════ -->
      <div v-show="activeStep === 0" class="wizard-panel">
        <el-form :model="form" label-width="150px" class="mt-20">
          <el-form-item label="渠道类型" required>
            <el-select v-model="form.type" placeholder="选择渠道类型" :disabled="!isNew" style="width: 320px">
              <el-option label="企业微信" value="wechat_work" />
              <el-option label="微信公众号" value="wechat_official" />
              <el-option label="Telegram" value="telegram" />
            </el-select>
          </el-form-item>
          <el-form-item label="渠道名称" required>
            <el-input v-model="form.name" placeholder="如：客户服务 / 会员中心" style="width: 320px" />
          </el-form-item>

          <template v-if="form.type === 'wechat_work'">
            <el-form-item label="CorpID" required>
              <el-input v-model="form.app_id" placeholder="ww 开头，如 wwbeda7696fa851722" style="width: 360px" />
              <div class="form-tip">企业唯一标识。位置：企微管理后台「我的企业 → 企业信息 → 企业ID」</div>
            </el-form-item>
            <el-form-item label="AgentId" required>
              <el-input v-model="form.agent_id" placeholder="应用 ID（纯数字）" style="width: 360px" />
              <div class="form-tip">位置：企微管理后台「应用管理 → 自建 → 应用详情 → AgentId」</div>
            </el-form-item>
            <el-form-item label="Secret" required>
              <el-input v-model="form.app_secret" type="password" show-password placeholder="应用密钥（查看需管理员确认）" style="width: 360px" />
              <div class="form-tip">位置：应用详情 → Secret 旁点「查看」→ 企业微信 App 确认后展示</div>
            </el-form-item>
          </template>

          <template v-else-if="form.type === 'wechat_official'">
            <el-form-item label="AppID" required>
              <el-input v-model="form.app_id" placeholder="wx 开头" style="width: 360px" />
              <div class="form-tip">位置：微信公众平台「设置与开发 → 基本配置 → 公众号开发信息」</div>
            </el-form-item>
            <el-form-item label="AppSecret" required>
              <el-input v-model="form.app_secret" type="password" show-password placeholder="开发者密码" style="width: 360px" />
              <div class="form-tip">位置：同上，AppSecret 旁「重置」前需管理员扫码确认；仅认证服务号可用完整能力</div>
            </el-form-item>
          </template>

          <template v-else>
            <el-form-item label="Bot Token" required>
              <el-input v-model="form.app_id" type="password" show-password placeholder="从 @BotFather 获取的 HTTP API Token" style="width: 360px" />
              <div class="form-tip">Telegram 渠道无消息回调：Token 即全部配置（BotFather → /mybots → API Token），保存后可直接测试连接</div>
            </el-form-item>
          </template>

          <!-- 企业微信取值指引（折叠帮助） -->
          <el-form-item v-if="form.type === 'wechat_work'">
            <el-collapse class="help-collapse" style="width: 100%">
              <el-collapse-item title="💡 还没创建应用？查看企业微信后台完整操作指引" name="guide">
                <ol class="help-ol">
                  <li>登录 <b>work.weixin.qq.com</b> 管理后台（需管理员权限）；</li>
                  <li><b>应用管理 → 自建 → 创建应用</b>：填应用名与 Logo，可见范围选内部成员；</li>
                  <li>进入应用详情，记录 <b>AgentId</b>；点 <b>Secret → 查看</b>，在企业微信 App 确认后复制 Secret；</li>
                  <li>回到「我的企业 → 企业信息」复制 <b>CorpID</b>（ww 开头）；</li>
                  <li>以上三项填到本页并保存，再进入下一步配置「消息回调」。</li>
                </ol>
                <div class="form-tip">提示：应用在「接收消息」开启前不可收发消息；接收消息的配置在下一步完成。</div>
              </el-collapse-item>
            </el-collapse>
          </el-form-item>
        </el-form>
      </div>

      <!-- ═══════════ 步骤② 消息回调 ═══════════ -->
      <div v-if="!isNew && hasCallback" v-show="activeStep === 1" class="wizard-panel">
        <!-- 域名三态（企微自建应用：回调 URL 必须落在与企微认证主体一致的备案域名上） -->
        <el-alert
          v-if="isWechatWork && callbackInfo?.custom_domain_active"
          type="success"
          :closable="false"
          show-icon
          class="mt-16"
          title="回调 URL 已使用你的自定义域名"
          :description="`当前回调落在已启用的自定义域名 ${callbackInfo.callback_host} 上——备案主体与企微企业主体一致，可通过企微「域名主体校验」。请将 URL 复制到企微后台「应用 → 接收消息 → 设置 API 接收」保存。`"
        />
        <el-alert
          v-else-if="isWechatWork && callbackInfo?.custom_domain"
          type="warning"
          :closable="false"
          show-icon
          class="mt-16"
          title="自定义域名已绑定但尚未启用"
          :description="`当前展示的仍是平台统一回调域。企微后台保存回调时会校验域名备案主体（报错：域名主体校验未通过，需配置备案主体与当前企业主体相同或有关联关系的域名）。请先在「租户设置 → 域名设置」启用 ${callbackInfo.custom_domain}，回调 URL 将自动切换。`"
        />
        <el-alert
          v-else-if="isWechatWork"
          type="warning"
          :closable="false"
          show-icon
          class="mt-16"
          title="自建应用消息回调必须使用企业备案域名"
          description="企微「设置 API 接收」保存时会校验 URL 域名备案主体与当前企业主体一致（报错：域名主体校验未通过）。平台统一回调域备案主体为平台公司，无法通过校验。请先在「租户设置 → 域名设置」绑定并启用与企微认证主体相同的备案域名，回调 URL 将自动切换；当前可在本页先行配置 Token / EncodingAESKey。"
        />
        <el-alert
          v-else
          type="info"
          :closable="false"
          show-icon
          class="mt-16"
          title="服务器配置 URL"
          description="回调使用平台统一回调域，复制到公众号后台「设置与开发 → 基本配置 → 服务器配置」填写。"
        />

        <el-form :model="form" label-width="150px" class="mt-20">
          <el-form-item :label="isWechatWork ? '回调 URL' : '服务器 URL'">
            <el-input :model-value="callbackUrl" readonly :placeholder="callbackInfo ? '' : '保存应用凭证后生成'">
              <template #append>
                <el-button :disabled="!callbackUrl" @click="copyUrl">复制</el-button>
              </template>
            </el-input>
            <div class="form-tip">保存后 URL 根据自定义域名状态自动切换{{ isWechatWork ? '（须为与企微认证主体一致的备案域名）' : '' }}；复制到{{ isWechatWork ? '企微' : '公众号' }}后台「接收消息 / 服务器配置」的 URL 输入框</div>
          </el-form-item>
          <el-form-item :label="isWechatWork ? 'Token' : '令牌 Token'">
            <el-input v-model="form.callback_token" placeholder="3-32 位，可手填或随机生成" style="width: 360px">
              <template #append>
                <el-button :loading="generating" @click="handleGenerateCredentials">随机生成</el-button>
              </template>
            </el-input>
            <div class="form-tip">用于消息签名校验。两种取法任选其一：① 本页「随机生成」（保存后复制三件套去{{ isWechatWork ? '企微' : '公众号' }}后台）；② 在{{ isWechatWork ? '企微' : '公众号' }}后台点「随机获取」后把值回填本页保存。两边必须完全一致</div>
          </el-form-item>
          <el-form-item :label="isWechatWork ? 'EncodingAESKey' : '消息加解密密钥'">
            <el-input v-model="form.encoding_aes_key" placeholder="43 位，可手填或随机生成" style="width: 360px" />
            <div class="form-tip">消息体加解密密钥（43 位）。{{ isWechatWork ? '企微' : '公众号' }}后台「随机获取」的 43 位值可直接粘贴；也可用本页随机生成</div>
          </el-form-item>

          <!-- 会话存档（保留既有能力） -->
          <template v-if="isWechatWork">
            <el-divider content-position="left">会话存档（可选，付费权限）</el-divider>
            <el-form-item label="存档私钥 PEM">
              <el-input
                v-model="form.session_archive_key"
                type="textarea"
                :rows="4"
                placeholder="企微「会话内容存档」后台生成的 RSA 私钥（-----BEGIN PRIVATE KEY----- 开头）"
              />
              <div class="form-tip">会话存档为企微独立付费权限，私钥在企微管理后台「安全与管理 → 会话内容存档」中申请，用于解密拉取到的聊天记录；不启用存档可留空。</div>
            </el-form-item>
          </template>

          <el-form-item>
            <el-button type="primary" :loading="saving" @click="handleSave">保存回调参数</el-button>
          </el-form-item>

          <!-- 企业可信 IP（折叠帮助） -->
          <el-form-item v-if="isWechatWork">
            <el-collapse class="help-collapse" style="width: 100%">
              <el-collapse-item title="💡 去企业微信后台粘贴并验证（顺序敏感，必读）" name="guide">
                <ol class="help-ol">
                  <li>企微后台 → 应用详情 → <b>「接收消息」→ 设置 API 接收</b>；</li>
                  <li>URL 粘贴本页上方回调 URL，Token / EncodingAESKey 与本页<b>完全一致</b>；</li>
                  <li>勾选需要接收的事件（消息收发至少勾选「用户发送的普通消息」）；</li>
                  <li>点击<b>保存</b>：企微立即向 URL 发起 GET 验证（需 1 秒内正确响应），提示成功后回调即生效；</li>
                  <li>验证失败时检查：URL 可公网访问、Token/AESKey 与本页一致、URL 末尾无多余字符。</li>
                </ol>
                <div class="form-tip">补充：如后续需要主动发消息（如欢迎语、通知），还需在应用「开发者接口 → 企业可信 IP」填入平台服务器公网出口 IP（接收消息不受此限制）；请向平台获取该 IP。</div>
              </el-collapse-item>
            </el-collapse>
          </el-form-item>
          <el-form-item v-else>
            <el-collapse class="help-collapse" style="width: 100%">
              <el-collapse-item title="💡 去微信公众平台配置（顺序敏感，必读）" name="guide">
                <ol class="help-ol">
                  <li>公众平台 → <b>「设置与开发 → 基本配置 → 服务器配置」</b>；</li>
                  <li>服务器 URL 粘贴本页上方 URL，Token / EncodingAESKey 与本页完全一致；</li>
                  <li>消息加解密方式建议选「安全模式」；提交后公众平台发起 GET 验证，成功后点击「启用」。</li>
                </ol>
              </el-collapse-item>
            </el-collapse>
          </el-form-item>
        </el-form>
      </div>

      <!-- ═══════════ 步骤③（telegram 无回调时即步骤②）连接验证 ═══════════ -->
      <div v-if="!isNew" v-show="(hasCallback ? activeStep === 2 : activeStep === 1)" class="wizard-panel">
        <el-form label-width="150px" class="mt-20">
          <el-form-item label="连接状态">
            <el-tag :type="connectionInfo?.connected ? 'success' : 'warning'" size="large">
              {{ connectionInfo?.connected ? '已连接' : '未连接' }}
            </el-tag>
            <div v-if="isWechatWork && !callbackInfo?.suite_active" class="form-tip">
              点击「测试连接」将校验应用凭证并读取企业信息；凭证有效后连接成功。若上一步已在企微后台保存成功但此处失败，多为 Secret 填写有误或尚未配置「企业可信 IP」。
            </div>
            <div v-else-if="form.type === 'telegram'" class="form-tip">Telegram 渠道保存 Bot Token 后即可测试连接（无需回调配置）。</div>
          </el-form-item>
          <el-form-item label="连接测试">
            <el-button v-if="connectionInfo?.connected" type="success" @click="$router.push('/channels')">完成</el-button>
            <el-button v-else type="success" :loading="testing" @click="handleTest">测试连接</el-button>
            <el-button @click="$router.push('/channels')">返回列表</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 步骤导航（每步独立暂存：①保存应用凭证 ②保存回调三件套 ③测试连接） -->
      <div class="wizard-nav">
        <el-button v-if="activeStep > 0" @click="activeStep--">上一步</el-button>
        <el-button v-if="!isNew && activeStep === 0" type="primary" :loading="saving" @click="handleSave">保存</el-button>
        <el-button v-if="!isNew && activeStep < stepCount - 1" type="primary" @click="activeStep++">下一步</el-button>
        <el-button v-if="isNew" type="primary" :loading="saving" @click="handleSave">保存并进入下一步</el-button>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { http } from '@scrm/shared'

const route = useRoute()
const router = useRouter()
const channelId = route.params.id as string
const isNew = !channelId || channelId === 'new'

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const generating = ref(false)
const connectionInfo = ref<any>(null)

/** 向导步骤（telegram 无回调步骤，2 步） */
const activeStep = ref(isNew ? 0 : Number(route.query.step || 0))

const form = ref({
  type: 'wechat_work',
  name: '',
  app_id: '',
  app_secret: '',
  agent_id: '',
  callback_token: '',
  encoding_aes_key: '',
  session_archive_key: '',
  status: 'disconnected',
})

const isWechatWork = computed(() => form.value.type === 'wechat_work')
/** 是否有消息回调配置环节（Telegram 无） */
const hasCallback = computed(() => form.value.type !== 'telegram')
const stepCount = computed(() => (hasCallback.value ? 3 : 2))
/** 向导步骤进度：连接验证通过后整体呈现完成（步骤条全绿） */
const stepsActive = computed(() => (connectionInfo.value?.connected ? stepCount.value : activeStep.value))

/** 回调信息：由后端 callback-info 权威生成（URL 按自定义域名状态三态联动） */
const callbackInfo = ref<any>(null)
const callbackUrl = computed(() => callbackInfo.value?.callback_url || '')

function copyUrl() {
  navigator.clipboard.writeText(callbackUrl.value)
  ElMessage.success('已复制')
}

async function loadCallbackInfo() {
  if (isNew) return
  try {
    const res = await http.get(`/biz/channels/${channelId}/callback-info`) as any
    const data = res?.data ?? res
    callbackInfo.value = data?.data ?? data
  } catch { /* 回调信息为辅助展示，失败不阻塞表单编辑 */ }
}

async function loadChannel() {
  if (isNew) return
  loading.value = true
  try {
    const res = await http.get(`/biz/channels/${channelId}`) as any
    const data = res?.data ?? res
    if (data) {
      form.value = {
        type: data.type || 'wechat_work',
        name: data.name || '',
        app_id: data.app_id || '',
        app_secret: data.app_secret || '',
        agent_id: data.agent_id || '',
        callback_token: data.callback_token || '',
        encoding_aes_key: data.encoding_aes_key || '',
        session_archive_key: data.metadata?.session_archive?.private_key || '',
        status: data.status || 'disconnected',
      }
      connectionInfo.value = { connected: data.status === 'connected' }
    }
  } catch (e: any) {
    ElMessage.error(e?.message || '加载渠道配置失败')
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (!form.value.name) {
    ElMessage.warning('请输入渠道名称')
    return
  }
  saving.value = true
  try {
    const payload: Record<string, any> = { ...form.value }
    // 会话存档私钥单独放入 metadata（后端合并更新，保留既有键）
    payload.metadata = {
      session_archive: { private_key: form.value.session_archive_key },
    }
    delete payload.session_archive_key
    if (isNew) {
      delete payload.status
      const res = (await http.post('/biz/channels', payload)) as any
      const created = res?.data ?? res
      ElMessage.success('配置已保存，进入下一步配置消息回调')
      const newId = created?.channel_id
      router.push(newId ? `/channels/${newId}?step=1` : '/channels')
    } else {
      await http.put(`/biz/channels/${channelId}`, payload)
      ElMessage.success(activeStep.value === 0 ? '应用凭证已保存，可进入下一步' : '回调参数已保存')
      await loadCallbackInfo()
    }
  } catch (e: any) {
    ElMessage.error(e?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

/** 随机生成 Token/EncodingAESKey（落库并同步框架凭证），用于「平台生成」取法 */
async function handleGenerateCredentials() {
  generating.value = true
  try {
    const res = await http.post(`/biz/channels/${channelId}/generate-callback-credentials`) as any
    const data = res?.data ?? res
    const cred = data?.data ?? data
    form.value.callback_token = cred.callback_token
    form.value.encoding_aes_key = cred.encoding_aes_key
    ElMessage.success('已生成并保存，请复制到企微后台（URL + Token + EncodingAESKey 三件套一致）')
  } catch (e: any) {
    ElMessage.error(e?.message || '生成失败')
  } finally {
    generating.value = false
  }
}

async function handleTest() {
  testing.value = true
  try {
    const res = await http.post(`/biz/channels/${channelId}/test`) as any
    const data = res?.data ?? res
    if (data?.connected) {
      ElMessage.success(data.message || '连接成功')
      connectionInfo.value = { connected: true }
    } else {
      ElMessage.error(data?.message || '连接失败')
      connectionInfo.value = { connected: false }
    }
  } catch (e: any) {
    ElMessage.error(e?.message || '连接测试失败')
  } finally {
    testing.value = false
  }
}

onMounted(() => { loadChannel(); loadCallbackInfo() })
</script>

<style scoped lang="scss">
.page-container {
  padding: 20px;
}
.page-title {
  font-size: 18px;
  font-weight: 500;
}
.mt-16 {
  margin-top: 16px;
}
.mt-20 {
  margin-top: 20px;
}
.wizard-steps {
  padding: 12px 0 4px;
}
.wizard-panel {
  min-height: 260px;
}
.wizard-nav {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--el-border-color-lighter);
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.form-tip {
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.6;
  margin-top: 4px;
  width: 100%;
}
.help-collapse {
  border: none;
  :deep(.el-collapse-item__header) {
    background: transparent;
    font-size: 13px;
    color: var(--el-color-primary);
  }
}
.help-ol {
  padding-left: 20px;
  line-height: 1.9;
  font-size: 13px;
  margin: 0;
  color: var(--el-text-color-regular);
}
</style>
