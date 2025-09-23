<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\EventListener;

use Exception;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractExtensionConfigListener
{
    /**
     * @return array<string, mixed>
     */
    protected function getExtensionConfiguration(): array
    {
        try {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            return $extensionConfiguration->get('lucene_cache') ?? [];
        } catch (Exception) {
            return [];
        }
    }
}
