<template>
  <div class="space-y-8">
    <!-- 顶部说明 -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900">知识点弱项画像</h1>
      <p class="mt-1 text-sm text-gray-500">基于你已完成考试的真实作答数据，定位薄弱知识点、题型与难度层级。</p>
      <div class="mt-3 flex items-start gap-2 rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-700">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span>本画像与推荐练习仅作为<strong>学习反馈</strong>，不会写入或改变任何正式考试成绩。</span>
      </div>
    </div>

    <div v-if="loading" class="text-center py-16">
      <div class="animate-spin rounded-full h-9 w-9 border-b-2 border-indigo-600 mx-auto"></div>
      <p class="mt-3 text-sm text-gray-400">正在分析你的作答数据…</p>
    </div>

    <template v-else-if="profile">
      <!-- 概览卡片 -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card-base p-5">
          <p class="text-xs text-gray-400 font-medium">已完成考试</p>
          <p class="mt-2 text-2xl font-bold text-gray-800">{{ overview.graded_records ?? 0 }}</p>
          <p class="mt-1 text-xs text-gray-400">平均 {{ overview.avg_exam_score ?? '—' }} 分</p>
        </div>
        <div class="card-base p-5">
          <p class="text-xs text-gray-400 font-medium">客观题正确率</p>
          <p class="mt-2 text-2xl font-bold text-indigo-600">{{ overview.accuracy ?? '—' }}<span v-if="overview.accuracy !== null" class="text-sm font-medium">%</span></p>
          <p class="mt-1 text-xs text-gray-400">{{ overview.total_correct ?? 0 }} / {{ overview.total_attempts ?? 0 }} 题</p>
        </div>
        <div class="card-base p-5">
          <p class="text-xs text-gray-400 font-medium">累计失分</p>
          <p class="mt-2 text-2xl font-bold text-red-500">{{ overview.lost_score ?? '0' }}</p>
          <p class="mt-1 text-xs text-gray-400">总分 {{ overview.total_score ?? '0' }} 分</p>
        </div>
        <div class="card-base p-5">
          <p class="text-xs text-gray-400 font-medium">待攻克薄弱点</p>
          <p class="mt-2 text-2xl font-bold text-amber-500">{{ overview.weak_category_count ?? 0 }}</p>
          <p class="mt-1 text-xs text-gray-400">未掌握错题 {{ overview.active_wrong_questions ?? 0 }} 道</p>
        </div>
      </div>

      <div v-if="(overview.total_attempts ?? 0) === 0" class="card-base p-10 text-center text-gray-400">
        <svg class="w-14 h-14 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <p>还没有已评分的考试作答数据。</p>
        <p class="text-sm mt-1">完成一场考试后，系统会自动生成你的弱项画像。</p>
        <router-link to="/exams" class="btn-primary mt-5">去参加考试</router-link>
      </div>

      <template v-else>
        <!-- 知识点掌握 -->
        <section>
          <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>知识点掌握情况
          </h2>
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div v-for="root in profile.categories" :key="root.category_id" class="card-base p-5">
              <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800">{{ root.name }}</h3>
                <span class="text-xs px-2 py-0.5 rounded-full" :class="proficiencyClass(root.proficiency)">
                  {{ root.attempts >= 2 ? root.proficiency_label : '数据不足' }}
                </span>
              </div>
              <div class="mb-3">
                <div class="flex justify-between text-xs text-gray-400 mb-1">
                  <span>正确率 {{ root.accuracy === null ? '—' : root.accuracy + '%' }}</span>
                  <span>失分 {{ root.lost_score }} 分</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                  <div class="h-full rounded-full transition-all"
                       :class="accuracyBarClass(root.accuracy)"
                       :style="{ width: (root.accuracy ?? 0) + '%' }"></div>
                </div>
              </div>
              <div v-if="root.children.length" class="space-y-2">
                <div v-for="child in root.children" :key="child.category_id"
                     class="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                     :class="child.is_weak ? 'border-red-200 bg-red-50/60' : 'border-gray-100'">
                  <span class="text-gray-700 flex items-center gap-2">
                    <span v-if="child.is_weak" class="w-2 h-2 rounded-full bg-red-400"></span>
                    {{ child.name }}
                  </span>
                  <span class="text-gray-400 text-xs">
                    {{ child.attempts ? (child.accuracy === null ? '—' : child.accuracy + '%') : '未作答' }}
                    <span v-if="child.attempts">（{{ child.attempts }} 题）</span>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- 题型失分 -->
          <section class="card-base p-5">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
              <span class="w-1.5 h-6 bg-sky-500 rounded-full mr-3"></span>按题型
            </h2>
            <div v-if="!profile.by_type.length" class="text-sm text-gray-400 py-6 text-center">暂无数据</div>
            <ul v-else class="space-y-3">
              <li v-for="item in profile.by_type" :key="item.type" class="text-sm">
                <div class="flex justify-between mb-1">
                  <span class="text-gray-700">{{ item.type_label }}</span>
                  <span :class="item.is_weak ? 'text-red-500 font-semibold' : 'text-gray-400'">
                    {{ item.accuracy === null ? '—' : item.accuracy + '%' }} · 失分 {{ item.lost_score }}
                  </span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                  <div class="h-full" :class="accuracyBarClass(item.accuracy)" :style="{ width: (item.accuracy ?? 0) + '%' }"></div>
                </div>
              </li>
            </ul>
          </section>

          <!-- 难度失分 -->
          <section class="card-base p-5">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
              <span class="w-1.5 h-6 bg-amber-500 rounded-full mr-3"></span>按难度层级
            </h2>
            <div v-if="!profile.by_difficulty.length" class="text-sm text-gray-400 py-6 text-center">暂无数据</div>
            <ul v-else class="space-y-3">
              <li v-for="item in profile.by_difficulty" :key="item.difficulty" class="text-sm">
                <div class="flex justify-between mb-1">
                  <span class="text-gray-700">
                    <span class="inline-block w-2 h-2 rounded-full mr-2 align-middle"
                          :class="['bg-green-400', 'bg-amber-400', 'bg-red-400'][item.difficulty - 1]"></span>
                    {{ item.difficulty_label }}
                  </span>
                  <span :class="item.is_weak ? 'text-red-500 font-semibold' : 'text-gray-400'">
                    {{ item.accuracy === null ? '—' : item.accuracy + '%' }} · 失分 {{ item.lost_score }}
                  </span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                  <div class="h-full" :class="accuracyBarClass(item.accuracy)" :style="{ width: (item.accuracy ?? 0) + '%' }"></div>
                </div>
              </li>
            </ul>
          </section>
        </div>

        <!-- 推荐练习 -->
        <section>
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-gray-800 flex items-center">
              <span class="w-1.5 h-6 bg-emerald-500 rounded-full mr-3"></span>针对性练习推荐
            </h2>
            <button class="btn-primary text-sm" :disabled="starting" @click="startRecommended">
              {{ starting ? '生成中…' : '开始弱项巩固练习' }}
            </button>
          </div>
          <p class="text-xs text-gray-400 mb-3">{{ recommendations.rule }}</p>

          <div v-if="loadingRecs" class="text-sm text-gray-400 py-6 text-center">正在根据你的错题生成推荐…</div>
          <div v-else-if="!recommendations.items?.length" class="card-base p-8 text-center text-sm text-gray-400">
            当前没有待攻克的错题，暂时无需推荐练习。
          </div>
          <div v-else class="space-y-3">
            <div v-for="item in recommendations.items" :key="item.source + '-' + item.question_id"
                 class="card-base p-4 flex items-start gap-4">
              <span class="flex-shrink-0 text-xs font-semibold px-2 py-1 rounded-full"
                    :class="item.source === 'wrong_question' ? 'bg-red-100 text-red-600' : 'bg-indigo-100 text-indigo-600'">
                {{ item.source_label }}
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 line-clamp-2">{{ item.title }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ item.reason }}</p>
                <p class="mt-1 text-xs text-gray-400">
                  {{ item.category_name }} · {{ item.type_label }} · {{ item.difficulty_label }}
                </p>
              </div>
            </div>
          </div>

          <div class="mt-4 flex justify-end">
            <router-link to="/practice/history" class="text-sm text-indigo-600 hover:underline">查看练习历史 →</router-link>
          </div>
        </section>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../api'
import { useToast } from '../../composables/useToast'

const router = useRouter()
const { success: toastSuccess, error: toastError } = useToast()

const profile = ref(null)
const loading = ref(true)
const recommendations = ref({ items: [] })
const loadingRecs = ref(true)
const starting = ref(false)

const overview = computed(() => profile.value?.overview || {})

onMounted(async () => {
  try {
    const [profileRes, recRes] = await Promise.all([
      api.get('/weakness/my'),
      api.get('/weakness/my/recommendations', { params: { limit: 10 } })
    ])
    profile.value = profileRes.data.profile
    recommendations.value = recRes.data
  } catch (e) {
    console.error('加载画像失败', e)
    toastError('加载弱项画像失败')
  } finally {
    loading.value = false
    loadingRecs.value = false
  }
})

const proficiencyClass = (level) => ({
  severe: 'bg-red-100 text-red-600',
  weak: 'bg-amber-100 text-amber-700',
  solid: 'bg-emerald-100 text-emerald-700',
  no_data: 'bg-gray-100 text-gray-500'
}[level] || 'bg-gray-100 text-gray-500')

const accuracyBarClass = (accuracy) => {
  if (accuracy === null || accuracy === undefined) return 'bg-gray-200'
  if (accuracy >= 80) return 'bg-emerald-500'
  if (accuracy >= 50) return 'bg-amber-400'
  return 'bg-red-400'
}

const startRecommended = async () => {
  if (starting.value) return
  starting.value = true
  try {
    const res = await api.post('/practice/start/recommended')
    if (!res.data.session) {
      toastError(res.data.message || '暂无可练习的题目')
      return
    }
    toastSuccess('练习已生成')
    router.push({ path: '/practice', query: { session: res.data.session.id } })
  } catch (e) {
    toastError(e.response?.data?.message || '生成练习失败')
  } finally {
    starting.value = false
  }
}
</script>
