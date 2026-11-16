<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\BlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishScheduledPosts extends Command
{
    protected $signature = 'blog:publish-scheduled';

    protected $description = 'Publish scheduled blog posts whose scheduled_at time is due';

    public function handle(): int
    {
        $due = BlogPost::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $published = 0;

        foreach ($due as $post) {
            try {
                // scheduled_at is cleared and published_at records the actual
                // moment the post became publicly visible.
                $post->update(['status' => 'published', 'published_at' => now(), 'scheduled_at' => null]);
                AuditLog::record('post.published', $post, 'Scheduled publication: '.$post->title);
                $published++;
                $this->info('Published: '.$post->title);
            } catch (\Throwable $e) {
                Log::error('Scheduled publication failed for post '.$post->id.': '.$e->getMessage());
                $this->error('Failed to publish: '.$post->title);
            }
        }

        $this->info("{$published} of {$due->count()} scheduled post(s) published.");

        return $published === $due->count() ? self::SUCCESS : self::FAILURE;
    }
}
