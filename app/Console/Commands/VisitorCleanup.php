<?php

namespace App\Console\Commands;

use App\Models\Visitor;
use Illuminate\Support\Facades\DB;

class VisitorCleanup
{
    /**
     * Delete visitors that no longer have any page views
     * (e.g. after retention pruning).
     */
    public static function pruneOrphans(): int
    {
        return Visitor::query()
            ->whereNotIn('id', DB::table('page_views')->select('visitor_id'))
            ->delete();
    }
}
