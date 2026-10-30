<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>渠道管理</span>
          <!-- 新建入口：直接进入分步配置向导（应用凭证 → 消息回调 → 连接验证） -->
          <el-button type="primary" @click="$router.push('/channels/new')">新建渠道</el-button>
        </div>
      </template>

      <!-- 筛选栏 -->
      <div class="filter-bar">
        <el-select v-model="filters.type" placeholder="渠道类型" clearable @change="loadData">
          <el-option label="企业微信" value="wechat_work" />
          <el-option label="微信公众号" value="wechat_official" />
          <el-option label="Telegram" value="telegram" />
        </el-select>
        <el-select v-model="filters.status" placeholder="状态" clearable @change="loadData">
          <el-option label="已连接" value="connected" />
          <el-option label="未连接" value="disconnected" />
        </el-select>
        <el-button @click="loadData">搜索</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe style="width: 100%">
        <el-table-column type="index" width="50" />
        <el-table-column label="渠道名称" prop="name" min-width="150" />
        <el-table-column label="类型" prop="type" width="130">
          <template #default="{ row }">
            <el-tag :type="getTypeTagType(row.type)">
              {{ getTypeLabel(row.type) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="AppID / CorpID" prop="app_id" width="180" show-overflow-tooltip />
        <el-table-column label="连接状态" prop="status" width="120">
          <template #default="{ row }">
            <el-tag :type="row.status === 'connected' ? 'success' : row.status === 'disconnected' ? 'warning' : 'info'" size="small">
              {{ getStatusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最后连接" width="180">
          <template #default="{ row }">{{ formatTime(row.last_connected_at) }}</template>
        </el-table-column>
        <el-table-column label="创建时间" prop="created_at" width="180">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="250" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleEdit(row)">配置</el-button>
            <el-button link type="success" @click="handleTest(row)" :loading="row._testing">测试</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :page-sizes="[10, 20, 50]"
          @size-change="loadData"
          @current-change="loadData"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { http, moduleApiPrefix } from '@multi-tenant-saas/console/shared/http'
import { formatDate } from '@multi-tenant-saas/console/shared/date'

const router = useRouter()
const loading = ref(false)
const tableData = ref<any[]>([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(20)

const filters = ref({
  type: '',
  status: '',
})

function getTypeLabel(type: string): string {
  const map: Record<string, string> = {
    wechat_work: '企业微信',
    wechat_official: '微信公众号',
    telegram: 'Telegram',
  }
  return map[type] || type
}

function getTypeTagType(type: string): string {
  const map: Record<string, string> = {
    wechat_work: 'success',
    wechat_official: 'warning',
    telegram: 'primary',
  }
  return map[type] || 'info'
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    connected: '已连接',
    disconnected: '未连接',
    error: '异常',
  }
  return map[status] || status
}

function formatTime(time: string | null): string {
  if (!time) return '—'
  return new Date(time).toLocaleString('zh-CN')
}

async function loadData() {
  loading.value = true
  try {
    const params: Record<string, any> = {
      per_page: pageSize.value,
      page: currentPage.value,
    }
    if (filters.value.type) params.type = filters.value.type
    if (filters.value.status) params.status = filters.value.status

    const res = await http.get(`${moduleApiPrefix('channel')}/channels`, { params }) as any
    const data = res?.data ?? res

    if (data?.data && Array.isArray(data.data)) {
      tableData.value = data.data.data ?? data.data
      total.value = data.total ?? data.data?.total ?? 0
    } else if (Array.isArray(data)) {
      tableData.value = data
      total.value = data.length
    } else {
      tableData.value = []
      total.value = 0
    }
  } catch (e: any) {
    ElMessage.error(e?.message || '加载渠道列表失败')
    tableData.value = []
  } finally {
    loading.value = false
  }
}

function handleEdit(row: any) {
  router.push(`/channels/${row.channel_id}`)
}

async function handleTest(row: any) {
  row._testing = true
  try {
    const res = await http.post(`${moduleApiPrefix('channel')}/channels/${row.channel_id}/test`) as any
    const data = res?.data ?? res
    if (data?.connected) {
      ElMessage.success(data.message || '连接成功')
    } else {
      ElMessage.error(data?.message || '连接失败')
    }
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.message || '测试连接失败')
  } finally {
    row._testing = false
  }
}

async function handleDelete(row: any) {
  await ElMessageBox.confirm(`确定删除渠道「${row.name}」？删除后框架侧回调凭证将一并清理。`)
  try {
    await http.delete(`${moduleApiPrefix('channel')}/channels/${row.channel_id}`)
    ElMessage.success('已删除')
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.message || '删除失败')
  }
}

onMounted(loadData)
</script>

<style scoped lang="scss">
.page-container {
  padding: 20px;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.filter-bar {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
  .el-select {
    width: 160px;
  }
}
.pagination-wrapper {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
</style>
