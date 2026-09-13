<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">班级管理</h1>
        <p class="mt-1 text-sm text-gray-500">建立班级并分配学生，用于查看班级共同薄弱知识点。</p>
      </div>
      <button class="btn-primary text-sm" @click="openCreate">新建班级</button>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="!classes.length" class="card-base p-12 text-center text-gray-400">
      还没有班级，点击右上角「新建班级」开始。
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
      <div v-for="c in classes" :key="c.id" class="card-base p-5 flex flex-col">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="font-bold text-gray-800">{{ c.name }}</h3>
            <p class="text-xs text-gray-400 mt-1 line-clamp-2 min-h-[2rem]">{{ c.description || '暂无描述' }}</p>
          </div>
        </div>
        <p class="text-sm text-gray-500 mt-3">{{ c.students_count }} 名学生</p>
        <div class="mt-4 flex gap-2">
          <router-link :to="`/classes/${c.id}`"
                       class="flex-1 text-center text-sm bg-indigo-50 text-indigo-600 rounded-lg py-2 hover:bg-indigo-100">
            薄弱点画像
          </router-link>
          <button class="text-sm text-gray-500 hover:text-indigo-600 px-3 py-2" @click="openEdit(c)">编辑</button>
          <button class="text-sm text-gray-500 hover:text-red-600 px-3 py-2" @click="remove(c)">解散</button>
        </div>
      </div>
    </div>

    <!-- 新建/编辑弹窗 -->
    <Teleport to="body">
      <div v-if="showForm" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-600/60 backdrop-blur-sm" @click="showForm = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
          <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? '编辑班级' : '新建班级' }}</h3>
          <div class="space-y-4">
            <div>
              <label class="block text-sm text-gray-600 mb-1">班级名称</label>
              <input v-model="form.name" class="input-base" placeholder="如：计科2301班">
            </div>
            <div>
              <label class="block text-sm text-gray-600 mb-1">班级描述</label>
              <textarea v-model="form.description" rows="3" class="input-base" placeholder="可选"></textarea>
            </div>
          </div>
          <div class="mt-6 flex justify-end gap-3">
            <button class="btn-secondary text-sm" @click="showForm = false">取消</button>
            <button class="btn-primary text-sm" :disabled="saving" @click="save">{{ saving ? '保存中…' : '保存' }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import { useToast } from '../../composables/useToast'
import { useModal } from '../../composables/useModal'

const { success: toastSuccess, error: toastError } = useToast()
const { confirm } = useModal()

const classes = ref([])
const loading = ref(true)
const showForm = ref(false)
const editing = ref(null)
const saving = ref(false)
const form = ref({ name: '', description: '' })

onMounted(load)

async function load() {
  loading.value = true
  try {
    const res = await api.get('/classes')
    classes.value = res.data.classes
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = { name: '', description: '' }
  showForm.value = true
}

function openEdit(c) {
  editing.value = c
  form.value = { name: c.name, description: c.description || '' }
  showForm.value = true
}

async function save() {
  if (!form.value.name.trim()) {
    toastError('请填写班级名称')
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      await api.put(`/classes/${editing.value.id}`, form.value)
      toastSuccess('已更新')
    } else {
      await api.post('/classes', form.value)
      toastSuccess('已创建')
    }
    showForm.value = false
    load()
  } catch (e) {
    toastError(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

async function remove(c) {
  const ok = await confirm(`确定解散「${c.name}」吗？学生将回到未分班状态，不会删除账号或成绩。`, '解散班级')
  if (!ok) return
  try {
    await api.delete(`/classes/${c.id}`)
    toastSuccess('班级已解散')
    load()
  } catch (e) {
    toastError(e.response?.data?.message || '操作失败')
  }
}
</script>
