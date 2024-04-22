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
use Zend_Search_Lucene;
use Zend_Search_Lucene_Document as Document;
use Zend_Search_Lucene_Field as Field;
use Zend_Search_Lucene_Index_Term;
use Zend_Search_Lucene_Proxy;
use Zend_Search_Lucene_Search_Query_Range;

class LuceneCacheBackend extends AbstractBackend implements TaggableBackendInterface
{
    protected Zend_Search_Lucene_Proxy $index;
    protected string $directory;
    protected bool $compression = false;
    protected int $compressionLevel = -1;
    protected string $indexName;

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
        $this->remove($entryIdentifier);
        if ($this->compression) {
            $data = gzcompress($data, $this->compressionLevel);
        }
        $doc = new Document();
        $doc->addField(Field::keyword('identifier', $entryIdentifier));
        $doc->addField(Field::binary('content', $data));
        $doc->addField(Field::text('tags', implode(',', $tags)));
        $doc->addField(Field::unIndexed('lifetime', $expires));

        $this->index->addDocument($doc);
        $this->index->commit();
    }

    /**
     * @param string $entryIdentifier
     */
    public function get($entryIdentifier): mixed
    {
        $hits = $this->index->find('identifier:' . $entryIdentifier);

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
        $hits = $this->index->find('identifier:' . $entryIdentifier);
        return !empty($hits);
    }

    /**
     * @param string $entryIdentifier
     */
    public function remove($entryIdentifier): bool
    {
        $hits = $this->index->find('identifier:' . $entryIdentifier);
        foreach ($hits as $hit) {
            $this->index->delete($hit->id);
        }
        $this->index->commit();
        return true;
    }

    public function flush(): void
    {
        unset($this->index);
        Zend_Search_Lucene::create($this->directory);
        $this->index = Zend_Search_Lucene::open($this->directory);
    }

    /**
     * @var string $tag
     */
    public function flushByTag($tag): void
    {
        $hits = $this->index->find('tags:' . $tag);
        foreach ($hits as $hit) {
            $this->index->delete($hit->id);
        }
        $this->index->commit();
    }

    /**
     * @var string $tag
     */
    public function findIdentifiersByTag($tag): array
    {
        $hits = $this->index->find('tags:' . $tag);
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

    public function setIndexName(string $indexName): void
    {
        $this->indexName = filter_var($indexName, FILTER_CALLBACK, ['options' => function($value) {
            $value = (string)$value;
            return preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
        }]);
    }
}
