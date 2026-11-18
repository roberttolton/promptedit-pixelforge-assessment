<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * A running `npm run dev` leaves public/hot behind, which makes Inertia call the dev server for SSR,
     * and a stale build lacks new pages in the manifest. Feature tests need neither.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.ssr.enabled' => false]);

        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
