<?php

namespace App\Services;

use App\Models\Question;

/**
 * 统一的客观题判分服务。
 *
 * 正式考试与课后练习共用同一套判分逻辑，保证画像数据口径一致。
 * 注意：该服务只负责“判分”，不会修改任何正式成绩。
 */
class AnswerGrader
{
    /**
     * 判断用户作答是否正确。
     */
    public static function grade(Question $question, ?string $userAnswer): bool
    {
        if ($userAnswer === null || trim($userAnswer) === '') {
            return false;
        }

        $correctAnswer = $question->answer;

        switch ($question->type) {
            case Question::TYPE_SINGLE_CHOICE:
            case Question::TYPE_TRUE_FALSE:
            case Question::TYPE_FILL_BLANK:
                return self::normalize($userAnswer) === self::normalize($correctAnswer);

            case Question::TYPE_MULTIPLE_CHOICE:
                $userAnswers = self::splitMulti($userAnswer);
                $correctAnswers = self::splitMulti($correctAnswer);
                return $userAnswers === $correctAnswers;

            case Question::TYPE_ESSAY:
            default:
                // 主观题不做自动判分，画像只统计客观题数据
                return false;
        }
    }

    /**
     * 题目类型是否可由系统自动判分（可纳入弱项画像统计）。
     */
    public static function isAutoGraded(string $type): bool
    {
        return in_array($type, [
            Question::TYPE_SINGLE_CHOICE,
            Question::TYPE_MULTIPLE_CHOICE,
            Question::TYPE_TRUE_FALSE,
            Question::TYPE_FILL_BLANK,
        ], true);
    }

    protected static function normalize(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    protected static function splitMulti(string $value): array
    {
        $parts = preg_split('/[,，\s]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_map(static fn ($v) => mb_strtoupper(trim($v)), $parts);
        $parts = array_unique($parts);
        sort($parts);
        return $parts;
    }
}
