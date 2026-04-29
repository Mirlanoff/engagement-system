<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRecommendation extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'generated_for', 'type', 'content',
        'key_insights', 'action_items', 'session_avg_score',
        'input_data_summary', 'model_used', 'tokens_used',
        'is_read', 'read_at', 'helpfulness_rating',
    ];

    protected $casts = [
        'key_insights' => 'array',
        'action_items' => 'array',
        'input_data_summary' => 'array',
        'session_avg_score' => 'decimal:2',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(LessonSession::class, 'session_id');
    }

    public function generatedFor()
    {
        return $this->belongsTo(User::class, 'generated_for');
    }
}
