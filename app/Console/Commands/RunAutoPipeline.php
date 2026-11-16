<?php

namespace App\Console\Commands;

use App\Models\AutomationSetting;
use App\Models\PipelineRun;
use App\Services\Ai\BlogAutoPipeline;
use Illuminate\Console\Command;

class RunAutoPipeline extends Command
{
    protected $signature = 'blog:auto-pipeline {--force : Attempt a run even when automation is disabled}';

    protected $description = 'Run one bounded autonomous blogging attempt for the current due publishing slot';

    public function handle(BlogAutoPipeline $pipeline): int
    {
        $automation = AutomationSetting::current();

        if (! $automation->is_enabled && ! $this->option('force')) {
            $this->info('Blog automation is disabled.');

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $automation->update(['is_enabled' => true]);
        }

        $run = $pipeline->run();

        if ($run === null) {
            $this->info('No publishing slot is due, the daily limit is reached, or another generation is running.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Pipeline run #%d (%s %s, attempt %d): %s%s',
            $run->id,
            $run->slot_date?->toDateString(),
            $run->slot,
            $run->attempts,
            $run->status,
            $run->error_message ? ' — '.$run->error_message : ''
        ));

        if ($run->blog_post_id) {
            $this->info('Post ID: '.$run->blog_post_id.($run->published ? ' (published)' : ' (draft for review)'));
        }

        return $run->status === PipelineRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
