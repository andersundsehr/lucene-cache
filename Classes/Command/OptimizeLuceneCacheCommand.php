<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace Weakbit\LuceneCache\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend;
use Weakbit\LuceneCache\Event\CacheOptimizationRequestedEvent;
use Zend_Search_Exception;
use Zend_Search_Lucene_Exception;

class OptimizeLuceneCacheCommand extends Command
{
    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this->setDescription('Optimize Lucene cache indices that are flagged for optimization');
        $this->setHelp('This command checks all Lucene cache backends for optimization flags and optimizes them if needed.');
    }

    /**
     * @throws NoSuchCacheException
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof CacheManager);
        $optimizedCaches = 0;
        $totalCaches = 0;

        // Get cache configurations from TYPO3_CONF_VARS instead
        $cacheConfigurations = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];

        // Use the event system to determine if optimization should happen
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcher::class);
        $event = new CacheOptimizationRequestedEvent();
        $eventDispatcher->dispatch($event);

        if (!$event->shouldOptimize()) {
            $output->writeln('Event system prevented optimization');
            return Command::FAILURE;
        }


        foreach ($cacheConfigurations as $identifier => $configuration) {
            // Check if this cache uses the LuceneCacheBackend
            if (
                isset($configuration['backend']) &&
                $configuration['backend'] === LuceneCacheBackend::class
            ) {
                $totalCaches++;

                // Check if cache exists before trying to get it
                if ($cacheManager->hasCache($identifier)) {
                    $cache = $cacheManager->getCache($identifier);
                    $backend = $cache->getBackend();

                    if ($backend instanceof LuceneCacheBackend) {
                        $backend->optimize();
                        $optimizedCaches++;
                    }
                } else {
                    $output->writeln(sprintf("<comment>Cache '%s' is configured but not available</comment>", $identifier), OutputInterface::VERBOSITY_VERBOSE);
                }
            }
        }

        if ($totalCaches === 0) {
            $output->writeln('<info>No Lucene cache backends found in configuration</info>');
        } else {
            $output->writeln(sprintf('<info>Processed %d Lucene cache(s), optimized %d</info>', $totalCaches, $optimizedCaches));
        }

        return Command::SUCCESS;
    }
}
