<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

uses(TestCase::class)->group('emulator')->in('Emulator');
