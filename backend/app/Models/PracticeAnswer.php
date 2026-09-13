<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PracticeAnswer extends Model
{
    protected $fillable = [
        'practice_session_id',
        'question_id',
        'answer',
        'is_correct',
        'score',
    ];

    protected $casts = [
        'practice_session_id' => 'integer',
        'question_id' => 'integer',
        'is_correct' => 'boolean',
        'score' => 'decimal:2',
    ];

    public function session()
    {
        return $this->belongsTo(PracticeSession::class, 'practice_session_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
