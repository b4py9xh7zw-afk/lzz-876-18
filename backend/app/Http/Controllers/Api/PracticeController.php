<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PracticeAnswer;
use App\Models\PracticeSession;
use App\Models\Question;
use App\Services\AnswerGrader;
use App\Services\RecommendationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 弱项巩固练习。
 *
 * 练习独立于正式考试：不创建 exam_records、不写入任何正式成绩，
 * 结果仅用于学习反馈与错题攻克状态更新。
 */
class PracticeController extends Controller
{
    public function __construct(private RecommendationService $recommendations)
    {
    }

    /**
     * 按推荐自动组一套练习（真实错题 + 相近题）
     */
    public function startRecommended(Request $request)
    {
        $recommendation = $this->recommendations->recommend($request->user()->id, 8);

        if (empty($recommendation['items'])) {
            return response()->json([
                'message' => '暂无可练习的题目：完成更多考试后，系统会基于你的真实错题生成练习。',
                'session' => null,
            ], 200);
        }

        $questionIds = array_map(fn ($item) => $item['question_id'], $recommendation['items']);
        $reasons = [];
        foreach ($recommendation['items'] as $item) {
            $reasons[$item['question_id']] = [
                'source' => $item['source'],
                'source_label' => $item['source_label'],
                'reason' => $item['reason'],
            ];
        }

        $session = $this->createSession(
            $request->user()->id,
            $questionIds,
            PracticeSession::SOURCE_RECOMMENDATION,
            '弱项巩固练习（智能推荐）'
        );

        return $this->sessionPayload($session, $reasons);
    }

    /**
     * 用教师/学生手动指定的题目开始练习（题目需来自本人错题推荐范围或题库启用题）
     */
    public function startCustom(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question_ids' => 'required|array|min:1|max:20',
            'question_ids.*' => 'integer',
            'title' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $questionIds = Question::whereIn('id', $request->question_ids)
            ->where('status', 1)
            ->pluck('id')
            ->all();

        if (empty($questionIds)) {
            return response()->json(['message' => '所选题目不可用'], 422);
        }

        $session = $this->createSession(
            $request->user()->id,
            $questionIds,
            PracticeSession::SOURCE_CUSTOM,
            $request->input('title', '自主练习')
        );

        return $this->sessionPayload($session);
    }

    /**
     * 提交练习并即时反馈
     */
    public function submit(Request $request, PracticeSession $session)
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权提交此练习'], 403);
        }

        if ($session->status === PracticeSession::STATUS_FINISHED) {
            return response()->json(['message' => '该练习已提交', 'session' => $session], 422);
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $allowedIds = $session->question_ids ?: [];
        $questions = Question::whereIn('id', $allowedIds)->get()->keyBy('id');

        $correctCount = 0;
        $earnedScore = 0.0;
        $details = [];

        foreach ($request->answers as $answerData) {
            $question = $questions->get($answerData['question_id']);
            if (!$question) {
                continue;
            }

            $isCorrect = AnswerGrader::grade($question, $answerData['answer'] ?? null);
            $questionScore = (float) $question->score;
            $gain = $isCorrect ? $questionScore : 0.0;

            if ($isCorrect) {
                $correctCount++;
                $earnedScore += $gain;
            }

            PracticeAnswer::create([
                'practice_session_id' => $session->id,
                'question_id' => $question->id,
                'answer' => $answerData['answer'] ?? '',
                'is_correct' => $isCorrect,
                'score' => $gain,
            ]);

            $details[] = [
                'question_id' => $question->id,
                'is_correct' => $isCorrect,
                'correct_answer' => $question->answer,
                'analysis' => $question->analysis,
                'score' => round($gain, 2),
                'max_score' => round($questionScore, 2),
            ];
        }

        $session->update([
            'status' => PracticeSession::STATUS_FINISHED,
            'correct_count' => $correctCount,
            'score' => round($earnedScore, 2),
            'finished_at' => now(),
        ]);

        return response()->json([
            'message' => '练习已提交（练习结果仅为学习反馈，不计入正式成绩）',
            'affects_grade' => false,
            'result' => [
                'session_id' => $session->id,
                'question_count' => $session->question_count,
                'correct_count' => $correctCount,
                'score' => round($earnedScore, 2),
                'total_score' => $session->total_score,
                'details' => $details,
            ],
        ]);
    }

    /**
     * 我的练习历史
     */
    public function history(Request $request)
    {
        $sessions = PracticeSession::where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate((int) $request->input('per_page', 10));

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * 练习详情：
     * - 进行中：返回不含正确答案的题面，供刷新后继续作答；
     * - 已完成：返回每题作答与解析。
     */
    public function show(Request $request, PracticeSession $session)
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此练习'], 403);
        }

        if ($session->status === PracticeSession::STATUS_FINISHED) {
            $session->load('answers.question');
        }

        $questions = [];
        if ($session->status === PracticeSession::STATUS_IN_PROGRESS && !empty($session->question_ids)) {
            $questionModels = Question::whereIn('id', $session->question_ids)
                ->where('status', 1)
                ->get()
                ->keyBy('id');

            foreach ($session->question_ids as $qid) {
                $question = $questionModels->get($qid);
                if (!$question) {
                    continue;
                }
                $questions[] = [
                    'id' => $question->id,
                    'type' => $question->type,
                    'title' => $question->title,
                    'options' => $question->options,
                    'score' => (float) $question->score,
                    'difficulty' => $question->difficulty,
                ];
            }
        }

        return response()->json([
            'session' => $session,
            'questions' => $questions,
            'affects_grade' => false,
        ]);
    }

    protected function createSession(int $userId, array $questionIds, string $source, string $title): PracticeSession
    {
        $questions = Question::whereIn('id', $questionIds)
            ->where('status', 1)
            ->get()
            ->keyBy('id');

        // 按传入顺序保留题目
        $orderedIds = array_values(array_filter($questionIds, fn ($id) => isset($questions[$id])));
        $totalScore = (float) $questions->sum('score');

        return PracticeSession::create([
            'user_id' => $userId,
            'source' => $source,
            'title' => $title,
            'question_ids' => $orderedIds,
            'question_count' => count($orderedIds),
            'correct_count' => 0,
            'total_score' => round($totalScore, 2),
            'score' => 0,
            'status' => PracticeSession::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    protected function sessionPayload(PracticeSession $session, array $reasons = [])
    {
        $questions = Question::whereIn('id', $session->question_ids)
            ->get()
            ->keyBy('id');

        $questionData = [];
        foreach ($session->question_ids as $qid) {
            $question = $questions->get($qid);
            if (!$question) {
                continue;
            }
            $questionData[] = [
                'id' => $question->id,
                'type' => $question->type,
                'title' => $question->title,
                'options' => $question->options,
                'score' => (float) $question->score,
                'difficulty' => $question->difficulty,
                'recommend' => $reasons[$qid] ?? null,
            ];
        }

        return response()->json([
            'message' => '练习已创建（练习不计入正式成绩）',
            'affects_grade' => false,
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'source' => $session->source,
                'question_count' => $session->question_count,
                'total_score' => $session->total_score,
            ],
            'questions' => $questionData,
        ], 201);
    }
}
