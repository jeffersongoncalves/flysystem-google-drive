<?php

declare(strict_types=1);

use JeffersonGoncalves\FlysystemGoogleDrive\Tests\TestCase;

uses(TestCase::class)->in('Feature');

afterEach(function () {
    Mockery::close();
});
