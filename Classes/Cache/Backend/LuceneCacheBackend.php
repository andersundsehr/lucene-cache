<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace Weakbit\LuceneCache\Cache\Backend;

use Exception;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\LuceneCache\Tokenizer\SingleSpaceTokenzier;
use Zend_Search_Exception;
use Zend_Search_Lucene;
use Zend_Search_Lucene_Analysis_Analyzer;
use Zend_Search_Lucene_Document as Document;
use Zend_Search_Lucene_Exception;
use Zend_Search_Lucene_Field as Field;
use Zend_Search_Lucene_Index_Term;
use Zend_Search_Lucene_Interface;
use Zend_Search_Lucene_Proxy;
use Zend_Search_Lucene_Search_Query_Range;
use Zend_Search_Lucene_Search_Query_Term;
use Zend_Search_Lucene_Search_Query_Wildcard;
use Zend_Search_Lucene_Search_QueryHit;
use Zend_Search_Lucene_Search_QueryParser;
use Zend_Search_Lucene_Search_QueryParserException;

class LuceneCacheBackend extends SimpleFileBackend implements TaggableBackendInterface
{
    protected Zend_Search_Lucene_Interface $index;

    protected string $directory;

    protected bool $compression = false;

    protected int $compressionLevel = -1;

    protected int $maxBufferedDocs = 1000;

    /** @var array<mixed> */
    protected array $buffer = [];

    protected int $execTime;

    protected bool $optimize = false;

    /**
     * @param array<mixed> $options
     * @throws AspectNotFoundException
     */
    public function __construct(string $context, array $options = [])
    {
        parent::__construct($context, $options);

        Zend_Search_Lucene::setTermsPerQueryLimit(PHP_INT_MAX);
        Zend_Search_Lucene_Analysis_Analyzer::setDefault(new SingleSpaceTokenzier());
        $this->execTime = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        if (!is_string($data)) {
            throw new Exception('lucene-cache only accepts string');
        }

        // Lifetime of this cache entry in seconds. If NULL is specified, the default lifetime is used. "0" means unlimited lifetime.
        if ($lifetime === null) {
            $lifetime = $this->defaultLifetime;
        }

        if ($lifetime === 0) {
            $lifetime = 9999999999;
        }

        if ($lifetime < 0) {
            return;
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
    }

    /**
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    protected function commit(): void
    {
        if (!$this->buffer) {
            return;
        }

        $identifiers = array_keys($this->buffer);
        $index = $this->getIndex();
        $maxBufferedDocs = $index->getMaxBufferedDocs();
        $index->setMaxBufferedDocs(count($identifiers) + 10);

        // delete the current entry, lucene cant replace
        foreach ($identifiers as $identifier) {
            $hits = $index->find('identifier:"' . $identifier . '"');
            foreach ($hits as $hit) {
                $index->delete($hit->id);
            }
        }

        foreach ($this->buffer as $entryIdentifier => $item) {
            $data = $item['content'];
            assert(is_string($data));
            $expires = $item['lifetime'];
            $tags = implode(' ', $item['tags']);

            if ($this->compression) {
                /** @noinspection PhpComposerExtensionStubsInspection */
                $data = gzcompress($data, $this->compressionLevel);
                if (false === $data) {
                    throw new RuntimeException('Could not compress data');
                }
            }

            $doc = new Document();
            $doc->addField(Field::keyword('identifier', $entryIdentifier));
            $doc->addField(Field::binary('content', $data));
            $doc->addField(Field::unStored('tags', $tags));
            $doc->addField(Field::unIndexed('lifetime', $expires));
            $index->addDocument($doc);
        }

        $index->commit();

        $index->setMaxBufferedDocs($maxBufferedDocs);

        $this->buffer = [];
    }

    /**
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     * @throws Exception
     */
    private function getIndex(): Zend_Search_Lucene_Proxy
    {
        if (is_dir($this->cacheDirectory)) {
            try {
                $proxy = Zend_Search_Lucene::open($this->cacheDirectory);
                assert($proxy instanceof Zend_Search_Lucene_Proxy);
                return $proxy;
            } catch (Zend_Search_Lucene_Exception | Zend_Search_Exception) {
            }
        }

        if (false === GeneralUtility::mkdir_deep($this->cacheDirectory)) {
            throw new Exception('Could not create temporary directory ' . $this->cacheDirectory);
        }

        $proxy = Zend_Search_Lucene::create($this->cacheDirectory);
        assert($proxy instanceof Zend_Search_Lucene_Proxy);
        return $proxy;
    }

    public function setMaxBufferedDocs(int $maxBufferedDocs): void
    {
        $this->maxBufferedDocs = abs($maxBufferedDocs);
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Lucene_Exception
     * @throws Exception
     */
    public function get($entryIdentifier): mixed
    {
        if (isset($this->buffer[$entryIdentifier]) && $this->buffer[$entryIdentifier]['lifetime'] > $this->execTime) {
            return $this->buffer[$entryIdentifier]['content'];
        }

        // before we search something, the index will commit internally
        $index = $this->getIndex();

        $query = new Zend_Search_Lucene_Search_Query_Term(
            new Zend_Search_Lucene_Index_Term($entryIdentifier, 'identifier')
        );
        $hits = $index->find($query);
        $hits = $this->filterByLifetime($index, $hits);

        $data = $hits === [] ? false : $hits[0]->getDocument()->getFieldValue('content');
        if (!$data) {
            return $data;
        }

        if ($this->compression) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            return gzuncompress($data);
        }

        return $data;
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    public function has($entryIdentifier): bool
    {
        if (isset($this->buffer[$entryIdentifier]) && $this->buffer[$entryIdentifier]['lifetime'] > $this->execTime) {
            return true;
        }

        $index = $this->getIndex();
        $query = new Zend_Search_Lucene_Search_Query_Term(
            new Zend_Search_Lucene_Index_Term($entryIdentifier, 'identifier')
        );
        $hits = $index->find($query);
        $hits = $this->filterByLifetime($index, $hits);
        return $hits !== [];
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    public function remove($entryIdentifier): bool
    {
        if (isset($this->buffer[$entryIdentifier])) {
            unset($this->buffer[$entryIdentifier]);
        }

        // before we search something, the index will commit internally
        $index = $this->getIndex();
        $query = new Zend_Search_Lucene_Search_Query_Term(
            new Zend_Search_Lucene_Index_Term($entryIdentifier, 'identifier')
        );

        $hits = $index->find($query);
        foreach ($hits as $hit) {
            $index->delete($hit->id);
        }

        $index->commit();

        return true;
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    public function flush(): void
    {
        $this->buffer = [];
        $index = $this->getIndex();
        Zend_Search_Lucene_Search_Query_Wildcard::setMinPrefixLength(0);
        $wildcard = new Zend_Search_Lucene_Search_Query_Wildcard(new Zend_Search_Lucene_Index_Term('*', 'identifier'));
        $hits = $index->find($wildcard);
        foreach ($hits as $hit) {
            $index->delete($hit->id);
        }

        if ($this->optimize) {
            $index->optimize();
        }
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     * @throws Zend_Search_Lucene_Search_QueryParserException
     */
    public function flushByTag($tag): void
    {
        $this->commit();
        $query = new Zend_Search_Lucene_Search_Query_Term(
            new Zend_Search_Lucene_Index_Term($tag, 'tags')
        );

        $index = $this->getIndex();
        $hits = $index->find($query);

        foreach ($hits as $hit) {
            $index->delete($hit);
        }

        $index->commit();
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     * @throws Zend_Search_Lucene_Search_QueryParserException
     */
    public function flushByTags(array $tags): void
    {
        $this->commit();
        $escapedTags = array_map(static fn($tag): string => '"' . addslashes($tag) . '"', $tags);
        $queryStr = 'tags:(' . implode(' OR ', $escapedTags) . ')';

        $query = Zend_Search_Lucene_Search_QueryParser::parse($queryStr);
        $index = $this->getIndex();
        $hits = $index->find($query);

        foreach ($hits as $hit) {
            $index->delete($hit);
        }

        $index->commit();
    }

    /**
     * @inheritdoc
     * @param string $tag
     * @return array<string>
     * @throws Zend_Search_Exception
     */
    public function findIdentifiersByTag($tag): array
    {
        $this->commit();
        $query = new Zend_Search_Lucene_Search_Query_Term(
            new Zend_Search_Lucene_Index_Term($tag, 'tags')
        );

        $index = $this->getIndex();
        $hits = $index->find($query);
        $hits = $this->filterByLifetime($index, $hits);

        $identifiers = [];
        foreach ($hits as $hit) {
            $identifiers[] = $hit->getDocument()->getFieldValue('identifier');
        }

        return $identifiers;
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    public function collectGarbage(): void
    {
        $this->commit();

        // get all documents from the past
        $query = new Zend_Search_Lucene_Search_Query_Range(
            null,
            new Zend_Search_Lucene_Index_Term($this->execTime, 'lifetime'),
            false
        );

        $index = $this->getIndex();
        $hits = $index->find($query);
        foreach ($hits as $hit) {
            $index->delete($hit->id);
        }

        $index->commit();
    }

    public function setCompression(bool $compression): void
    {
        if ($compression && !function_exists('gzcompress')) {
            throw new RuntimeException('Compression is not supported');
        }

        $this->compression = $compression;
    }

    public function setOptimize(bool $optimize): void
    {
        $this->optimize = $optimize;
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

    /**
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    private function shutdown(): void
    {
        $this->commit();
    }

    /**
     * @param array<Zend_Search_Lucene_Search_QueryHit> $hits
     * @return array<Zend_Search_Lucene_Search_QueryHit>
     * @throws Zend_Search_Lucene_Exception
     */
    private function filterByLifetime(Zend_Search_Lucene_Proxy $index, array $hits): array
    {
        $toRemove = [];
        $remainingHits = [];
        foreach ($hits as $hit) {
            $doc = $hit->getDocument();

            // Previously the field was not stored, so this invalidates the old cache
            try {
                $lifetime = (int)$doc->getFieldValue('lifetime');
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (Zend_Search_Lucene_Exception) {
                $toRemove[] = $hit->id;
                continue;
            }

            if ($lifetime < $this->execTime) {
                $toRemove[] = $hit->id;
            } else {
                $remainingHits[] = $hit;
            }
        }

        if ($toRemove !== []) {
            foreach ($toRemove as $docId) {
                $index->delete($docId);
            }

            $index->commit();
        }

        return $remainingHits;
    }
}
