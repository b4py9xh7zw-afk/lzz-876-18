<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 课后练习会话。
 *
 * 练习数据完全独立于 exam_records，不计入任何正式成绩，
 * 仅用于弱项巩固与学习反馈。
 */
class PracticeSession extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'title',
        'question_ids',
        'question_count',
        'correct_count',
        'total_score',
        'score',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'question_ids' => 'array',
        'question_count' => 'integer',
        'correct_count' => 'integer',
        'total_score' => 'decimal:2',
        'score' => 'decimal:2',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const SOURCE_RECOMMENDATION = 'recommendation';
    public const SOURCE_WRONG = 'wrong_questions';
    public const SOURCE_CUSTOM = 'custom';

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FINISHED = 'finished';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function answers()
    {
        return $this->hasMany(PracticeAnswer::class, 'practice_session_id');
    }
}
