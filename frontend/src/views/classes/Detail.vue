<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <router-link to="/classes" class="text-sm text-gray-400 hover:text-indigo-600">← 返回班级列表</router-link>
        <h1 class="text-2xl font-bold text-gray-900 mt-1">{{ profile?.class?.name || '班级画像' }}</h1>
        <p class="mt-1 text-sm text-gray-500">聚合全班已评分考试的作答数据，定位共同薄弱知识点。</p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary text-sm" :class="tab === 'profile' ? '!bg-indigo-50 !text-indigo-600' : ''" @click="tab = 'profile'">薄弱点画像</button>
        <button class="btn-secondary text-sm" :class="tab === 'students' ? '!bg-indigo-50 !text-indigo-600' : ''" @click="tab = 'students'; loadStudents()">学生管理</button>
      </div>
    </div>

    <div class="rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-700">
      画像结果仅作为教学反馈使用，展示的是聚合数据，<strong>不会改变任何学生的正式成绩</strong>。
    </div>

    <div v-if="loading" class="text-center py-16">
      <div class="animate-spin rounded-full h-9 w-9 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <!-- 画像 Tab -->
    <template v-else-if="tab === 'profile' && profile">
      <!-- 概览 -->
      <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="card-base p-4">
          <p class="text-xs text-gray-400">班级人数</p>
          <p class="mt-1 text-xl font-bold text-gray-800">{{ profile.class.student_count }}</p>
        </div>
        <div class="card-base p-4">
          <p class="text-xs text-gray-400">有作答学生</p>
          <p class="mt-1 text-xl font-bold text-gray-800">{{ profile.overview.active_students ?? 0 }}</p>
        </div>
        <div class="card-base p-4">
          <p class="text-xs text-gray-400">客观题正确率</p>
          <p class="mt-1 text-xl font-bold text-indigo-600">{{ profile.overview.accuracy ?? '—' }}<span v-if="profile.overview.accuracy !== null">%</span></p>
        </div>
        <div class="card-base p-4">
          <p class="text-xs text-gray-400">平均考试得分</p>
          <p class="mt-1 text-xl font-bold text-gray-800">{{ profile.overview.avg_exam_score ?? '—' }}</p>
        </div>
        <div class="card-base p-4">
          <p class="text-xs text-gray-400">累计失分</p>
          <p class="mt-1 text-xl font-bold text-red-500">{{ profile.overview.lost_score ?? 0 }}</p>
        </div>
      </div>

      <!-- 共同薄弱知识点 -->
      <section>
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
          <span class="w-1.5 h-6 bg-red-500 rounded-full mr-3"></span>共同薄弱知识点
          <span class="ml-2 text-xs font-normal text-gray-400">（至少 2 名学生正确率偏低且占比 ≥ 50%）</span>
        </h2>
        <div v-if="!profile.common_weak_categories.length" class="card-base p-8 text-center text-sm text-gray-400">
          暂未识别出共同薄弱点：可能是班级人数/作答数据不足，或整体掌握情况良好。
        </div>
        <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div v-for="w in profile.common_weak_categories" :key="w.category_id" class="card-base p-5 border-l-4 border-l-red-400">
            <div class="flex items-center justify-between">
              <h3 class="font-bold text-gray-800">
                {{ w.parent_name ? w.parent_name + ' / ' : '' }}{{ w.name }}
              </h3>
              <span class="text-xs bg-red-50 text-red-500 px-2 py-1 rounded-full">{{ w.weak_student_count }} 人薄弱</span>
            </div>
            <div class="mt-3 flex items-center gap-4 text-xs text-gray-400">
              <span>薄弱占比 {{ w.weak_ratio }}%</span>
              <span>知识点平均正确率 {{ w.avg_accuracy === null ? '—' : w.avg_accuracy + '%' }}</span>
              <span>{{ w.attempted_student_count }} 人作答</span>
            </div>
            <div class="mt-3 flex flex-wrap gap-1.5">
              <span v-for="s in w.weak_students" :key="s.student.id"
                    class="text-xs bg-gray-100 text-gray-600 rounded-full px-2.5 py-1">
                {{ s.student.real_name || s.student.username }}
                <span v-if="s.accuracy !== null" class="text-red-400">{{ s.accuracy }}%</span>
              </span>
            </div>
          </div>
        </div>
      </section>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 题型 / 难度 -->
        <section class="card-base p-5">
          <h2 class="font-bold text-gray-800 mb-3">按题型正确率</h2>
          <ul class="space-y-3">
            <li v-for="t in profile.by_type" :key="t.type" class="text-sm">
              <div class="flex justify-between mb-1">
                <span class="text-gray-700">{{ t.type_label }}</span>
                <span :class="t.is_weak ? 'text-red-500 font-semibold' : 'text-gray-400'">
                  {{ t.accuracy === null ? '—' : t.accuracy + '%' }} · 失分 {{ t.lost_score }}
                </span>
              </div>
              <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full" :class="barClass(t.accuracy)" :style="{ width: (t.accuracy ?? 0) + '%' }"></div>
              </div>
            </li>
            <li v-if="!profile.by_type.length" class="text-gray-400 text-sm text-center py-4">暂无数据</li>
          </ul>
        </section>
        <section class="card-base p-5">
          <h2 class="font-bold text-gray-800 mb-3">按难度正确率</h2>
          <ul class="space-y-3">
            <li v-for="d in profile.by_difficulty" :key="d.difficulty" class="text-sm">
              <div class="flex justify-between mb-1">
                <span class="text-gray-700">{{ d.difficulty_label }}</span>
                <span :class="d.is_weak ? 'text-red-500 font-semibold' : 'text-gray-400'">
                  {{ d.accuracy === null ? '—' : d.accuracy + '%' }} · 失分 {{ d.lost_score }}
                </span>
              </div>
              <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full" :class="barClass(d.accuracy)" :style="{ width: (d.accuracy ?? 0) + '%' }"></div>
              </div>
            </li>
            <li v-if="!profile.by_difficulty.length" class="text-gray-400 text-sm text-center py-4">暂无数据</li>
          </ul>
        </section>
      </div>

      <!-- 学生明细 -->
      <section>
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
          <span class="w-1.5 h-6 bg-indigo-500 rounded-full mr-3"></span>学生掌握概览
        </h2>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-5 py-3 text-left text-xs text-gray-500 uppercase">学生</th>
                <th class="px-5 py-3 text-left text-xs text-gray-500 uppercase">作答数</th>
                <th class="px-5 py-3 text-left text-xs text-gray-500 uppercase">正确率</th>
                <th class="px-5 py-3 text-left text-xs text-gray-500 uppercase">薄弱知识点数</th>
                <th class="px-5 py-3 text-left text-xs text-gray-500 uppercase">平均考试得分</th>
                <th class="px-5 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="s in profile.students" :key="s.student.id">
                <td class="px-5 py-3 text-sm text-gray-800">{{ s.student.real_name || s.student.username }}</td>
                <td class="px-5 py-3 text-sm text-gray-500">{{ s.attempts }}</td>
                <td class="px-5 py-3 text-sm">
                  <span v-if="s.accuracy === null" class="text-gray-300">无数据</span>
                  <span v-else :class="s.accuracy < 50 ? 'text-red-500 font-semibold' : 'text-gray-700'">{{ s.accuracy }}%</span>
                </td>
                <td class="px-5 py-3 text-sm">
                  <span v-if="s.weak_category_count > 0" class="text-amber-600 font-medium">{{ s.weak_category_count }}</span>
                  <span v-else class="text-gray-300">0</span>
                </td>
                <td class="px-5 py-3 text-sm text-gray-700">{{ s.avg_exam_score ?? '—' }}</td>
                <td class="px-5 py-3 text-right">
                  <button class="text-sm text-indigo-600 hover:underline" @click="viewStudent(s.student)">个人画像</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <!-- 学生管理 Tab -->
    <template v-else-if="tab === 'students'">
      <div class="card-base p-5">
        <h2 class="font-bold text-gray-800 mb-3">本班学生（{{ roster.students.length }}）</h2>
        <div v-if="!roster.students.length" class="text-sm text-gray-400 py-6 text-center">暂无学生，请从下方未分班学生中添加。</div>
        <div v-else class="flex flex-wrap gap-2">
          <div v-for="stu in roster.students" :key="stu.id"
               class="flex items-center gap-2 bg-gray-50 rounded-full pl-3 pr-2 py-1.5 text-sm">
            <span class="text-gray-700">{{ stu.real_name || stu.username }}</span>
            <button class="w-5 h-5 rounded-full bg-white text-gray-400 hover:text-red-500 flex items-center justify-center text-xs"
                    @click="removeStudent(stu)">×</button>
          </div>
        </div>
      </div>
      <div class="card-base p-5">
        <h2 class="font-bold text-gray-800 mb-3">未分班学生</h2>
        <div v-if="!roster.unassigned_students.length" class="text-sm text-gray-400 py-4 text-center">没有可添加的学生。</div>
        <div v-else class="flex flex-wrap gap-2">
          <button v-for="stu in roster.unassigned_students" :key="stu.id"
                  class="text-sm bg-indigo-50 text-indigo-600 rounded-full px-3 py-1.5 hover:bg-indigo-100"
                  @click="addStudent(stu)">
            + {{ stu.real_name || stu.username }}
          </button>
        </div>
      </div>
    </template>

    <!-- 学生个人画像弹层 -->
    <Teleport to="body">
      <div v-if="studentProfile" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-600/60 backdrop-blur-sm" @click="studentProfile = null"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] overflow-y-auto p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900">{{ studentProfile._name }} 的个人画像</h3>
            <button class="text-gray-400 hover:text-gray-600" @click="studentProfile = null">✕</button>
          </div>
          <div class="text-xs text-gray-400 mb-4">仅教师可见的学习反馈，不对该生成绩产生任何影响。</div>

          <div class="grid grid-cols-3 gap-3 mb-5">
            <div class="bg-gray-50 rounded-lg p-3 text-center">
              <p class="text-lg font-bold text-indigo-600">{{ studentProfile.overview.accuracy ?? '—' }}{{ studentProfile.overview.accuracy !== null ? '%' : '' }}</p>
              <p class="text-xs text-gray-400 mt-1">客观题正确率</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
              <p class="text-lg font-bold text-red-500">{{ studentProfile.overview.lost_score ?? 0 }}</p>
              <p class="text-xs text-gray-400 mt-1">累计失分</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
              <p class="text-lg font-bold text-amber-500">{{ studentProfile.overview.active_wrong_questions ?? 0 }}</p>
              <p class="text-xs text-gray-400 mt-1">未掌握错题</p>
            </div>
          </div>

          <h4 class="font-semibold text-gray-800 text-sm mb-2">薄弱知识点</h4>
          <div v-if="!studentProfile.weak_categories.length" class="text-sm text-gray-400 mb-4">暂无明显薄弱点。</div>
          <ul v-else class="space-y-2 mb-4">
            <li v-for="w in studentProfile.weak_categories" :key="w.category_id"
                class="flex items-center justify-between bg-red-50/60 rounded-lg px-3 py-2 text-sm">
              <span class="text-gray-700">{{ w.name }}</span>
              <span class="text-red-500">{{ w.accuracy === null ? '—' : w.accuracy + '%' }}（{{ w.attempts }} 题）</span>
            </li>
          </ul>

          <h4 class="font-semibold text-gray-800 text-sm mb-2">真实错题</h4>
          <div v-if="!studentProfile.wrong_questions.length" class="text-sm text-gray-400">没有未攻克的错题。</div>
          <ul v-else class="space-y-2">
            <li v-for="wq in studentProfile.wrong_questions.slice(0, 5)" :key="wq.question_id"
                class="bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-600">
              <p class="line-clamp-1">{{ wq.title }}</p>
              <p class="text-xs text-gray-400 mt-0.5">{{ wq.category_name }} · 答错 {{ wq.wrong_count }} 次 · {{ wq.last_wrong_at }}</p>
            </li>
          </ul>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const classId = route.params.id
const { success: toastSuccess, error: toastError } = useToast()

const profile = ref(null)
const loading = ref(true)
const tab = ref('profile')
const roster = ref({ students: [], unassigned_students: [] })
const studentProfile = ref(null)

onMounted(loadProfile)

async function loadProfile() {
  loading.value = true
  try {
    const res = await api.get(`/weakness/classes/${classId}`)
    profile.value = res.data.profile
  } catch (e) {
    toastError(e.response?.data?.message || '加载班级画像失败')
  } finally {
    loading.value = false
  }
}

async function loadStudents() {
  try {
    const res = await api.get(`/classes/${classId}/students`)
    roster.value = res.data
  } catch (e) {
    toastError('加载学生列表失败')
  }
}

async function addStudent(stu) {
  try {
    await api.post(`/classes/${classId}/students`, { student_ids: [stu.id] })
    toastSuccess(`${stu.real_name || stu.username} 已加入班级`)
    loadStudents()
    loadProfile()
  } catch (e) {
    toastError('添加失败')
  }
}

async function removeStudent(stu) {
  try {
    await api.delete(`/classes/${classId}/students/${stu.id}`)
    toastSuccess('已移出班级')
    loadStudents()
    loadProfile()
  } catch (e) {
    toastError('操作失败')
  }
}

async function viewStudent(student) {
  try {
    const res = await api.get(`/weakness/students/${student.id}`)
    studentProfile.value = { ...res.data.profile, _name: student.real_name || student.username }
  } catch (e) {
    toastError(e.response?.data?.message || '无法查看该学生画像')
  }
}

const barClass = (accuracy) => {
  if (accuracy === null || accuracy === undefined) return 'bg-gray-200'
  if (accuracy >= 80) return 'bg-emerald-500'
  if (accuracy >= 50) return 'bg-amber-400'
  return 'bg-red-400'
}
</script>
