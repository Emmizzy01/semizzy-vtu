<?php

namespace Tests\Feature;

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
}
