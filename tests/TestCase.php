<?php

namespace Tests;

use Database\Seeders\AirportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * The aerodrome registry is reference data rather than test fixtures —
     * resolving any code or free-text airport mention depends on it — so every
     * test starts with it seeded.
     */
    protected bool $seed = true;

    protected string $seeder = AirportSeeder::class;
}
