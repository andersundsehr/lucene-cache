<?php
/** @noinspection PhpUnused */
/** @noinspection PhpDocMissingThrowsInspection */

declare(strict_types=1);

namespace Weakbit\LuceneCache\Cache\Backend;

use Exception;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\AbstractBackend;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\LuceneCache\Tokenizer\SingleSpaceTokenzier;
use Zend_Search_Lucene;
use Zend_Search_Lucene_Analysis_Analyzer;
use Zend_Search_Lucene_Document as Document;
use Zend_Search_Lucene_Field as Field;
use Zend_Search_Lucene_Index_Term;
use Zend_Search_Lucene_Proxy;
use Zend_Search_Lucene_Search_Query_MultiTerm;
use Zend_Search_Lucene_Search_Query_Range;
use Zend_Search_Lucene_Search_QueryParser;

class LuceneCacheBackend extends AbstractBackend implements TaggableBackendInterface
{
    protected Zend_Search_Lucene_Proxy $index;
    protected string $directory;
    protected bool $compression = false;
    protected int $compressionLevel = -1;
    protected string $indexName;
    protected int $maxBufferedDocs = 1000;
    /** @var array<mixed> */
    protected array $buffer = [];

    /**
     * @param string $context
     * @param array<mixed> $options
     */
    public function __construct(string $context, array $options = array())
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
    }

    public function __destruct()
    {
        if (!$this->buffer) {
            return;
        }

        // the index might be destructed already, we reconstruct it therefore
        $this->index = Zend_Search_Lucene::open($this->directory);
        $this->commit();
        unset($this->index);
    }

    /**
     * @param string $entryIdentifier
     * @param string $data
     * @param array<string> $tags
     * @param int $lifetime
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        if (false === is_string($data)) {
            throw new Exception('lucene-cache only accepts string');
        }

        if ($lifetime === null) {
            $lifetime = $this->defaultLifetime;
        }

        $expires = $GLOBALS['EXEC_TIME'] + $lifetime;

        $doc = [
            'lifetime' => $expires,
            'content' => $data,
            'tags' => $tags,
        ];

        $this->buffer[$entryIdentifier] = $doc;
        if (count($this->buffer) > $this->maxBufferedDocs) {
            $this->commit();
        }
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
        $hits = $this->index->find('identifier:"' . $entryIdentifier . '"');

        $data = empty($hits) ? false : $hits[0]->content;
        if (!$data) {
            return $data;
        }

        if ($this->compression) {
            $data = gzuncompress($data);
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
        $hits = $this->index->find('identifier:"' . $entryIdentifier . '"');
        return !empty($hits);
    }

    /**
     * @param string $entryIdentifier
     */
    public function remove($entryIdentifier): bool
    {
        if (isset($this->buffer[$entryIdentifier])) {
            unset($this->buffer[$entryIdentifier]);
        }
        // before we search something, the index will commit internally
        $hits = $this->index->find('identifier:"' . $entryIdentifier . '"');
        foreach ($hits as $hit) {
            $this->index->delete($hit->id);
        }
        return true;
    }

    public function flush(): void
    {
        $this->buffer = [];
        unset($this->index);
        Zend_Search_Lucene::create($this->directory);
        $this->index = Zend_Search_Lucene::open($this->directory);
    }

    /**
     * @var string $tag
     */
    public function flushByTag($tag): void
    {
        $this->commit();

        $query = Zend_Search_Lucene_Search_QueryParser::parse('tags:"' . addslashes($tag) . '"');
        $start = microtime(true);
        $hits = $this->index->find($query);
        $commit = false;
        foreach ($hits as $hit) {
            $this->index->delete($hit);
            $commit = true;
        }
        file_put_contents('timings', microtime(true) - $start.PHP_EOL, FILE_APPEND);
        // TODO does it receive deleted documents? because if not we could think to mark it dirty here and commit before the next search ( i think it does this anyway ) so mass clearings would perform much better
        // TODO merge factor ggfs vor "unsrem" commit falls er groß ist hochsetzen und danach stark reduzieren? laut docs müsste das dann schneller indexen.
        // TODO tests wie im core!
// TODO$ # also nun noch den feeder checken wie man den schneller bekommt mit kleinen mengen die sizes erhöhen/verringern optimizes zwischendrin oder queue vergrößeren was auch immer, gab es ein optimize  ^C
// 28.4. mit 50k entries (ohne tests zuletzt kamen bei eigenen prüfungen keine probleme mehr)
// wkbdef Feeding 50000 took 155.46377301216 seconds
// wkbdef 1000 Identifiers lookup took 0.26504993438721 seconds
// wkbdef 100 Tag flushes took 164.84662604332 seconds
        // -------------
// wkbmsgpack Feeding 50000 took 446.53987812996 seconds <- doof
// wkbmsgpack 1000 Identifiers lookup took 0.37302899360657 seconds <- ok
// wkbmsgpack 100 Tag flushes took 0.35814690589905 seconds <- das wäre perfekt

        if ($commit) {
            //$this->index->commit();
        }
    }

    /**
     * @var string $tag
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
        // get all documents from the past
        $query = new Zend_Search_Lucene_Search_Query_Range(
            new Zend_Search_Lucene_Index_Term(0, 'lifetime'),
            new Zend_Search_Lucene_Index_Term($GLOBALS['EXEC_TIME'], 'lifetime'),
            true
        );

        $hits = $this->index->find($query);
        foreach ($hits as $hit) {
            if ($hit->lifetime < time()) {
                $this->index->delete($hit->id);
            }
        }
        $this->index->commit();
        $this->index->optimize();
    }

    /**
     * @param bool $compression
     */
    public function setCompression(bool $compression): void
    {
        $this->compression = $compression;
    }

    /**
     * @param int $compressionLevel -1 to 9: Compression level
     */
    public function setCompressionLevel(int $compressionLevel): void
    {
        if ($compressionLevel >= -1 && $compressionLevel <= 9) {
            $this->compressionLevel = $compressionLevel;
        }
    }

    public function setMaxBufferedDocs(int $maxBufferedDocs): void
    {
        $this->maxBufferedDocs = abs($maxBufferedDocs);
    }

    public function setIndexName(string $indexName): void
    {
        $this->indexName = filter_var($indexName, FILTER_CALLBACK, ['options' => function($value) {
            $value = (string)$value;
            return preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
        }]);
    }

    protected function commit(): void
    {
        if (!$this->buffer) {
            return;
        }
        $identifiers = array_keys($this->buffer);

        $query = new Zend_Search_Lucene_Search_Query_MultiTerm();

        foreach ($identifiers as $identifier) {
            $term = new Zend_Search_Lucene_Index_Term($identifier, 'identifier');
            $query->addTerm($term, false);
        }

        $hits = $this->index->find($query);
        foreach ($hits as $hit) {
            $this->index->delete($hit);
        }

        foreach ($this->buffer as $entryIdentifier => $item) {
            $data = $item['content'];
            $expires = $item['lifetime'];
            $tags = implode(' ', $item['tags']);

            if ($this->compression) {
                $data = gzcompress($data, $this->compressionLevel);
            }

            $doc = new Document();
            $doc->addField(Field::keyword('identifier', $entryIdentifier));
            $doc->addField(Field::binary('content', $data));
            $doc->addField(Field::unStored('tags', $tags));
            $doc->addField(Field::unIndexed('lifetime', $expires));

            $this->index->addDocument($doc);
        }
        $this->index->commit();

        $this->buffer = [];
    }
}
