<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolUsageDaily extends Model
{
    public $timestamps = false;

    protected $table = 'tool_usage_daily';

    protected $fillable = [
        'tool_id',
        'date',
        'total',
        'success',
        'failed',
        'unique_visitors',
    ];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }
}
