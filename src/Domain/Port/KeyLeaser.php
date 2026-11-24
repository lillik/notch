<?php

declare(strict_types=1);

namespace App\Domain\Port;

interface KeyLeaser
{
    public function take(): string;
}
