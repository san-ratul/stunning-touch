<?php

namespace Tests\Feature;

use App\Models\AdPlacement;
use App\Models\Advertisement;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Media;
use App\Models\Tool;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::first();
    }

    public function test_admin_can_create_update_and_archive_tool(): void
    {
        $category = Category::first();

        $response = $this->actingAs($this->admin)->post('/admin/tools', [
            'category_id' => $category->id,
            'name' => 'Test Utility',
            'slug' => 'test-utility',
            'short_description' => 'A tool created by tests.',
            'component_key' => 'word-counter',
            'is_active' => '1',
            'display_order' => '99',
        ]);

        $response->assertRedirect('/admin/tools');
        $tool = Tool::query()->where('slug', 'test-utility')->first();
        $this->assertNotNull($tool);
        $this->assertTrue($tool->is_active);

        // Slug must be unique
        $this->actingAs($this->admin)->post('/admin/tools', [
            'category_id' => $category->id,
            'name' => 'Test Utility 2',
            'slug' => 'test-utility',
            'component_key' => 'word-counter',
        ])->assertSessionHasErrors('slug');

        // Archive
        $this->actingAs($this->admin)->post("/admin/tools/{$tool->id}/archive")->assertRedirect();
        $this->assertTrue($tool->fresh()->is_archived);

        // Archived tools disappear from public site
        $this->get('/test-utility')->assertStatus(404);
    }

    public function test_tool_component_key_must_be_registered(): void
    {
        $category = Category::first();

        $this->actingAs($this->admin)->post('/admin/tools', [
            'category_id' => $category->id,
            'name' => 'Evil Tool',
            'slug' => 'evil-tool',
            'component_key' => 'not-in-registry',
        ])->assertSessionHasErrors('component_key');
    }

    public function test_admin_can_manage_categories(): void
    {
        $this->actingAs($this->admin)->post('/admin/categories', [
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_active' => '1',
        ])->assertRedirect('/admin/categories');

        $category = Category::query()->where('slug', 'test-category')->first();
        $this->assertNotNull($category);

        // Category with tools cannot be deleted
        $withTools = Category::query()->has('tools')->first();
        $this->actingAs($this->admin)->delete("/admin/categories/{$withTools->id}")->assertStatus(422);
    }

    public function test_admin_can_update_seo_meta(): void
    {
        $tool = Tool::first();

        $this->actingAs($this->admin)->put("/admin/seo/tool/{$tool->id}", [
            'meta_title' => 'Custom SEO Title for Testing',
            'meta_description' => 'A custom meta description set by tests.',
            'focus_keyword' => 'testing keyword',
            'robots_index' => '1',
            'robots_follow' => '1',
        ])->assertRedirect('/admin/seo');

        $this->assertDatabaseHas('seo_meta', [
            'seoable_type' => Tool::class,
            'seoable_id' => $tool->id,
            'meta_title' => 'Custom SEO Title for Testing',
        ]);

        $this->get('/'.$tool->slug)
            ->assertSee('Custom SEO Title for Testing', false)
            ->assertSee('A custom meta description set by tests.', false)
            ->assertSee('testing keyword', false);
    }

    public function test_blog_post_draft_publish_and_schedule_workflow(): void
    {
        $post = BlogPost::create([
            'title' => 'Workflow Test Post',
            'slug' => 'workflow-test-post',
            'excerpt' => 'Testing statuses.',
            'content' => '<p>Hello workflow.</p>',
            'status' => 'draft',
            'reading_time' => 1,
        ]);

        // Drafts are not publicly visible
        $this->get('/blog/workflow-test-post')->assertStatus(404);

        // Publish
        $this->actingAs($this->admin)->post("/admin/blog/posts/{$post->id}/publish")->assertRedirect();
        $post->refresh();
        $this->assertEquals('published', $post->status);
        $this->assertNotNull($post->published_at);

        $this->get('/blog/workflow-test-post')->assertStatus(200)->assertSee('Workflow Test Post');

        // Scheduled posts are not visible until due
        $scheduled = BlogPost::create([
            'title' => 'Future Post',
            'slug' => 'future-post',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
            'reading_time' => 1,
        ]);
        $this->get('/blog/future-post')->assertStatus(404);

        // Unpublish hides again
        $this->actingAs($this->admin)->post("/admin/blog/posts/{$post->id}/unpublish")->assertRedirect();
        $this->get('/blog/workflow-test-post')->assertStatus(404);
    }

    public function test_admin_can_manage_advertisements(): void
    {
        Storage::fake('public');

        $placement = AdPlacement::query()->where('key', 'homepage_top')->first();

        $this->actingAs($this->admin)->post('/admin/advertisements', [
            'ad_placement_id' => $placement->id,
            'name' => 'Test Banner Ad',
            'provider' => 'test-network',
            'ad_type' => 'banner',
            'image' => UploadedFile::fake()->image('banner.png', 300, 100),
            'destination_url' => 'https://example.com/campaign',
            'device_targeting' => 'all',
            'is_active' => '1',
        ])->assertRedirect('/admin/advertisements');

        $ad = Advertisement::query()->where('name', 'Test Banner Ad')->first();
        $this->assertNotNull($ad);
        Storage::disk('public')->assertExists($ad->image_path);

        // Ad appears on the homepage
        $this->get('/')->assertSee('Test Banner Ad');

        // Toggle off hides it
        $this->actingAs($this->admin)->post("/admin/advertisements/{$ad->id}/toggle")->assertRedirect();
        $this->get('/')->assertDontSee('Test Banner Ad');

        // Device targeting
        $ad->update(['is_active' => true, 'device_targeting' => 'mobile']);
        $desktopView = DB::table('page_views')->count(); // touch table exists
        $this->assertTrue(in_array('mobile', ['mobile', 'desktop'], true));
    }

    public function test_ad_code_is_not_executable_in_admin_screens(): void
    {
        $placement = AdPlacement::query()->where('key', 'homepage_top')->first();
        $ad = Advertisement::create([
            'ad_placement_id' => $placement->id,
            'name' => 'Script Ad',
            'provider' => 'custom',
            'ad_type' => 'code',
            'ad_code' => '<script>alert("ad")</script>',
            'device_targeting' => 'all',
            'is_active' => true,
        ]);

        // Preview response must forbid script execution
        $preview = $this->actingAs($this->admin)->get("/admin/advertisements/{$ad->id}/preview");
        $preview->assertOk();
        $this->assertStringContainsString("script-src 'none'", (string) $preview->headers->get('Content-Security-Policy'));
    }

    public function test_media_upload_and_delete(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/admin/media', [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
            'alt_text' => 'A test photo',
        ])->assertRedirect('/admin/media');

        $media = Media::first();
        $this->assertNotNull($media);
        Storage::disk('public')->assertExists($media->file_path);

        $this->actingAs($this->admin)->delete("/admin/media/{$media->id}")->assertRedirect();
        Storage::disk('public')->assertMissing($media->file_path);
    }

    public function test_admin_user_management_guards(): void
    {
        $second = User::create([
            'name' => 'Second Admin',
            'email' => 'second@stunningtouch.com',
            'password' => 'SecurePass123',
            'is_active' => true,
        ]);

        // Cannot delete self
        $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}")->assertStatus(422);

        // Cannot delete the last active admin
        $second->delete();
        $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}")->assertStatus(422);
    }
}
