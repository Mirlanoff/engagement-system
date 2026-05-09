<?php

namespace App\Domain\Recommendation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRecommendation extends Model
{
    use HasUuids;

    protected $table = 'ai_recommendations';

    protected $fillable = [
        'session_id',
        'classroom_id',
        'generated_for',
        'type',
        'content',
        'key_insights',
        'action_items',
        'session_avg_score',
        'input_data_summary',
        'model_used',
        'tokens_used',
        'is_read',
        'read_at',
        'helpfulness_rating',
    ];

    protected $casts = [
        'key_insights'        => 'array',
        'action_items'        => 'array',
        'input_data_summary'  => 'array',
        'session_avg_score'   => 'decimal:2',
        'is_read'             => 'boolean',
        'read_at'             => 'datetime',
        'helpfulness_rating'  => 'integer',
    ];

    public $timestamps = true;
}
