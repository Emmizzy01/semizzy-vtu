<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_the_original_semizzy_single_prototype(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Semizzy VTU - Single File Dashboard');
        $response->assertSee('User Dashboard');
        $response->assertSee('Admin Dashboard');
        $response->assertSee('switchView');
    }
    public function test_home_page_uses_admin_saved_content_without_changing_the_prototype_layout(): void
    {
        IntegrationSetting::query()->create([
            'key' => 'homepage.cashback_line_one',
            'value' => 'A new homepage headline',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('A new homepage headline');
        $response->assertSee('id="view-user"', false);
        $response->assertSee('id="view-admin"', false);
        $response->assertSee("switchView('admin')", false);
    }

}
