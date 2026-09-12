<?php

declare(strict_types=1);

namespace JeffersonGoncalves\FlysystemGoogleDrive\Tests\Support;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\Resource\Files;
use Google\Service\Drive\Resource\Permissions;
use Mockery;
use Mockery\MockInterface;

/**
 * Builds a real \Google\Service\Drive instance whose `files`/`permissions`
 * resources are Mockery mocks, so adapter tests never touch the network or
 * need real credentials.
 */
final class DriveTestFactory
{
    /**
     * @return array{0: Drive, 1: MockInterface, 2: MockInterface}
     */
    public static function make(): array
    {
        $drive = new Drive(new Client);

        /** @var MockInterface $files */
        $files = Mockery::mock(Files::class);
        /** @var MockInterface $permissions */
        $permissions = Mockery::mock(Permissions::class);

        $drive->files = $files;
        $drive->permissions = $permissions;

        return [$drive, $files, $permissions];
    }
}
