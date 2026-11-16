<?php

namespace Tests\Feature;

use App\Models\Tool;
use App\Support\ToolRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_all_twelve_tool_routes_render_with_unique_seo_titles(): void
    {
        $tools = Tool::query()->active()->get();

        $this->assertCount(12, $tools);

        $titles = [];

        foreach ($tools as $tool) {
            $response = $this->get('/'.$tool->slug);

            $response->assertStatus(200);
            $response->assertSee('data-tool="'.$tool->component_key.'"', false);

            preg_match('/<title>([^<]+)<\/title>/', $response->getContent(), $m);
            $this->assertNotEmpty($m[1] ?? null, "Tool {$tool->slug} is missing a title tag.");
            $titles[$tool->slug] = $m[1];

            $response->assertSee('<link rel="canonical"', false);
            $response->assertSee('name="description"', false);
        }

        $this->assertCount(12, array_unique($titles), 'Tool meta titles must be unique.');
    }

    public function test_inactive_tool_returns_404(): void
    {
        $tool = Tool::query()->where('slug', 'word-counter')->first();
        $tool->update(['is_active' => false]);

        $this->get('/word-counter')->assertStatus(404);
    }

    public function test_archived_tool_returns_404(): void
    {
        $tool = Tool::query()->where('slug', 'word-counter')->first();
        $tool->update(['is_archived' => true]);

        $this->get('/word-counter')->assertStatus(404);
    }

    public function test_registry_contains_all_twelve_component_keys(): void
    {
        $this->assertCount(12, ToolRegistry::keys());
        $this->assertTrue(ToolRegistry::exists('image-compressor'));
        $this->assertTrue(ToolRegistry::exists('uuid-generator'));
        $this->assertFalse(ToolRegistry::exists('not-a-real-component'));
    }

    public function test_tool_usage_beacon_records_real_processing(): void
    {
        $response = $this->post('/api/tool-usage', [
            'tool' => 'word-counter',
            'success' => 'true',
            'event' => 'run',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('tool_usage', 1);
        $this->assertDatabaseHas('tool_usage', [
            'success' => true,
            'event_type' => 'run',
        ]);

        // Unknown component keys are rejected
        $this->post('/api/tool-usage', [
            'tool' => 'not-a-tool',
            'success' => 'true',
        ])->assertStatus(422);
    }

    public function test_tool_usage_requires_all_fields(): void
    {
        $this->postJson('/api/tool-usage', ['tool' => 'word-counter'])->assertStatus(422);
        $this->postJson('/api/tool-usage', ['success' => 'true'])->assertStatus(422);
    }

    public function test_tool_search_returns_matching_tools(): void
    {
        $response = $this->getJson('/tools/search?q=compress');

        $response->assertOk();
        $response->assertJsonStructure(['results']);
        $names = collect($response->json('results'))->pluck('name');
        $this->assertStringContainsStringIgnoringCase('compress', $names->first());
    }
}
