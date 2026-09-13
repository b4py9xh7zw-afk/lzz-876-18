<?php

namespace App\Services;

use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\Question;
use App\Models\QuestionCategory;
use Illuminate\Support\Collection;

/**
 * 弱项练习推荐服务。
 *
 * 推荐只来源于两类真实数据，绝不按热度/热门程度推荐：
 * 1. 学生自己在正式考试中做错、且尚未攻克的原题；
 * 2. 与这些错题同知识点、同题型、难度相近的题库题目。
 */
class RecommendationService
{
    public const MAX_WRONG = 5;
    public const MAX_SIMILAR = 8;

    public function __construct(private WeaknessProfileService $profiles)
    {
    }

    public function recommend(int $userId, ?int $limit = null): array
    {
        $profile = $this->profiles->studentProfile($userId);
        $wrongQuestions = $profile['wrong_questions'];

        $wrongSlice = array_slice($wrongQuestions, 0, self::MAX_WRONG);
        $wrongIds = array_map(fn ($w) => $w['question_id'], $wrongSlice);

        // 相近题排除该生在正式考试中已作答过的所有题，保证推的是“新题”
        $attemptedIds = ExamRecordAnswer::query()
            ->join('exam_records as er', 'exam_record_answers.exam_record_id', '=', 'er.id')
            ->where('er.user_id', $userId)
            ->where('er.status', ExamRecord::STATUS_GRADED)
            ->pluck('exam_record_answers.question_id')
            ->unique()
            ->values()
            ->all();

        $wrongItems = array_map(function ($w) {
            return [
                'question_id' => $w['question_id'],
                'title' => $w['title'],
                'type' => $w['type'],
                'type_label' => $w['type_label'],
                'difficulty' => $w['difficulty'],
                'difficulty_label' => $w['difficulty_label'],
                'category_id' => $w['category_id'],
                'category_name' => $w['category_name'],
                'source' => 'wrong_question',
                'source_label' => '我的错题',
                'reason' => sprintf(
                    '该题在正式考试中答错 %d 次（最近一次：%s），建议先重做原题。',
                    $w['wrong_count'],
                    $w['last_wrong_at']
                ),
            ];
        }, $wrongSlice);

        $similarItems = $this->similarQuestions($userId, $wrongQuestions, $attemptedIds);

        $items = array_merge($wrongItems, $similarItems);

        if ($limit !== null && $limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        return [
            'items' => $items,
            'wrong_question_count' => count($wrongItems),
            'similar_question_count' => count($similarItems),
            'rule' => '推荐题目全部来自本人真实错题及其同知识点相近题，不使用热门题排序。',
        ];
    }

    /**
     * 根据错题找出相近题
     */
    protected function similarQuestions(int $userId, array $wrongQuestions, array $attemptedIds): array
    {
        if (empty($wrongQuestions)) {
            return [];
        }

        $categories = QuestionCategory::all();

        // 为每个候选分类（错题所在分类及其父分类）准备种子信息
        $seedsByCategory = [];
        foreach ($wrongQuestions as $wrong) {
            $cid = $wrong['category_id'];
            $seedsByCategory[$cid][] = $wrong;
        }

        $seedCategoryIds = array_keys($seedsByCategory);

        // 扩展到同属一个父知识点的兄弟分类
        $expandedIds = [];
        foreach ($seedCategoryIds as $cid) {
            $category = $categories->firstWhere('id', $cid);
            if (!$category) {
                $expandedIds[] = $cid;
                continue;
            }
            $expandedIds[] = $category->id;
            if ($category->parent_id) {
                foreach ($categories->where('parent_id', $category->parent_id) as $sibling) {
                    $expandedIds[] = $sibling->id;
                }
            } else {
                // 父分类本身：纳入其全部子分类
                foreach ($categories->where('parent_id', $category->id) as $child) {
                    $expandedIds[] = $child->id;
                }
            }
        }
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));

        $seedTypes = array_values(array_unique(array_map(fn ($w) => $w['type'], $wrongQuestions)));
        $seedDifficulties = array_values(array_unique(array_map(fn ($w) => (int) $w['difficulty'], $wrongQuestions)));

        $candidates = Question::with('category')
            ->where('status', 1)
            ->whereIn('category_id', $expandedIds)
            ->whereNotIn('id', $attemptedIds)
            ->get();

        $scored = [];
        foreach ($candidates as $question) {
            $score = $this->similarityScore($question, $seedsByCategory, $seedTypes, $seedDifficulties, $categories);
            if ($score <= 0) {
                continue;
            }
            $scored[] = ['question' => $question, 'score' => $score];
        }

        // 按相似度排序；相似度相同时不借助热度，用 ID 保证稳定顺序
        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['question']->id <=> $b['question']->id;
            }
            return $b['score'] <=> $a['score'];
        });

        $items = [];
        foreach (array_slice($scored, 0, self::MAX_SIMILAR) as $entry) {
            $question = $entry['question'];
            $items[] = [
                'question_id' => $question->id,
                'title' => $question->title,
                'type' => $question->type,
                'type_label' => $this->typeLabel($question->type),
                'difficulty' => $question->difficulty,
                'difficulty_label' => Question::DIFFICULTIES[$question->difficulty] ?? (string) $question->difficulty,
                'category_id' => $question->category_id,
                'category_name' => $question->category->name ?? null,
                'source' => 'similar',
                'source_label' => '相近题',
                'reason' => sprintf(
                    '与你的错题「%s」同属知识点，题型/难度相近（相似度 %d 分）。',
                    $question->category->name ?? '未知知识点',
                    $entry['score']
                ),
            ];
        }

        return $items;
    }

    /**
     * 相似度打分：知识点匹配权重最高，其次题型，再次难度
     */
    protected function similarityScore(
        Question $question,
        array $seedsByCategory,
        array $seedTypes,
        array $seedDifficulties,
        Collection $categories
    ): int {
        $score = 0;

        if (isset($seedsByCategory[$question->category_id])) {
            // 与错题完全同一知识点
            $score += 60;
        } else {
            // 同父知识点（兄弟分类）给部分分数
            $category = $categories->firstWhere('id', $question->category_id);
            if ($category && $category->parent_id) {
                foreach ($seedsByCategory as $cid => $seeds) {
                    $seedCategory = $categories->firstWhere('id', $cid);
                    if ($seedCategory && $seedCategory->parent_id === $category->parent_id) {
                        $score += 30;
                        break;
                    }
                }
            }
        }

        if ($score === 0) {
            return 0;
        }

        if (in_array($question->type, $seedTypes, true)) {
            $score += 25;
        }

        if (in_array((int) $question->difficulty, $seedDifficulties, true)) {
            $score += 15;
        } else {
            // 难度相差一级仍算相近，相差两级不给分
            foreach ($seedDifficulties as $d) {
                if (abs($d - (int) $question->difficulty) === 1) {
                    $score += 6;
                    break;
                }
            }
        }

        return $score;
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
}
