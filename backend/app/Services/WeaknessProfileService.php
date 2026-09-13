<?php

namespace App\Services;

use App\Models\ClassRoom;
use App\Models\ExamRecord;
use App\Models\PracticeAnswer;
use App\Models\PracticeSession;
use App\Models\Question;
use App\Models\QuestionCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 知识点弱项画像服务。
 *
 * 数据口径：
 * - 只统计已完成自动评分（graded）的正式考试记录，练习数据不写入正式成绩；
 * - 每道作答按其所在试卷的该题分值计算失分；
 * - 画像结果只作为学习反馈，不影响考试分数。
 */
class WeaknessProfileService
{
    /** 薄弱判定阈值：正确率低于该值且作答达到一定次数视为薄弱 */
    public const WEAK_ACCURACY = 0.75;
    /** 严重薄弱阈值 */
    public const SEVERE_ACCURACY = 0.5;
    /** 纳入画像判定所需的最少作答次数 */
    public const MIN_ATTEMPTS = 2;

    public const LEVEL_SEVERE = 'severe';
    public const LEVEL_WEAK = 'weak';
    public const LEVEL_SOLID = 'solid';
    public const LEVEL_NO_DATA = 'no_data';

    /**
     * 学生个人画像
     */
    public function studentProfile(int $userId): array
    {
        $rows = $this->answerRows([$userId]);
        $categories = QuestionCategory::orderBy('sort_order')->get();

        $wrongQuestions = $this->activeWrongQuestions($userId, $rows);
        $categoryTree = $this->categoryTree($categories, $rows);
        $byType = $this->aggregateByType($rows);
        $byDifficulty = $this->aggregateByDifficulty($rows);
        $overview = $this->overview($userId, $rows, $wrongQuestions, $categoryTree);

        return [
            'scope' => 'student',
            'overview' => $overview,
            'categories' => $categoryTree,
            'weak_categories' => $this->flattenWeakCategories($categoryTree),
            'by_type' => $byType,
            'by_difficulty' => $byDifficulty,
            'wrong_questions' => $wrongQuestions,
            'notice' => '画像数据来源于已完成的考试错题，仅用于学习反馈，不影响正式成绩。',
        ];
    }

    /**
     * 班级共同薄弱点画像（教师视角）
     */
    public function classProfile(ClassRoom $classRoom): array
    {
        $students = $classRoom->students()->where('status', 1)->get();
        $studentIds = $students->pluck('id')->all();

        $rows = $this->answerRows($studentIds);
        $categories = QuestionCategory::orderBy('sort_order')->get();

        // 按学生 + 知识点拆分，识别每位学生的薄弱分类
        $perStudentCategory = [];
        $studentOverview = [];
        foreach ($students as $student) {
            $studentRows = $rows->where('user_id', $student->id)->values();
            $rawLeafStats = $this->leafStats($categories, $studentRows);

            $statsByCategory = [];
            foreach ($rawLeafStats as $cid => $raw) {
                $statsByCategory[$cid] = $this->summarize(
                    $studentRows->where('category_id', $cid)->values()
                );
            }

            $weakLeafIds = collect($statsByCategory)
                ->filter(fn ($s) => $s['attempts'] >= self::MIN_ATTEMPTS
                    && $s['accuracy'] !== null
                    && $s['accuracy'] < self::WEAK_ACCURACY * 100)
                ->keys()
                ->all();

            foreach ($statsByCategory as $cid => $stats) {
                $perStudentCategory[$cid][] = [
                    'student' => $student,
                    'stats' => $stats,
                    'is_weak' => in_array($cid, $weakLeafIds, true),
                ];
            }

            $studentOverview[] = [
                'student' => $this->studentPayload($student),
                'attempts' => $studentRows->count(),
                'correct' => (int) $studentRows->sum('is_correct'),
                'accuracy' => $studentRows->count() > 0
                    ? round($studentRows->sum('is_correct') / $studentRows->count() * 100, 1)
                    : null,
                'weak_category_count' => count($weakLeafIds),
                'avg_exam_score' => $this->avgExamScore([$student->id]),
            ];
        }

        // 共同薄弱知识点：在有足够作答的学生中，薄弱学生占比 >= 50% 且至少 2 人
        $commonWeak = [];
        foreach ($perStudentCategory as $cid => $entries) {
            $attempted = array_filter($entries, fn ($e) => $e['stats']['attempts'] > 0);
            $qualified = array_filter($entries, fn ($e) => $e['stats']['attempts'] >= self::MIN_ATTEMPTS);
            $weakOnes = array_filter($entries, fn ($e) => $e['is_weak']);

            if (count($weakOnes) < 2) {
                continue;
            }

            $qualifiedCount = count($qualified) ?: count($attempted);
            if ($qualifiedCount === 0) {
                continue;
            }

            $weakRatio = count($weakOnes) / $qualifiedCount;
            if ($weakRatio < 0.5) {
                continue;
            }

            $category = $categories->firstWhere('id', $cid);
            if (!$category) {
                continue;
            }

            $totalAttempts = array_sum(array_map(fn ($e) => $e['stats']['attempts'], $attempted));
            $totalCorrect = array_sum(array_map(fn ($e) => $e['stats']['correct'], $attempted));

            $commonWeak[] = [
                'category_id' => $cid,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'parent_name' => $category->parent_id
                    ? optional($categories->firstWhere('id', $category->parent_id))->name
                    : null,
                'weak_student_count' => count($weakOnes),
                'weak_ratio' => round($weakRatio * 100, 1),
                'attempted_student_count' => count($attempted),
                'avg_accuracy' => $totalAttempts > 0
                    ? round($totalCorrect / $totalAttempts * 100, 1)
                    : null,
                'weak_students' => array_map(fn ($e) => [
                    'student' => $this->studentPayload($e['student']),
                    'accuracy' => $e['stats']['attempts'] > 0
                        ? round($e['stats']['correct'] / $e['stats']['attempts'] * 100, 1)
                        : null,
                ], $weakOnes),
            ];
        }

        usort($commonWeak, function ($a, $b) {
            if ($a['weak_student_count'] === $b['weak_student_count']) {
                return ($a['avg_accuracy'] ?? 100) <=> ($b['avg_accuracy'] ?? 100);
            }
            return $b['weak_student_count'] <=> $a['weak_student_count'];
        });

        $classRows = $rows;
        $categoryTree = $this->categoryTree($categories, $classRows);

        return [
            'scope' => 'class',
            'class' => [
                'id' => $classRoom->id,
                'name' => $classRoom->name,
                'student_count' => $students->count(),
            ],
            'overview' => array_merge($this->globalOverview($classRows), [
                'avg_exam_score' => $this->avgExamScore($studentIds),
                'active_students' => count(array_filter($studentOverview, fn ($s) => $s['attempts'] > 0)),
            ]),
            'common_weak_categories' => array_values($commonWeak),
            'categories' => $categoryTree,
            'by_type' => $this->aggregateByType($classRows),
            'by_difficulty' => $this->aggregateByDifficulty($classRows),
            'students' => $studentOverview,
            'notice' => '班级画像为聚合后的教学反馈，不展示也不影响任何学生的正式成绩。',
        ];
    }

    /**
     * 取指定学生在已评分正式考试中的所有客观题作答明细（含题目属性与该题在卷面上的分值）
     */
    protected function answerRows(array $userIds): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return DB::table('exam_record_answers as era')
            ->join('exam_records as er', 'era.exam_record_id', '=', 'er.id')
            ->join('questions as q', 'era.question_id', '=', 'q.id')
            ->join('exam_paper_questions as epq', function ($join) {
                $join->on('epq.question_id', '=', 'era.question_id')
                    ->on('epq.exam_paper_id', '=', 'er.exam_paper_id');
            })
            ->whereIn('er.user_id', $userIds)
            ->where('er.status', ExamRecord::STATUS_GRADED)
            ->where('q.type', '!=', Question::TYPE_ESSAY)
            ->orderBy('er.end_time')
            ->orderBy('era.id')
            ->get([
                'era.id as answer_id',
                'era.exam_record_id',
                'era.question_id',
                'era.is_correct',
                'er.user_id',
                'er.end_time as record_at',
                'q.category_id',
                'q.type',
                'q.difficulty',
                'epq.score as max_score',
            ]);
    }

    /**
     * 当前仍未攻克的错题（最近一次正式考试作答为错，且未通过练习答对过）
     */
    protected function activeWrongQuestions(int $userId, Collection $rows): array
    {
        // 每道题取时间序上的最后一次作答
        $latestByQuestion = [];
        foreach ($rows as $row) {
            $latestByQuestion[$row->question_id] = $row;
        }

        $questionIds = array_keys($latestByQuestion);
        $wrongCountById = [];
        $attemptCountById = [];
        foreach ($rows as $row) {
            $attemptCountById[$row->question_id] = ($attemptCountById[$row->question_id] ?? 0) + 1;
            if (!$row->is_correct) {
                $wrongCountById[$row->question_id] = ($wrongCountById[$row->question_id] ?? 0) + 1;
            }
        }

        // 练习中已答对的题目（练习完成时间晚于最后一次考试答错时间）视为已攻克
        $redeemedAt = [];
        if (!empty($questionIds)) {
            PracticeAnswer::query()
                ->join('practice_sessions as ps', 'practice_answers.practice_session_id', '=', 'ps.id')
                ->where('ps.user_id', $userId)
                ->where('ps.status', PracticeSession::STATUS_FINISHED)
                ->whereIn('practice_answers.question_id', $questionIds)
                ->where('practice_answers.is_correct', 1)
                ->get(['practice_answers.question_id', 'ps.finished_at'])
                ->each(function ($r) use (&$redeemedAt) {
                    $ts = strtotime((string) $r->finished_at) ?: 0;
                    if (!isset($redeemedAt[$r->question_id]) || $ts > $redeemedAt[$r->question_id]) {
                        $redeemedAt[$r->question_id] = $ts;
                    }
                });
        }

        $activeIds = [];
        foreach ($latestByQuestion as $qid => $row) {
            if ($row->is_correct) {
                continue;
            }
            $wrongAt = strtotime((string) $row->record_at) ?: 0;
            if (isset($redeemedAt[$qid]) && $redeemedAt[$qid] >= $wrongAt) {
                continue;
            }
            $activeIds[] = $qid;
        }

        if (empty($activeIds)) {
            return [];
        }

        $questions = Question::with('category')
            ->whereIn('id', $activeIds)
            ->where('status', 1)
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($activeIds as $qid) {
            $question = $questions->get($qid);
            if (!$question) {
                continue;
            }
            $row = $latestByQuestion[$qid];
            $result[] = [
                'question_id' => $qid,
                'title' => $question->title,
                'type' => $question->type,
                'type_label' => $this->typeLabel($question->type),
                'difficulty' => $question->difficulty,
                'difficulty_label' => Question::DIFFICULTIES[$question->difficulty] ?? (string) $question->difficulty,
                'category_id' => $question->category_id,
                'category_name' => $question->category->name ?? null,
                'attempts' => $attemptCountById[$qid] ?? 1,
                'wrong_count' => $wrongCountById[$qid] ?? 1,
                'max_score' => round((float) $row->max_score, 2),
                'last_record_id' => $row->exam_record_id,
                'last_wrong_at' => date('Y-m-d H:i:s', strtotime((string) $row->record_at) ?: time()),
            ];
        }

        // 错误次数多、最近仍错的题排前面 —— 真实错题驱动，而非热门题
        usort($result, function ($a, $b) {
            if ($a['wrong_count'] === $b['wrong_count']) {
                return strcmp($b['last_wrong_at'], $a['last_wrong_at']);
            }
            return $b['wrong_count'] <=> $a['wrong_count'];
        });

        return $result;
    }

    /**
     * 叶子分类（题目直接挂载的分类）统计
     * 返回 [category_id => stats]
     */
    protected function leafStats(Collection $categories, Collection $rows): array
    {
        $stats = [];
        foreach ($categories as $category) {
            $stats[$category->id] = $this->emptyStats();
        }

        foreach ($rows as $row) {
            if (!isset($stats[$row->category_id])) {
                $stats[$row->category_id] = $this->emptyStats();
            }
            $this->accumulate($stats[$row->category_id], $row);
        }

        return $stats;
    }

    /**
     * 两级分类树（父分类汇总其下所有子分类）
     */
    protected function categoryTree(Collection $categories, Collection $rows): array
    {
        $childrenOf = [];
        foreach ($categories as $category) {
            $childrenOf[$category->parent_id ?? 0][] = $category->id;
        }

        $buildNode = function (QuestionCategory $category) use (&$buildNode, $categories, $childrenOf, $rows) {
            $childIds = $childrenOf[$category->id] ?? [];

            $childNodes = [];
            foreach ($childIds as $cid) {
                $child = $categories->firstWhere('id', $cid);
                if ($child) {
                    $childNodes[] = $buildNode($child);
                }
            }

            // 汇总范围：自身直接挂载的题目 + 所有后代分类的题目
            $descendantIds = $this->collectDescendantIds($category->id, $childrenOf);
            $scopeIds = array_unique(array_merge([$category->id], $descendantIds));
            $scopedRows = $rows->filter(fn ($r) => in_array($r->category_id, $scopeIds, true));
            $stats = $this->summarize($scopedRows);

            return [
                'category_id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'is_leaf' => empty($childIds),
                'attempts' => $stats['attempts'],
                'correct' => $stats['correct'],
                'accuracy' => $stats['accuracy'],
                'max_score' => $stats['max_score'],
                'earned_score' => $stats['earned_score'],
                'lost_score' => $stats['lost_score'],
                'score_rate' => $stats['score_rate'],
                'proficiency' => $stats['proficiency'],
                'proficiency_label' => $this->proficiencyLabel($stats['proficiency']),
                'is_weak' => $stats['attempts'] >= self::MIN_ATTEMPTS
                    && $stats['accuracy'] !== null
                    && $stats['accuracy'] < self::WEAK_ACCURACY * 100,
                'children' => $childNodes,
            ];
        };

        $tree = [];
        foreach ($categories->where('parent_id', 0) as $root) {
            $tree[] = $buildNode($root);
        }

        return $tree;
    }

    protected function collectDescendantIds(int $parentId, array $childrenOf): array
    {
        $ids = [];
        foreach ($childrenOf[$parentId] ?? [] as $cid) {
            $ids[] = $cid;
            $ids = array_merge($ids, $this->collectDescendantIds($cid, $childrenOf));
        }
        return $ids;
    }

    protected function flattenWeakCategories(array $tree): array
    {
        $weak = [];
        $walk = function (array $nodes) use (&$walk, &$weak) {
            foreach ($nodes as $node) {
                if ($node['is_weak']) {
                    $weak[] = [
                        'category_id' => $node['category_id'],
                        'name' => $node['name'],
                        'accuracy' => $node['accuracy'],
                        'attempts' => $node['attempts'],
                        'lost_score' => $node['lost_score'],
                        'proficiency_label' => $node['proficiency_label'],
                        'is_leaf' => $node['is_leaf'],
                    ];
                }
                if (!empty($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk($tree);

        usort($weak, fn ($a, $b) => ($a['accuracy'] ?? 100) <=> ($b['accuracy'] ?? 100));

        return $weak;
    }

    protected function aggregateByType(Collection $rows): array
    {
        $result = [];
        foreach ([
            Question::TYPE_SINGLE_CHOICE,
            Question::TYPE_MULTIPLE_CHOICE,
            Question::TYPE_TRUE_FALSE,
            Question::TYPE_FILL_BLANK,
            Question::TYPE_ESSAY,
        ] as $type) {
            $scoped = $rows->where('type', $type);
            if ($scoped->isEmpty()) {
                continue;
            }
            $stats = $this->summarize($scoped);
            $result[] = [
                'type' => $type,
                'type_label' => $this->typeLabel($type),
                'attempts' => $stats['attempts'],
                'correct' => $stats['correct'],
                'accuracy' => $stats['accuracy'],
                'lost_score' => $stats['lost_score'],
                'proficiency_label' => $this->proficiencyLabel($stats['proficiency']),
                'is_weak' => $stats['attempts'] >= self::MIN_ATTEMPTS
                    && $stats['accuracy'] !== null
                    && $stats['accuracy'] < self::WEAK_ACCURACY * 100,
            ];
        }
        return $result;
    }

    protected function aggregateByDifficulty(Collection $rows): array
    {
        $result = [];
        foreach (Question::DIFFICULTIES as $level => $label) {
            $scoped = $rows->where('difficulty', $level);
            if ($scoped->isEmpty()) {
                continue;
            }
            $stats = $this->summarize($scoped);
            $result[] = [
                'difficulty' => $level,
                'difficulty_label' => $label,
                'attempts' => $stats['attempts'],
                'correct' => $stats['correct'],
                'accuracy' => $stats['accuracy'],
                'lost_score' => $stats['lost_score'],
                'proficiency_label' => $this->proficiencyLabel($stats['proficiency']),
                'is_weak' => $stats['attempts'] >= self::MIN_ATTEMPTS
                    && $stats['accuracy'] !== null
                    && $stats['accuracy'] < self::WEAK_ACCURACY * 100,
            ];
        }
        return $result;
    }

    protected function overview(int $userId, Collection $rows, array $wrongQuestions, array $tree): array
    {
        return array_merge($this->globalOverview($rows), [
            'active_wrong_questions' => count($wrongQuestions),
            'weak_category_count' => count($this->flattenWeakCategories($tree)),
            'graded_records' => ExamRecord::where('user_id', $userId)
                ->where('status', ExamRecord::STATUS_GRADED)
                ->count(),
            'avg_exam_score' => $this->avgExamScore([$userId]),
        ]);
    }

    protected function globalOverview(Collection $rows): array
    {
        $stats = $this->summarize($rows);

        return [
            'total_attempts' => $stats['attempts'],
            'total_correct' => $stats['correct'],
            'accuracy' => $stats['accuracy'],
            'total_score' => $stats['max_score'],
            'earned_score' => $stats['earned_score'],
            'lost_score' => $stats['lost_score'],
            'score_rate' => $stats['score_rate'],
        ];
    }

    protected function avgExamScore(array $userIds): ?float
    {
        if (empty($userIds)) {
            return null;
        }
        $avg = ExamRecord::whereIn('user_id', $userIds)
            ->where('status', ExamRecord::STATUS_GRADED)
            ->avg('score');
        return $avg === null ? null : round((float) $avg, 2);
    }

    protected function emptyStats(): array
    {
        return [
            'attempts' => 0,
            'correct' => 0,
            'earned' => 0.0,
            'max' => 0.0,
        ];
    }

    protected function accumulate(array &$stats, object $row): void
    {
        $stats['attempts']++;
        if ($row->is_correct) {
            $stats['correct']++;
            $stats['earned'] += (float) $row->max_score;
        }
        $stats['max'] += (float) $row->max_score;
    }

    /**
     * 汇总一组作答行
     */
    protected function summarize(Collection $rows): array
    {
        $attempts = $rows->count();
        $correct = (int) $rows->sum('is_correct');
        $maxScore = (float) $rows->sum('max_score');
        $earned = (float) $rows->sum(fn ($r) => $r->is_correct ? (float) $r->max_score : 0);

        $accuracy = $attempts > 0 ? round($correct / $attempts * 100, 1) : null;
        $scoreRate = $maxScore > 0 ? round($earned / $maxScore * 100, 1) : null;

        $proficiency = $this->proficiency($attempts, $accuracy);

        return [
            'attempts' => $attempts,
            'correct' => $correct,
            'accuracy' => $accuracy,
            'max_score' => round($maxScore, 2),
            'earned_score' => round($earned, 2),
            'lost_score' => round($maxScore - $earned, 2),
            'score_rate' => $scoreRate,
            'proficiency' => $proficiency,
        ];
    }

    protected function proficiency(int $attempts, ?float $accuracy): string
    {
        if ($attempts < self::MIN_ATTEMPTS) {
            return self::LEVEL_NO_DATA;
        }
        if ($accuracy === null) {
            return self::LEVEL_NO_DATA;
        }
        if ($accuracy >= 80) {
            return self::LEVEL_SOLID;
        }
        if ($accuracy < self::SEVERE_ACCURACY * 100) {
            return self::LEVEL_SEVERE;
        }
        return self::LEVEL_WEAK;
    }

    protected function proficiencyLabel(string $level): string
    {
        return match ($level) {
            self::LEVEL_SEVERE => '严重薄弱',
            self::LEVEL_WEAK => '有待加强',
            self::LEVEL_SOLID => '掌握良好',
            default => '数据不足',
        };
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            Question::TYPE_SINGLE_CHOICE => '单选题',
            Question::TYPE_MULTIPLE_CHOICE => '多选题',
            Question::TYPE_TRUE_FALSE => '判断题',
            Question::TYPE_FILL_BLANK => '填空题',
            Question::TYPE_ESSAY => '问答题',
            default => $type,
        };
    }

    protected function studentPayload($student): array
    {
        return [
            'id' => $student->id,
            'username' => $student->username,
            'real_name' => $student->real_name,
        ];
    }
}
