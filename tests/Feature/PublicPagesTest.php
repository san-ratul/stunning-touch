<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_with_branding_and_tools(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Stunning Touch');
        $response->assertSee('Your Everyday Tools');
        $response->assertSee('Image Compressor');
        $response->assertSee('All in One Place');
    }

    public function test_tools_index_lists_categories(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->get('/tools');

        $response->assertStatus(200);
        $response->assertSee('Image Tools');
        $response->assertSee('PDF Tools');
        $response->assertSee('Merge PDF');
    }

    public function test_category_pages_render(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['image-tools', 'pdf-tools', 'text-tools', 'developer-tools', 'generators'] as $slug) {
            $this->get('/'.$slug)->assertStatus(200);
        }
    }

    public function test_static_legal_pages_render(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/about')->assertStatus(200);
        $this->get('/contact')->assertStatus(200);
        $this->get('/privacy-policy')->assertStatus(200);
        $this->get('/terms')->assertStatus(200);
        $this->get('/disclaimer')->assertStatus(200);
    }

    public function test_unknown_slug_renders_custom_404(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/this-page-does-not-exist')->assertStatus(404);
        $this->get('/this-page-does-not-exist')->assertSee('Page not found');
    }

    public function test_contact_form_stores_message_and_honeypot_blocks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->post('/contact', [
            'name' => 'Jane Tester',
            'email' => 'jane@example.com',
            'subject' => 'Hello',
            'message' => 'Testing the contact form.',
        ], ['Headers' => ['X-Requested-With' => null]]);

        $response->assertRedirect();
        $this->assertDatabaseCount('contact_messages', 1);

        $this->post('/contact', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'subject' => 'Spam',
            'message' => 'spam',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseCount('contact_messages', 1);
    }
}
