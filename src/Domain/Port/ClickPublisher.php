<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Entity\Click;

interface ClickPublisher
{
    public function publish(Click $click): void;
}
