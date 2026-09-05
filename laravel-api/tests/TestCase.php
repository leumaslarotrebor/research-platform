<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

// Same situation as app/Http/Controllers/Controller.php: normally
// part of Laravel's own skeleton, provided here directly because
// scripts/bootstrap-laravel-api.sh excludes tests/ from the skeleton
// copy (we ship our own tests/Feature/*.php).
abstract class TestCase extends BaseTestCase
{
    //
}
