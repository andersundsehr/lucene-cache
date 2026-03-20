<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\EventListener;

use Exception;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

abstract class AbstractExtensionConfigListener
{
    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration)
    {
    }

    /**
     * @return array<string, mixed>
     */
    protected function getExtensionConfiguration(): array
    {
        try {
            return $this->extensionConfiguration->get('lucene_cache') ?? [];
        } catch (Exception) {
            return [];
        }
    }
}
