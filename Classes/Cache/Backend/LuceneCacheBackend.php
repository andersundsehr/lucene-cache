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
use Weakbit\LuceneCache\Tokenizer\SingleSpaceTokenizer;
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
    protected string $directory;

    protected bool $compression = false;

    protected int $compressionLevel = -1;

    /**
     * Compression algorithm: 'zstd', 'gzdeflate', 'gzcompress'
     * Will auto-detect best available if not set.
     */
    protected string $compressionAlgorithm = '';

    /**
     * Minimum data size in bytes to apply compression.
     * Compressing very small data adds overhead without benefit.
     */
    protected int $compressionMinSize = 256;

    protected int $maxBufferedDocs = 1000;

    /** @var array<mixed> */
    protected array $buffer = [];

    protected int $execTime;

    protected bool $optimize = false;

    /**
     * @param array<mixed> $options
     * @throws AspectNotFoundException
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        Zend_Search_Lucene::setTermsPerQueryLimit(PHP_INT_MAX);
        $this->execTime = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * @inheritdoc
     * @param array<mixed> $tags
     * @throws Exception
     */
    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
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
        if ($this->buffer === []) {
            return;
        }

        $this->withAnalyzer(function (): void {
            $identifiers = array_keys($this->buffer);
            $index = $this->getIndex();
            $maxBufferedDocs = $index->getMaxBufferedDocs();
            $index->setMaxBufferedDocs(count($identifiers) + 10);

            // delete the current entry, lucene cant replace
            foreach ($identifiers as $identifier) {
                $query = new Zend_Search_Lucene_Search_Query_Term(
                    new Zend_Search_Lucene_Index_Term($identifier, 'identifier')
                );
                $hits = $index->find($query);
                foreach ($hits as $hit) {
                    $index->delete($hit->id);
                }
            }

            foreach ($this->buffer as $entryIdentifier => $item) {
                $data = $item['content'];
                assert(is_string($data));
                $expires = $item['lifetime'];
                $tags = implode(' ', $item['tags']);

                $data = $this->compress($data);

                $doc = new Document();
                $doc->addField(Field::keyword('identifier', $entryIdentifier));
                $doc->addField(Field::binary('content', $data));
                $doc->addField(Field::unStored('tags', $tags));
                $doc->addField(Field::keyword('lifetime', $expires));
                $index->addDocument($doc);
            }

            $index->commit();

            $index->setMaxBufferedDocs($maxBufferedDocs);

            $this->buffer = [];
        });
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

        GeneralUtility::mkdir_deep($this->cacheDirectory);

        $proxy = Zend_Search_Lucene::create($this->cacheDirectory);
        assert($proxy instanceof Zend_Search_Lucene_Proxy);
        return $proxy;
    }

    /**
     * Execute a callback with the SingleSpaceTokenizer, restoring the previous analyzer afterwards.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withAnalyzer(callable $callback): mixed
    {
        $previousAnalyzer = Zend_Search_Lucene_Analysis_Analyzer::getDefault();
        Zend_Search_Lucene_Analysis_Analyzer::setDefault(new SingleSpaceTokenizer());
        try {
            return $callback();
        } finally {
            Zend_Search_Lucene_Analysis_Analyzer::setDefault($previousAnalyzer);
        }
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
    public function get(string $entryIdentifier): false|string
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

        return $this->decompress($data);
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    public function has(string $entryIdentifier): bool
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
    public function remove(string $entryIdentifier): bool
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

        $index->commit();
    }

    /**
     * @inheritdoc
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     * @throws Zend_Search_Lucene_Search_QueryParserException
     */
    public function flushByTag(string $tag): void
    {
        $this->commit();
        $this->withAnalyzer(function () use ($tag): void {
            $query = new Zend_Search_Lucene_Search_Query_Term(
                new Zend_Search_Lucene_Index_Term($tag, 'tags')
            );

            $index = $this->getIndex();
            $hits = $index->find($query);

            foreach ($hits as $hit) {
                $index->delete($hit);
            }

            $index->commit();
        });
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
        $this->withAnalyzer(function () use ($tags): void {
            $escapedTags = array_map(static fn(string $tag): string => '"' . addslashes($tag) . '"', $tags);
            $queryStr = 'tags:(' . implode(' OR ', $escapedTags) . ')';

            $query = Zend_Search_Lucene_Search_QueryParser::parse($queryStr);
            $index = $this->getIndex();
            $hits = $index->find($query);

            foreach ($hits as $hit) {
                $index->delete($hit);
            }

            $index->commit();
        });
    }

    /**
     * @inheritdoc
     * @return array<string>
     * @throws Zend_Search_Exception
     */
    public function findIdentifiersByTag(string $tag): array
    {
        $this->commit();
        return $this->withAnalyzer(function () use ($tag): array {
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
        });
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
        $this->compression = $compression;

        if ($compression && $this->compressionAlgorithm === '') {
            // Auto-detect best available algorithm
            $this->compressionAlgorithm = $this->detectBestCompressionAlgorithm();
        }
    }

    /**
     * Set the compression algorithm: 'zstd', 'gzdeflate', 'gzcompress'
     */
    public function setCompressionAlgorithm(string $algorithm): void
    {
        $supported = ['zstd', 'gzdeflate', 'gzcompress'];
        if (!in_array($algorithm, $supported, true)) {
            throw new RuntimeException(sprintf(
                'Unsupported compression algorithm "%s". Supported: %s',
                $algorithm,
                implode(', ', $supported)
            ), 3064094861);
        }

        if ($algorithm === 'zstd' && !function_exists('zstd_compress')) {
            throw new RuntimeException('zstd compression requires ext-zstd', 1888340496);
        }

        $this->compressionAlgorithm = $algorithm;
    }

    /**
     * Set minimum data size for compression (in bytes).
     * Data smaller than this will not be compressed.
     */
    public function setCompressionMinSize(int $minSize): void
    {
        $this->compressionMinSize = max(0, $minSize);
    }

    /**
     * Detect the best available compression algorithm.
     */
    private function detectBestCompressionAlgorithm(): string
    {
        // zstd is fastest with excellent ratio
        if (function_exists('zstd_compress')) {
            return 'zstd';
        }

        // gzdeflate is slightly faster than gzcompress (no header)
        if (function_exists('gzdeflate')) {
            return 'gzdeflate';
        }

        if (function_exists('gzcompress')) {
            return 'gzcompress';
        }

        throw new RuntimeException('No compression algorithm available', 6634054114);
    }

    /**
     * Compress data using the configured algorithm.
     * Returns original data if compression is disabled or data is too small.
     */
    private function compress(string $data): string
    {
        if (!$this->compression) {
            return $data;
        }

        // Skip compression for small data - overhead not worth it
        if (strlen($data) < $this->compressionMinSize) {
            return $data;
        }

        $compressed = match ($this->compressionAlgorithm) {
            'zstd' => zstd_compress($data, $this->compressionLevel > 0 ? $this->compressionLevel : 3),
            'gzdeflate' => gzdeflate($data, $this->compressionLevel),
            'gzcompress' => gzcompress($data, $this->compressionLevel),
            default => throw new RuntimeException('No compression algorithm configured', 6585967389),
        };

        if ($compressed === false) {
            throw new RuntimeException('Compression failed', 3000545271);
        }

        // Prefix with algorithm identifier for decompression
        return match ($this->compressionAlgorithm) {
            'zstd' => "\x00Z" . $compressed,
            'gzdeflate' => "\x00D" . $compressed,
            'gzcompress' => "\x00C" . $compressed,
            default => $compressed,
        };
    }

    /**
     * Decompress data, auto-detecting the algorithm from the prefix.
     */
    private function decompress(string $data): string|false
    {
        // Check for compression prefix
        if (strlen($data) < 2 || $data[0] !== "\x00") {
            // No prefix - check if it looks like legacy gzcompress data
            // gzcompress starts with 0x78 (zlib header)
            if ($data !== '' && ord($data[0]) === 0x78) {
                $result = @gzuncompress($data);
                return $result !== false ? $result : $data;
            }

            return $data;
        }

        $algorithm = $data[1];
        $compressedData = substr($data, 2);

        return match ($algorithm) {
            'Z' => function_exists('zstd_uncompress')
                ? zstd_uncompress($compressedData)
                : throw new RuntimeException('zstd decompression requires ext-zstd', 7341513500),
            'D' => gzinflate($compressedData),
            'C' => gzuncompress($compressedData),
            default => $data, // Unknown format, return as-is
        };
    }

    /**
     * Optimize the Lucene index
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    public function optimize(): void
    {
        $index = $this->getIndex();
        $index->optimize();
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
