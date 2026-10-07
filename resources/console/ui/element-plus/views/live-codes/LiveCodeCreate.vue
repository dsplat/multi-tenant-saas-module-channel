<template>
  <div class="live-code-create">
    <div class="page-head">
      <div class="head-text">
        <h1 class="head-title">新建活码</h1>
        <p class="head-desc">
          活码是可动态更新的二维码，扫码用户会被引导至目标地址，并可按渠道、门店、员工维度追踪扫码数据。
        </p>
      </div>
      <el-button plain @click="handleBack">返回列表</el-button>
    </div>

    <el-form
      ref="formRef"
      :model="form"
      :rules="rules"
      label-position="top"
      class="create-form"
      @submit.prevent
    >
      <el-card class="form-card">
        <template #header>
          <span class="card-title">选择活码类型</span>
        </template>
        <div class="type-grid">
          <div
            v-for="t in typeOptions"
            :key="t.value"
            class="type-card"
            :class="{ active: form.type === t.value }"
            @click="selectType(t.value)"
          >
            <div class="type-icon" :style="{ color: t.color }">
              <el-icon :size="24"><component :is="t.icon" /></el-icon>
            </div>
            <div class="type-name">{{ t.label }}</div>
            <div class="type-desc">{{ t.desc }}</div>
            <div v-if="form.type === t.value" class="type-check">
              <el-icon :size="14"><Check /></el-icon>
            </div>
          </div>
        </div>
      </el-card>

      <el-card class="form-card">
        <template #header>
          <span class="card-title">配置目标</span>
        </template>

        <el-form-item label="渠道标识" prop="channel">
          <el-input
            v-model="form.channel"
            placeholder="如：抖音投放、线下门店（用于区分来源，可选）"
            maxlength="100"
            show-word-limit
            clearable
          />
        </el-form-item>

        <el-form-item label="目标地址" prop="target_url">
          <el-input
            v-model="form.target_url"
            placeholder="扫码后跳转的链接，如 https://example.com"
            maxlength="500"
            clearable
          >
            <template #prefix>
              <el-icon><Link /></el-icon>
            </template>
          </el-input>
        </el-form-item>

        <el-form-item label="过期时间" prop="expire_at">
          <el-date-picker
            v-model="form.expire_at"
            type="datetime"
            placeholder="留空则永久有效"
            value-format="YYYY-MM-DD HH:mm:ss"
            style="width: 100%"
          />
        </el-form-item>
      </el-card>

      <div class="form-actions">
        <el-button @click="handleBack">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          {{ submitting ? '创建中…' : '创建活码' }}
        </el-button>
      </div>
    </el-form>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Grid, Link, Share, OfficeBuilding, User, Check } from '@element-plus/icons-vue'
import { http } from '@scrm/shared'

defineOptions({ name: 'LiveCodeCreate' })

const router = useRouter()
const formRef = ref<FormInstance>()
const submitting = ref(false)

const typeOptions = [
  { value: 'qrcode', label: '二维码', desc: '通用扫码跳转', icon: Grid, color: '#409eff' },
  { value: 'short_link', label: '短链接', desc: '生成短链便于分享', icon: Link, color: '#67c23a' },
  { value: 'channel', label: '渠道码', desc: '按投放渠道追踪', icon: Share, color: '#e6a23c' },
  { value: 'store', label: '门店码', desc: '按门店维度统计', icon: OfficeBuilding, color: '#f56c6c' },
  { value: 'employee', label: '员工码', desc: '按员工维度统计', icon: User, color: '#909399' },
]

const form = reactive({
  type: 'qrcode',
  channel: '',
  target_url: '',
  expire_at: '',
})

const rules: FormRules = {
  type: [{ required: true, message: '请选择活码类型', trigger: 'change' }],
}

function selectType(value: string) {
  form.type = value
}

function handleBack() {
  router.push('/live-codes')
}

async function handleSubmit() {
  if (!formRef.value) return
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    const payload: Record<string, any> = { type: form.type }
    if (form.channel) payload.channel = form.channel
    if (form.target_url) payload.target_url = form.target_url
    if (form.expire_at) payload.expire_at = form.expire_at

    await http.post('/biz/live-codes', payload)
    ElMessage.success('活码创建成功')
    router.push('/live-codes')
  } catch {
    ElMessage.error('创建失败，请重试')
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped lang="scss">
.live-code-create {
  .page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;

    .head-title {
      font-size: 22px;
      font-weight: 700;
      color: #303133;
      margin: 0 0 6px;
    }

    .head-desc {
      font-size: 13px;
      color: #909399;
      margin: 0;
      max-width: 640px;
      line-height: 1.6;
    }
  }

  .form-card {
    margin-bottom: 20px;

    .card-title {
      font-size: 15px;
      font-weight: 600;
      color: #303133;
    }
  }

  .type-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;

    @media (max-width: 1200px) {
      grid-template-columns: repeat(3, 1fr);
    }
  }

  .type-card {
    position: relative;
    border: 1.5px solid #e4e7ed;
    border-radius: 10px;
    padding: 18px 14px 14px;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s ease;
    background: #fff;

    &:hover {
      border-color: #c0c4cc;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    }

    &.active {
      border-color: #409eff;
      background: #ecf5ff;
      box-shadow: 0 2px 10px rgba(64, 158, 255, 0.15);
    }

    .type-icon {
      margin-bottom: 8px;
      display: flex;
      justify-content: center;
    }

    .type-name {
      font-size: 14px;
      font-weight: 600;
      color: #303133;
      margin-bottom: 4px;
    }

    .type-desc {
      font-size: 12px;
      color: #909399;
      line-height: 1.4;
    }

    .type-check {
      position: absolute;
      top: 8px;
      right: 8px;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: #409eff;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
    }
  }

  .form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 4px 0 20px;
  }
}
</style>
