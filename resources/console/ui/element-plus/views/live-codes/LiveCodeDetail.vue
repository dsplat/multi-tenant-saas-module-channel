<template>
  <div class="page-container">
    <el-card v-loading="loading">
      <template #header>
        <div class="card-head">
          <span>活码详情</span>
          <el-button size="small" plain @click="handleBack">返回列表</el-button>
        </div>
      </template>
      <el-row :gutter="20">
        <el-col :span="8">
          <el-card shadow="never">
            <template #header> 二维码预览 </template>
            <div class="qr-box">
              <!-- 微信带参二维码优先：扫码关注能拿到 openid + scene 做真实归因 -->
              <template v-if="wechatQrcode?.qrcode_image_url">
                <img :src="wechatQrcode.qrcode_image_url" alt="微信带参二维码" class="qr-img" />
                <el-tag type="success" size="small" effect="plain">微信带参二维码</el-tag>
                <div class="qr-meta">
                  <p>场景值：{{ wechatQrcode.scene }}</p>
                  <p v-if="wechatQrcode.permanent">类型：永久有效</p>
                  <p v-else>类型：临时（{{ Math.round((wechatQrcode.expire_seconds || 0) / 86400) }} 天）</p>
                  <p>生成时间：{{ wechatQrcode.generated_at || '—' }}</p>
                </div>
              </template>
              <!-- 回退：自有短码只有落地页链接，扫码人身份拿不到 -->
              <template v-else>
                <el-empty description="尚未生成微信带参二维码" :image-size="80">
                  <div class="qr-fallback">
                    <p class="fallback-url">{{ liveCode.target_url || '（未配置目标地址）' }}</p>
                    <el-button
                      type="primary"
                      size="small"
                      :loading="generating"
                      @click="openGenerate"
                    >
                      生成微信带参二维码
                    </el-button>
                    <p class="fallback-tip">
                      需先在「渠道管理 → 微信」配置已认证服务号凭证；生成后扫码关注会自动归因到本活码。
                    </p>
                  </div>
                </el-empty>
              </template>
            </div>
          </el-card>
        </el-col>
        <el-col :span="16">
          <el-card shadow="never" class="mb-16">
            <template #header> 基本信息 </template>
            <el-descriptions :column="2" border>
              <el-descriptions-item label="活码编码">{{ liveCode.code || '—' }}</el-descriptions-item>
              <el-descriptions-item label="类型">{{ typeLabel }}</el-descriptions-item>
              <el-descriptions-item label="渠道标识">{{ liveCode.channel || '—' }}</el-descriptions-item>
              <el-descriptions-item label="状态">
                <el-tag :type="liveCode.status === 'active' ? 'success' : 'info'" size="small">
                  {{ liveCode.status || '—' }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="目标地址" :span="2">
                {{ liveCode.target_url || '—' }}
              </el-descriptions-item>
              <el-descriptions-item label="过期时间">{{ liveCode.expire_at || '永久有效' }}</el-descriptions-item>
              <el-descriptions-item label="创建时间">{{ liveCode.created_at || '—' }}</el-descriptions-item>
            </el-descriptions>
          </el-card>
          <el-card shadow="never">
            <template #header> 扫码统计 </template>
            <el-descriptions :column="3" border>
              <el-descriptions-item label="总扫码次数">
                {{ stats.total_scans ?? 0 }}
              </el-descriptions-item>
              <el-descriptions-item label="新增关注">
                {{ stats.total_adds ?? 0 }}
              </el-descriptions-item>
              <el-descriptions-item label="今日扫码">
                {{ todayScans }}
              </el-descriptions-item>
            </el-descriptions>
            <div v-if="channelStats.length" class="channel-stats">
              <span class="channel-stats-title">渠道分布：</span>
              <el-tag
                v-for="item in channelStats"
                :key="item.name"
                size="small"
                effect="plain"
                class="channel-tag"
              >
                {{ item.name }} {{ item.count }}
              </el-tag>
            </div>
          </el-card>
        </el-col>
      </el-row>
    </el-card>

    <el-dialog v-model="generateVisible" title="生成微信带参二维码" width="460px">
      <el-form label-position="top">
        <el-form-item label="场景值（scene）">
          <el-input :model-value="defaultScene" disabled />
          <div class="form-tip">扫码事件按此值归因到本活码，默认由活码 ID 生成，无需修改。</div>
        </el-form-item>
        <el-form-item label="有效期">
          <el-radio-group v-model="permanent">
            <el-radio :value="true">永久（推荐，可复用不重复占配额）</el-radio>
            <el-radio :value="false">临时 30 天</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="generateVisible = false">取消</el-button>
        <el-button type="primary" :loading="generating" @click="handleGenerate">生成</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { http, moduleApiPrefix } from '@multi-tenant-saas/console/shared/http'

defineOptions({ name: 'LiveCodeDetail' })

const route = useRoute()
const router = useRouter()
const liveCodeId = route.params.id as string

const loading = ref(false)
const generating = ref(false)
const generateVisible = ref(false)
const permanent = ref(true)
const liveCode = ref<Record<string, any>>({})
const stats = ref<Record<string, any>>({})
const todayScans = ref(0)

const TYPE_LABELS: Record<string, string> = {
  qrcode: '二维码',
  short_link: '短链接',
  channel: '渠道码',
  store: '门店码',
  employee: '员工码',
}

const wechatQrcode = computed<Record<string, any> | null>(
  () => liveCode.value?.metadata?.wechat_qrcode ?? null,
)
const typeLabel = computed(() => TYPE_LABELS[liveCode.value?.type] ?? liveCode.value?.type ?? '—')
const defaultScene = computed(() => `lc_${liveCodeId}`)
const channelStats = computed(() =>
  Object.entries((stats.value?.channel_stats ?? {}) as Record<string, number>).map(
    ([name, count]) => ({ name, count }),
  ),
)

async function loadLiveCode() {
  if (!liveCodeId) return
  loading.value = true
  try {
    const res = (await http.get(`${moduleApiPrefix('channel')}/live-codes/${liveCodeId}`)) as any
    liveCode.value = res?.data ?? res ?? {}
    stats.value = liveCode.value?.stats ?? {}
    await loadScanStats()
  } catch {
    ElMessage.error('加载活码详情失败')
  } finally {
    loading.value = false
  }
}

/**
 * 扫码统计（一次请求取全）
 *
 * 详情页内嵌的 stats 只有 summary（累计值，来自 metadata 计数器），今日扫码
 * 只能从 daily_trend 取 —— 趋势区间恒以「今天」收尾，故末位即今日。
 */
async function loadScanStats() {
  try {
    const res = (await http.get(`${moduleApiPrefix('channel')}/live-codes/${liveCodeId}/stats`)) as any
    const data = res?.data ?? {}
    stats.value = { ...(stats.value ?? {}), ...(data.summary ?? {}), channel_stats: data.channel_stats ?? {} }
    const trend = data.daily_trend ?? []
    todayScans.value = trend.length ? Number(trend[trend.length - 1]?.scans ?? 0) : 0
  } catch {
    todayScans.value = 0
  }
}

function openGenerate() {
  permanent.value = true
  generateVisible.value = true
}

async function handleGenerate() {
  generating.value = true
  try {
    const res = (await http.post(`${moduleApiPrefix('channel')}/live-codes/${liveCodeId}/wechat-qrcode`, {
      scene: defaultScene.value,
      permanent: permanent.value,
    })) as any
    ElMessage.success(res?.message || '微信带参二维码已生成')
    generateVisible.value = false
    await loadLiveCode()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '生成失败，请确认公众号凭证已配置且为已认证服务号')
  } finally {
    generating.value = false
  }
}

function handleBack() {
  router.push('/live-codes')
}

onMounted(loadLiveCode)
</script>
<style scoped lang="scss">
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.mb-16 {
  margin-bottom: 16px;
}

.qr-box {
  text-align: center;
  padding: 12px 8px;

  .qr-img {
    max-width: 200px;
    margin-bottom: 10px;
    border: 1px solid #ebeef5;
    border-radius: 6px;
  }

  .qr-meta {
    margin-top: 10px;
    font-size: 12px;
    color: #909399;
    text-align: left;

    p {
      margin: 4px 0;
      word-break: break-all;
    }
  }
}

.qr-fallback {
  .fallback-url {
    font-size: 12px;
    color: #606266;
    word-break: break-all;
    margin: 0 0 10px;
  }

  .fallback-tip {
    font-size: 12px;
    color: #909399;
    line-height: 1.6;
    margin: 10px 0 0;
    text-align: left;
  }
}

.form-tip {
  font-size: 12px;
  color: #909399;
  line-height: 1.5;
  margin-top: 4px;
}

.channel-stats {
  margin-top: 12px;
  font-size: 12px;

  .channel-stats-title {
    color: #909399;
    margin-right: 6px;
  }

  .channel-tag {
    margin-right: 6px;
  }
}
</style>
