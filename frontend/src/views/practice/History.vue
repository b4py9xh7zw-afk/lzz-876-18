<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">练习历史</h1>
        <p class="mt-1 text-sm text-gray-500">所有练习仅用于弱项巩固与学习反馈，不计入正式成绩。</p>
      </div>
      <router-link to="/weakness" class="btn-secondary text-sm">返回弱项画像</router-link>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="!sessions.length" class="card-base p-12 text-center text-gray-400">
      还没有练习记录，去弱项画像开始一次巩固练习吧。
    </div>

    <div v-else class="space-y-3">
      <div v-for="s in sessions" :key="s.id"
           class="card-base p-4 flex items-center justify-between gap-4">
        <div class="min-w-0">
          <p class="font-medium text-gray-800 truncate">{{ s.title }}</p>
          <p class="text-xs text-gray-400 mt-1">
            {{ sourceLabel(s.source) }} · {{ s.question_count }} 题 ·
            {{ s.status === 'finished' ? new Date(s.finished_at || s.updated_at).toLocaleString() : '未完成' }}
          </p>
        </div>
        <div class="flex items-center gap-4 flex-shrink-0">
          <div v-if="s.status === 'finished'" class="text-right">
            <p class="font-bold text-indigo-600">{{ s.correct_count }}/{{ s.question_count }} 正确</p>
            <p class="text-xs text-gray-400">{{ s.score }} / {{ s.total_score }} 分</p>
          </div>
          <router-link v-if="s.status === 'finished'"
                       :to="{ path: '/practice', query: { session: s.id } }"
                       class="text-sm text-indigo-600 hover:underline">查看解析</router-link>
          <router-link v-else
                       :to="{ path: '/practice', query: { session: s.id } }"
                       class="text-sm text-amber-600 hover:underline">继续练习</router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const sessions = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const res = await api.get('/practice/history')
    sessions.value = res.data.sessions.data || []
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
})

const sourceLabel = (source) => ({
  recommendation: '智能推荐',
  wrong_questions: '错题练习',
  custom: '自主练习'
}[source] || source)
</script>
