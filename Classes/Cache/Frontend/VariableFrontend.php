<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Cache\Frontend;

use TYPO3\CMS\Core\Cache\Backend\BackendInterface;

use function trigger_deprecation;

class VariableFrontend extends \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend
{
    public function __construct($identifier, BackendInterface $backend)
    {
        parent::__construct($identifier, $backend);
        trigger_deprecation('weakbit/lucene-cache', '2.0.3', 'The class %s is deprecated and will be removed in a future version.', self::class);
    }
}
