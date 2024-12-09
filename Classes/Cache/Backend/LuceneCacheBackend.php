<?php

/** @noinspection PhpUnused */

/** @noinspection PhpDocMissingThrowsInspection */

declare(strict_types=1);

namespace Weakbit\LuceneCache\Cache\Backend;

use Exception;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Context\Context;
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
use Zend_Search_Lucene_Search_Query_Range;
use Zend_Search_Lucene_Search_Query_Wildcard;
use Zend_Search_Lucene_Search_QueryParser;

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

    /**
     * @param array<mixed> $options
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
    }

    protected function commit(): void
    {
        if (!$this->buffer) {
            return;
        }

        $identifiers = array_keys($this->buffer);
        $index = $this->getIndex();
        $maxBufferedDocks = $index->getMaxBufferedDocs();
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

            $index->addDocument($doc);
        }

        $index->commit();

        $index->setMaxBufferedDocs($maxBufferedDocks);

        $this->buffer = [];
    }

    private function getIndex(): Zend_Search_Lucene_Interface
    {
        if (is_dir($this->cacheDirectory)) {
            try {
                return Zend_Search_Lucene::open($this->cacheDirectory);
            } catch (Zend_Search_Lucene_Exception | Zend_Search_Exception) {
            }
        }

        if (false === GeneralUtility::mkdir_deep($this->cacheDirectory)) {
            throw new Exception('Could not create temporary directory ' . $this->cacheDirectory);
        }

        return Zend_Search_Lucene::create($this->cacheDirectory);
    }

    public function setMaxBufferedDocs(int $maxBufferedDocs): void
    {
        $this->maxBufferedDocs = abs($maxBufferedDocs);
    }


    /**
     * @param string $entryIdentifier
     */
    public function get($entryIdentifier): mixed
    {
        if (isset($this->buffer[$entryIdentifier])) {
            return $this->buffer[$entryIdentifier]['content'];
        }

        // before we search something, the index will commit internally
        $hits = $this->getIndex()->find('identifier:"' . $entryIdentifier . '"');

        $data = $hits === [] ? false : $hits[0]->content;
        if (!$data) {
            return $data;
        }

        if ($this->compression) {
            return gzuncompress($data);
        }

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
        $hits = $this->getIndex()->find('identifier:"' . $entryIdentifier . '"');
        return $hits !== [];
    }

    /**
     * @param string $entryIdentifier
     */
    public function remove($entryIdentifier): bool
    {
        if (isset($this->buffer[$entryIdentifier])) {
            unset($this->buffer[$entryIdentifier]);
        }

        $index = $this->getIndex();
        // before we search something, the index will commit internally
        $hits = $index->find('identifier:"' . $entryIdentifier . '"');
        foreach ($hits as $hit) {
            $index->delete($hit->id);
        }

        return true;
    }

    public function flush(): void
    {
        $this->buffer = [];
        $index = $this->getIndex();
        Zend_Search_Lucene_Search_Query_Wildcard::setMinPrefixLength(0);
        $wildcard = new Zend_Search_Lucene_Search_Query_Wildcard(new Zend_Search_Lucene_Index_Term('identifier:*'));
        $hits = $index->find($wildcard);
        foreach ($hits as $hit) {
            $index->delete($hit->id);
        }

        $index->optimize();
    }

    /**
     * @param string $tag
     */
    public function flushByTag($tag): void
    {
        $this->commit();
        $query = Zend_Search_Lucene_Search_QueryParser::parse('tags:"' . addslashes($tag) . '"');
        $index = $this->getIndex();
        $hits = $index->find($query);
        foreach ($hits as $hit) {
            $index->delete($hit);
        }
    }

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
    }

    /**
     * @param string $tag
     * @return array<string>
     */
    public function findIdentifiersByTag($tag): array
    {
        $this->commit();
        $query = Zend_Search_Lucene_Search_QueryParser::parse('tags:"' . addslashes($tag) . '"');
        $hits = $this->getIndex()->find($query);
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

        $index = $this->getIndex();
        $hits = $index->find($query);
        foreach ($hits as $hit) {
            $index->delete($hit->id);
        }

        $index->commit();
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

    public function shutdown(): void
    {
        $this->commit();
    }
}
