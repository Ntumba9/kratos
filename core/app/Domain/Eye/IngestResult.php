<?php

namespace App\Domain\Eye;

use App\Models\SecurityEvent;

final readonly class IngestResult
{
    public function __construct(
        public SecurityEvent $event,
        public bool $duplicate,
    ) {}
}
