<?php

/** @noinspection PhpUnused */

/** @noinspection PhpDocMissingThrowsInspection */

declare(strict_types=1);

namespace Weakbit\LuceneCache\Cache\Backend;

use AUS\AusMetricsExporter\Service\CollectorService;
use RuntimeException;
use TYPO3\CMS\Core\Context\Context;
use Exception;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\AbstractBackend;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\LuceneCache\Tokenizer\SingleSpaceTokenzier;
use Zend_Search_Lucene;
use Zend_Search_Lucene_Analysis_Analyzer;
use Zend_Search_Lucene_Document as Document;
use Zend_Search_Lucene_Field as Field;
use Zend_Search_Lucene_Index_Term;
use Zend_Search_Lucene_Interface;
use Zend_Search_Lucene_Search_Query_Range;
use Zend_Search_Lucene_Search_QueryParser;

class LuceneCacheBackend extends AbstractBackend implements TaggableBackendInterface
{
    protected Zend_Search_Lucene_Interface $index;

    protected string $directory;

    protected bool $compression = false;

    protected int $compressionLevel = -1;

    protected string $indexName;

    protected int $maxBufferedDocs = 1000;

    /** @var array<mixed> */
    protected array $buffer = [];

    protected int $execTime;

    protected bool $collect = false;

    protected CollectorService $collectorService;

    /**
     * @param array<mixed> $options
     */
    public function __construct(string $context, array $options = [])
    {
        parent::__construct($context, $options);

        $this->directory = $options['directory'] ?? GeneralUtility::getFileAbsFileName(Environment::getVarPath() . '/weakbit/lucene-cache/' . $context . '/' . $this->indexName);
        if (is_dir($this->directory)) {
            $this->index = Zend_Search_Lucene::open($this->directory);
        } else {
            if (false === GeneralUtility::mkdir_deep($this->directory)) {
                throw new Exception('Could not create temporary directory ' . $this->directory);
            }

            $this->index = Zend_Search_Lucene::create($this->directory);
        }

        Zend_Search_Lucene_Analysis_Analyzer::setDefault(new SingleSpaceTokenzier());
        $this->execTime = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
        register_shutdown_function([$this, 'shutdown']);

        $context = \TYPO3\CMS\Core\Core\Environment::getContext();
        if (!$context->isTesting() && ExtensionManagementUtility::isLoaded('aus_metrics_exporter')) {
            $this->collect = true;
            $this->collectorService = GeneralUtility::makeInstance(CollectorService::class);
        }
    }

    /**
     * @param string $entryIdentifier
     * @param string $data
     * @param array<string> $tags
     * @param int $lifetime
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        if (!is_string($data)) {
            throw new Exception('lucene-cache only accepts string');
        }

        if ($lifetime === null) {
            $lifetime = $this->defaultLifetime;
        }

        $expires = $this->execTime + $lifetime;

        $doc = [
            'lifetime' => (string)$expires,
            'content' => $data,
            'tags' => $tags,
        ];

        $this->buffer[$entryIdentifier] = $doc;
        if (count($this->buffer) > $this->maxBufferedDocs) {
            $this->commit();
        }

        $this->collect('inserts');
    }

    /**
     * @param string $entryIdentifier
     */
    public function get($entryIdentifier): mixed
    {
        if (isset($this->buffer[$entryIdentifier])) {
            $this->collect('hits');
            return $this->buffer[$entryIdentifier]['content'];
        }

        // before we search something, the index will commit internally
        $hits = $this->index->find('identifier:"' . $entryIdentifier . '"');

        $data = $hits === [] ? false : $hits[0]->content;
        if (!$data) {
            return $data;
        }

        if ($this->compression) {
            return gzuncompress($data);
        }
        $this->collect('hits');
        return $data;
    }

    /**
     * @param string $entryIdentifier
     */
    public function has($entryIdentifier): bool
    {
        if (isset($this->buffer[$entryIdentifier])) {
            return true;
        }

        // before we search something, the index will commit internally
        $hits = $this->index->find('identifier:"' . $entryIdentifier . '"');
        return $hits !== [];
    }

    /**
     * @param string $entryIdentifier
     */
    public function remove($entryIdentifier): bool
    {
        if (isset($this->buffer[$entryIdentifier])) {
            $this->collect('removes');
            unset($this->buffer[$entryIdentifier]);
        }

        // before we search something, the index will commit internally
        $hits = $this->index->find('identifier:"' . $entryIdentifier . '"');
        foreach ($hits as $hit) {
            $this->collect('removes');
            $this->index->delete($hit->id);
        }

        return true;
    }

    public function flush(): void
    {
        $this->collect('flushes');
        $this->buffer = [];
        unset($this->index);
        Zend_Search_Lucene::create($this->directory);
        $this->index = Zend_Search_Lucene::open($this->directory);
    }

    /**
     * @param string $tag
     */
    public function flushByTag($tag): void
    {
        $this->commit();
        $this->collect('flushes-by-tag');
        $query = Zend_Search_Lucene_Search_QueryParser::parse('tags:"' . addslashes($tag) . '"');
        $hits = $this->index->find($query);
        foreach ($hits as $hit) {
            $this->index->delete($hit);
        }
    }

    public function flushByTags(array $tags): void
    {
        $this->commit();
        $this->collect('flushes-by-tags');
        $escapedTags = array_map(static fn($tag): string => '"' . addslashes($tag) . '"', $tags);
        $queryStr = 'tags:(' . implode(' OR ', $escapedTags) . ')';

        $query = Zend_Search_Lucene_Search_QueryParser::parse($queryStr);
        $hits = $this->index->find($query);

        foreach ($hits as $hit) {
            $this->index->delete($hit);
        }
    }

    /**
     * @param string $tag
     * @return array<string>
     */
    public function findIdentifiersByTag($tag): array
    {
        $this->commit();
        $query = Zend_Search_Lucene_Search_QueryParser::parse('tags:"' . addslashes($tag) . '"');
        $hits = $this->index->find($query);
        $identifiers = [];
        foreach ($hits as $hit) {
            $identifiers[] = $hit->identifier;
        }

        return $identifiers;
    }

    public function collectGarbage(): void
    {
        $this->commit();
        // get all documents from the past
        $query = new Zend_Search_Lucene_Search_Query_Range(
            null,
            new Zend_Search_Lucene_Index_Term($this->execTime, 'lifetime'),
            false
        );

        $hits = $this->index->find($query);
        foreach ($hits as $hit) {
            $this->index->delete($hit->id);
        }

        $this->index->commit();
    }

    public function setCompression(bool $compression): void
    {
        $this->compression = $compression;
    }

    /**
     * @param int $compressionLevel -1 to 9: Compression level
     */
    public function setCompressionLevel(int $compressionLevel): void
    {
        if ($compressionLevel < -1) {
            return;
        }

        if ($compressionLevel > 9) {
            return;
        }

        $this->compressionLevel = $compressionLevel;
    }

    public function setMaxBufferedDocs(int $maxBufferedDocs): void
    {
        $this->maxBufferedDocs = abs($maxBufferedDocs);
    }

    public function setIndexName(string $indexName): void
    {
        $this->indexName = filter_var($indexName, FILTER_CALLBACK, [
            'options' => static function ($value): ?string {
                $value = (string)$value;
                return preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
            },
        ]);
    }

    protected function commit(): void
    {
        if (!$this->buffer) {
            return;
        }

        $identifiers = array_keys($this->buffer);

        $maxBufferedDocks = $this->index->getMaxBufferedDocs();
        $this->index->setMaxBufferedDocs(count($identifiers) + 10);

        // delete the current entry, lucene cant replace
        foreach ($identifiers as $identifier) {
            $hits = $this->index->find('identifier:"' . $identifier . '"');
            foreach ($hits as $hit) {
                $this->index->delete($hit->id);
            }
        }

        foreach ($this->buffer as $entryIdentifier => $item) {
            $data = $item['content'];
            assert(is_string($data));
            $expires = $item['lifetime'];
            $tags = implode(' ', $item['tags']);

            if ($this->compression) {
                $data = gzcompress($data, $this->compressionLevel);
                if (false === $data) {
                    throw new RuntimeException('Could not compress data');
                }
            }

            $doc = new Document();
            $doc->addField(Field::keyword('identifier', $entryIdentifier));
            $doc->addField(Field::binary('content', $data));
            $doc->addField(Field::unStored('tags', $tags));
            $doc->addField(Field::unStored('lifetime', $expires));

            $this->index->addDocument($doc);
        }

        $this->index->commit();

        $this->index->setMaxBufferedDocs($maxBufferedDocks);

        $this->buffer = [];
    }

    public function shutdown(): void
    {
        $this->commit();
    }

    protected function collect(string $string): void
    {
        if ($this->collect) {
            $this->collectorService->collect('weakbit_lucene-cache_' . $string, '1');
        }
    }
}
