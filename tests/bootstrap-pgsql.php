<?php

declare(strict_types=1);

use App\Support\Testing\PostgresTestPreflight;

require_once dirname(__DIR__).'/vendor/autoload.php';

// Mandatory even for direct PHPUnit and plain tests. Laravel boots only in the worker or a test.
(new PostgresTestPreflight)->verify();
