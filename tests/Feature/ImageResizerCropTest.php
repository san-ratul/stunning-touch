<?php

namespace Tests\Feature;

use App\Support\ToolRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageResizerCropTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_image_resizer_page_renders_the_crop_editor(): void
    {
        $response = $this->get('/image-resizer');

        $response->assertStatus(200);

        // Crop editor mount, dimension labels and live preview
        $response->assertSee('data-crop-mount', false);
        $response->assertSee('data-original-dims', false);
        $response->assertSee('data-crop-dims-label', false);
        $response->assertSee('data-output-dims-label', false);
        $response->assertSee('data-crop-preview', false);

        // Crop position presets (all nine) + reset action
        $response->assertSee('data-position-presets', false);
        foreach (['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'] as $preset) {
            $response->assertSee('data-pos="'.$preset.'"', false);
        }
        $response->assertSee('data-reset-crop', false);
        $response->assertSee('Reset crop', false);
    }

    public function test_image_resizer_page_keeps_the_existing_resize_controls(): void
    {
        $response = $this->get('/image-resizer');

        $response->assertStatus(200);
        $response->assertSee('data-w', false);
        $response->assertSee('data-h', false);
        $response->assertSee('data-percent', false);
        $response->assertSee('data-presets', false);
        $response->assertSee('data-format', false);
        $response->assertSee('data-lock', false);
        $response->assertSee('data-apply', false);
        $response->assertSee('Keep aspect ratio', false);

        // Upload validation contract stays intact
        $response->assertSee('accept="image/jpeg,image/png,image/webp"', false);
    }

    public function test_registry_configuration_for_image_resizer_is_unchanged(): void
    {
        $config = ToolRegistry::config('image-resizer');

        $this->assertSame('tools.image-resizer', $config['view']);
        $this->assertSame('tools/image-resizer.js', $config['js']);
        $this->assertSame('image/jpeg,image/png,image/webp', $config['accept']);
        $this->assertTrue($config['client_side']);
    }

    public function test_crop_position_preset_buttons_expose_pressed_state(): void
    {
        $response = $this->get('/image-resizer');

        $response->assertStatus(200);
        $this->assertSame(
            9,
            substr_count($response->getContent(), 'aria-pressed="false"'),
            'All nine position presets must render with an explicit pressed state.',
        );
    }
}
