<?php

namespace Tests\Feature;

use App\Http\Controllers\Support\HelpPageController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupportHelpPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_support_footer_destination_has_a_public_content_page(): void
    {
        foreach (HelpPageController::definitions() as $slug => $definition) {
            $response = $this->get('/help/'.$slug);

            $response->assertOk();
            $response->assertInertia(fn (Assert $page) => $page
                ->component('Support/HelpPage')
                ->where('page.slug', $slug)
                ->where('page.label', $definition['label'])
                ->where('page.title', $definition['title'])
                ->has('supportLinks', 8)
            );
        }
    }

    public function test_unknown_support_page_slug_returns_not_found(): void
    {
        $this->get('/help/not-a-real-support-page')->assertNotFound();
    }
}
