<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

require_once dirname(__DIR__).'/vendor/autoload.php';

// Runs even for plain PHPUnit tests that do not extend Tests\TestCase.
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->make('db')->connection('pgsql')->getPdo();
