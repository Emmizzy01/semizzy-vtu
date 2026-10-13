<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_page_renders_semizzy_welcome_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('SEMIZZY');
    }
}
