<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Event;

use Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend;

/**
 * Event dispatched when a Lucene cache backend needs optimization
 */
final class CacheOptimizationRequestedEvent
{
    public function __construct(
        private bool $shouldOptimize = true
    ) {
    }

    public function shouldOptimize(): bool
    {
        return $this->shouldOptimize;
    }

    public function preventOptimization(): void
    {
        $this->shouldOptimize = false;
    }
}
