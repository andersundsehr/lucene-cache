<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\EventListener;

use Weakbit\LuceneCache\Event\CacheOptimizationRequestedEvent;

/**
 * Listens for CacheOptimizationRequestedEvent and disables optimization if system load is too high
 */
class SystemLoadOptimizationListener extends AbstractExtensionConfigListener
{
    public function __invoke(CacheOptimizationRequestedEvent $event): void
    {
        if (!$event->shouldOptimize()) {
            return;
        }

        $extensionConfig = $this->getExtensionConfiguration();
        $systemLoad = $this->getSystemLoad();
        if ($systemLoad === null) {
            return;
        }

        if ($this->shouldOptimizeBasedOnLoad($systemLoad, $extensionConfig)) {
            return;
        }

        $event->preventOptimization();
    }

    /**
     * @return array<string, float>|null Returns an associative array with keys '1min', '5min', '15min' or null if not available
     */
    private function getSystemLoad(): ?array
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        if ($load === false || !is_array($load) || count($load) < 3) {
            return null;
        }

        return [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2]
        ];
    }

    /**
     * @param array<string, float>|null $systemLoad
     * @param array<string, mixed> $config
     */
    private function shouldOptimizeBasedOnLoad(?array $systemLoad, array $config): bool
    {
        if ($systemLoad === null) {
            return true;
        }

        $maxLoad1min = (float)($config['maxLoad1min'] ?? 2.0);
        $maxLoad5min = (float)($config['maxLoad5min'] ?? 1.5);
        $maxLoad15min = (float)($config['maxLoad15min'] ?? 1.0);
        return !($systemLoad['1min'] > $maxLoad1min ||
        $systemLoad['5min'] > $maxLoad5min ||
        $systemLoad['15min'] > $maxLoad15min);
    }
}
