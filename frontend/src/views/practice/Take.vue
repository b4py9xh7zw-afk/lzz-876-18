<template>
  <div class="space-y-6">
    <div class="flex items-start justify-between">
      <div>
        <router-link to="/weakness" class="text-sm text-gray-400 hover:text-indigo-600">← 返回弱项画像</router-link>
        <h1 class="text-2xl font-bold text-gray-900 mt-1">{{ session?.title || '弱项巩固练习' }}</h1>
      </div>
      <span class="flex-shrink-0 text-xs bg-emerald-50 text-emerald-600 border border-emerald-100 rounded-full px-3 py-1.5">
        练习模式 · 不计入正式成绩
      </span>
    </div>

    <div v-if="loading" class="text-center py-16">
      <div class="animate-spin rounded-full h-9 w-9 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <!-- 作答阶段 -->
    <template v-else-if="!result && questions.length">
      <div class="space-y-6">
        <div v-for="(question, index) in questions" :key="question.id" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
          <div class="flex items-start mb-4">
            <span class="bg-indigo-100 text-indigo-800 text-sm font-medium px-2.5 py-0.5 rounded mr-3">{{ index + 1 }}</span>
            <div class="flex-1">
              <h3 class="text-lg font-medium text-gray-900 mb-2">{{ question.title }}</h3>
              <p class="text-sm text-gray-400 mb-3">
                {{ questionTypeLabel(question.type) }} · {{ difficultyLabel(question.difficulty) }} · {{ question.score }} 分
                <span v-if="question.recommend" class="ml-2 text-indigo-500">（{{ question.recommend.source_label }}）</span>
              </p>
              <div class="space-y-2">
                <template v-if="question.type === 'single_choice' || question.type === 'true_false'">
                  <template v-if="question.type === 'true_false'">
                    <label v-for="opt in [{k:'true',t:'正确'},{k:'false',t:'错误'}]" :key="opt.k"
                           class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
                           :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === opt.k}">
                      <input type="radio" :name="'q_' + question.id" :value="opt.k" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600">
                      <span class="ml-3">{{ opt.t }}</span>
                    </label>
                  </template>
                  <template v-else>
                    <label v-for="(label, key) in question.options" :key="key"
                           class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
                           :class="{'border-indigo-500 bg-indigo-50': answers[question.id] === key}">
                      <input type="radio" :name="'q_' + question.id" :value="key" v-model="answers[question.id]" class="h-4 w-4 text-indigo-600">
                      <span class="ml-3">{{ key }}. {{ label }}</span>
                    </label>
                  </template>
                </template>
                <template v-else-if="question.type === 'multiple_choice'">
                  <label v-for="(label, key) in question.options" :key="key"
                         class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
                         :class="{'border-indigo-500 bg-indigo-50': (answers[question.id] || []).includes(key)}">
                    <input type="checkbox" :value="key" @change="toggleMulti(question.id, key)"
                           :checked="(answers[question.id] || []).includes(key)" class="h-4 w-4 text-indigo-600">
                    <span class="ml-3">{{ key }}. {{ label }}</span>
                  </label>
                </template>
                <textarea v-else v-model="answers[question.id]" rows="3"
                          class="input-base" placeholder="请输入答案"></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="flex justify-between items-center">
          <router-link to="/weakness" class="btn-secondary">退出练习</router-link>
          <button @click="submit" :disabled="submitting" class="btn-primary">
            {{ submitting ? '判分中…' : '提交并查看解析' }}
          </button>
        </div>
      </div>
    </template>

    <!-- 结果反馈 -->
    <template v-else-if="result">
      <div class="card-base p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h2 class="text-xl font-bold text-gray-800">练习完成</h2>
          <p class="text-sm text-gray-400 mt-1">答对 {{ result.correct_count }} / {{ result.question_count }} 题</p>
        </div>
        <div class="flex items-center gap-6">
          <div class="text-center">
            <p class="text-3xl font-bold text-indigo-600">{{ result.score }}</p>
            <p class="text-xs text-gray-400 mt-1">练习得分（满分 {{ result.total_score }}）</p>
          </div>
          <div class="flex flex-col gap-2">
            <router-link to="/weakness" class="btn-primary text-sm">返回画像</router-link>
            <router-link to="/practice/history" class="btn-secondary text-sm">练习历史</router-link>
          </div>
        </div>
      </div>
      <div class="rounded-lg bg-emerald-50 border border-emerald-100 px-4 py-3 text-sm text-emerald-700">
        本次结果仅用于学习反馈与错题攻克记录，<strong>不会写入或影响正式成绩</strong>。
      </div>
      <div class="space-y-4">
        <div v-for="(detail, idx) in result.details" :key="detail.question_id"
             class="card-base p-5" :class="detail.is_correct ? 'border-l-4 border-l-emerald-400' : 'border-l-4 border-l-red-400'">
          <div class="flex items-center gap-2 mb-2">
            <span class="text-sm font-semibold" :class="detail.is_correct ? 'text-emerald-600' : 'text-red-500'">
              {{ detail.is_correct ? '✓ 回答正确' : '✗ 回答错误' }}
            </span>
            <span class="text-xs text-gray-400">第 {{ idx + 1 }} 题 · {{ detail.score }}/{{ detail.max_score }} 分</span>
          </div>
          <p class="font-medium text-gray-800">{{ questionTitle(detail.question_id) }}</p>
          <p class="mt-2 text-sm text-gray-600">正确答案：<span class="font-semibold text-emerald-600">{{ detail.correct_answer }}</span></p>
          <p v-if="detail.analysis" class="mt-1 text-sm text-gray-400">解析：{{ detail.analysis }}</p>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const { error: toastError } = useToast()

const session = ref(null)
const questions = ref([])
const answers = ref({})
const result = ref(null)
const loading = ref(true)
const submitting = ref(false)

onMounted(async () => {
  const sessionId = route.query.session
  if (!sessionId) {
    toastError('缺少练习会话，请从弱项画像进入练习')
    loading.value = false
    return
  }
  try {
    const res = await api.get(`/practice/${sessionId}`)
    const s = res.data.session
    session.value = { id: s.id, title: s.title }
    if (s.status === 'finished') {
      // 已完成的会话：直接展示历史详情
      result.value = buildResultFromSession(s)
      questions.value = (s.answers || []).map(a => ({ id: a.question_id, title: a.question?.title }))
    } else {
      // 进行中的会话：show 接口返回不含正确答案的题面
      questions.value = (res.data.questions || [])
      session.value = { id: s.id, title: s.title }
    }
  } catch (e) {
    toastError(e.response?.data?.message || '加载练习失败')
  } finally {
    loading.value = false
  }
})

const buildResultFromSession = (s) => ({
  question_count: s.question_count,
  correct_count: s.correct_count,
  score: s.score,
  total_score: s.total_score,
  details: (s.answers || []).map(a => ({
    question_id: a.question_id,
    is_correct: !!a.is_correct,
    correct_answer: a.question?.answer,
    analysis: a.question?.analysis,
    score: a.score,
    max_score: a.question?.score
  }))
})

const questionTitle = (id) => questions.value.find(q => q.id === id)?.title || `题目 #${id}`

const questionTypeLabel = (type) => ({
  single_choice: '单选题', multiple_choice: '多选题', true_false: '判断题',
  fill_blank: '填空题', essay: '问答题'
}[type] || type)

const difficultyLabel = (d) => ({ 1: '简单', 2: '中等', 3: '困难' }[d] || d)

const toggleMulti = (qid, key) => {
  if (!answers.value[qid]) answers.value[qid] = []
  const idx = answers.value[qid].indexOf(key)
  if (idx === -1) answers.value[qid].push(key)
  else answers.value[qid].splice(idx, 1)
}

const submit = async () => {
  if (submitting.value) return
  submitting.value = true
  try {
    const payload = questions.value.map(q => ({
      question_id: q.id,
      answer: Array.isArray(answers.value[q.id])
        ? answers.value[q.id].join(',')
        : (answers.value[q.id] ?? '')
    }))
    const res = await api.post(`/practice/${session.value.id}/submit`, { answers: payload })
    result.value = res.data.result
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    toastError(e.response?.data?.message || '提交失败')
  } finally {
    submitting.value = false
  }
}
</script>
