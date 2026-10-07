<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>活码管理</span>
          <el-button type="primary" @click="handleCreate"> 新建 </el-button>
        </div>
      </template>
      <el-table v-loading="loading" :data="tableData" stripe style="width: 100%">
        <el-table-column type="index" width="50" />
        <el-table-column label="名称" prop="name" />
        <el-table-column label="状态" prop="status" width="120">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'">
              {{ row.status === 'active' ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" prop="createdAt" width="180" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleEdit(row)"> 编辑 </el-button>
            <el-button link type="danger" @click="handleDelete(row)"> 删除 </el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrapper">
        <el-pagination layout="total, prev, pager, next" :total="total" :page-size="20" />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { http, extractListResult } from '@scrm/shared'

interface TableItem {
  id: number
  name: string
  channel?: string
  scan_count?: number
  status: string
  createdAt: string
}

const router = useRouter()
const loading = ref(false)
const tableData = ref<TableItem[]>([])
const total = ref(0)

async function loadData() {
  loading.value = true
  try {
    const res = await http.get('/biz/live-codes')
    const { data, total: t } = extractListResult<any>(res)
    // 后端契约：live_code_id/channel(名称)/created_at → 前端展示字段
    tableData.value = data.map((c: any) => ({
      id: c.live_code_id,
      name: c.channel ?? c.code ?? '',
      status: c.status,
      createdAt: c.created_at,
    }))
    total.value = t
  } catch {
    ElMessage.error('加载活码列表失败')
  } finally {
    loading.value = false
  }
}
function handleCreate() {
  router.push('/live-codes/new')
}
function handleEdit(row: TableItem) {
  router.push(`/live-codes/${row.id}`)
}
async function handleDelete(row: TableItem) {
  await ElMessageBox.confirm(`确定删除活码「${row.name}」？`)
  await http.delete(`/biz/live-codes/${row.id}`)
  ElMessage.success('已删除')
  loadData()
}
onMounted(loadData)
</script>

<style scoped lang="scss">
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.pagination-wrapper {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
</style>
