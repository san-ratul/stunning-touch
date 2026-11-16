<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function scheduledPost(array $overrides = []): BlogPost
    {
        return BlogPost::create($overrides + [
            'title' => 'Scheduled Article',
            'slug' => 'scheduled-article',
            'content' => '<h2>Heading</h2><p>'.trim(str_repeat('word ', 50)).'</p>',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
            'source' => 'manual',
        ]);
    }

    public function test_publishes_due_scheduled_post(): void
    {
        $post = $this->scheduledPost();

        $this->artisan('blog:publish-scheduled')->assertSuccessful();

        $post->refresh();
        $this->assertEquals('published', $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertNull($post->scheduled_at);
    }

    public function test_leaves_future_scheduled_posts_unpublished(): void
    {
        $post = $this->scheduledPost(['scheduled_at' => now()->addHour()]);

        $this->artisan('blog:publish-scheduled')->assertSuccessful();

        $post->refresh();
        $this->assertEquals('scheduled', $post->status);
        $this->assertNull($post->published_at);
        $this->assertNotNull($post->scheduled_at);
    }

    public function test_draft_with_past_scheduled_time_is_not_published(): void
    {
        $post = $this->scheduledPost(['status' => 'draft']);

        $this->artisan('blog:publish-scheduled')->assertSuccessful();

        $post->refresh();
        $this->assertEquals('draft', $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_archived_post_with_due_time_is_not_published(): void
    {
        $post = $this->scheduledPost(['status' => 'archived']);

        $this->artisan('blog:publish-scheduled')->assertSuccessful();

        $post->refresh();
        $this->assertEquals('archived', $post->status);
    }

    public function test_command_succeeds_when_nothing_is_due(): void
    {
        $this->artisan('blog:publish-scheduled')->assertSuccessful();
        $this->assertEquals(0, BlogPost::query()->where('status', 'published')->count());
    }

    public function test_scheduled_post_becomes_public_only_after_publication(): void
    {
        $post = $this->scheduledPost(['scheduled_at' => now()->addHour()]);

        // Before the scheduled time: not publicly visible.
        $this->get(route('blog.show', $post->slug))->assertNotFound();

        // Once due and processed by the command: publicly visible.
        $post->update(['scheduled_at' => now()->subMinute()]);
        $this->artisan('blog:publish-scheduled')->assertSuccessful();

        $this->get(route('blog.show', $post->slug))->assertOk();
    }
}
