<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDaily extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_daily';

    protected $fillable = [
        'date',
        'visitors',
        'page_views',
        'tool_usage',
        'tool_success',
        'tool_failed',
    ];
}
