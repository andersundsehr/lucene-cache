<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\EventListener;

use Weakbit\LuceneCache\Event\CacheOptimizationRequestedEvent;

/**
 * Listens for CacheOptimizationRequestedEvent and disables optimization if not enabled in config
 */
class EnableOptimizationListener extends AbstractExtensionConfigListener
{
    public function __invoke(CacheOptimizationRequestedEvent $event): void
    {
        if (!$event->shouldOptimize()) {
            return;
        }

        $extensionConfig = $this->getExtensionConfiguration();
        if (!($extensionConfig['enableOptimization'] ?? true)) {
            $event->preventOptimization();
        }
    }
}
