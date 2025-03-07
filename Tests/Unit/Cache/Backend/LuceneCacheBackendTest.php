<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Tests\Unit\Cache\Backend;

use ReflectionClass;
use ReflectionException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Cache\Exception\InvalidDataException;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend;
use Zend_Search_Exception;
use Zend_Search_Lucene_Exception;
use Zend_Search_Lucene_Search_QueryParserException;

class LuceneCacheBackendTest extends UnitTestCase
{
    protected LuceneCacheBackend $subject;

    protected CacheManager $cacheManager;

    protected bool $resetSingletonInstances = true;


    /**
     * @throws NoSuchCacheException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $path = realpath(__DIR__ . '/../../../../') . '/';
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            false,
            $path,
            $path . 'public',
            $path . 'var',
            $path . 'config',
            $path . 'typo3/index.php',
            'UNIX'
        );
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] ??= '0644';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] ??= '0755';


        $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->cacheManager->setCacheConfigurations([
            'lucene_cache_test' => [
                'backend' => LuceneCacheBackend::class,
                'options' => [
                    // Force the commit on every set
                    'maxBufferedDocs' => 0,
                    'compression' => true,
                ],
            ],
        ]);
        $GLOBALS['EXEC_TIME'] = 1741333418;
        $luceneCacheBackend = $this->cacheManager->getCache('lucene_cache_test')->getBackend();
        assert($luceneCacheBackend instanceof LuceneCacheBackend);
        $this->subject = $luceneCacheBackend;
    }

    protected function tearDown(): void
    {
        unset($this->subject);
        parent::tearDown();
    }

    /**
     * Test if the backend can set and retrieve cache entries.
     *
     * @@throws \Exception
     */
    public function testSetAndGetCacheEntries(): void
    {
        $entryIdentifier = 'uniqueIdentifier';
        $data = 'cachedData';
        $tags = ['aTag'];
        $lifetime = 3600;

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        static::assertSame($data, $this->subject->get($entryIdentifier));
    }

    /**
     * Test if the backend correctly removes cache entries.
     *
     * @throws \Exception
     */
    public function testRemoveCacheEntries(): void
    {
        $entryIdentifier = 'anotherUniqueIdentifier';
        $data = 'dataToCache';

        $this->subject->set($entryIdentifier, $data, [], 3600);
        static::assertTrue($this->subject->has($entryIdentifier));

        $this->subject->remove($entryIdentifier);
        static::assertFalse($this->subject->has($entryIdentifier));
    }

    /**
     * Test tag removal also removes associated data.
     *
     * @throws \Exception
     */
    public function testTagRemovalAlsoRemovesAssociatedData(): void
    {
        $entryIdentifier = 'taggedDataIdentifier';
        $data = 'dataWithTag';
        $tags = ['importantTag'];
        $lifetime = 3600;

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        static::assertSame($data, $this->subject->get($entryIdentifier));

        $entryIdentifier = 'taggedDataIdentifier2';
        $tags = ['importantTag2'];

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        static::assertSame($data, $this->subject->get($entryIdentifier));

        $this->subject->flushByTag('importantTag');

        static::assertFalse($this->subject->has('taggedDataIdentifier'), 'The data should not be found after removing the tag.');
        static::assertTrue($this->subject->has('taggedDataIdentifier2'), 'The data should be found after removing the tag.');
    }

    /**
     * Test flush removes all data
     *
     * @throws \Exception
     */
    public function testFlushRemovesAllData(): void
    {
        $entryIdentifier = 'testFlushRemovesAllData';
        $data = 'data';

        $this->subject->set($entryIdentifier, $data);
        self::assertTrue($this->subject->has($entryIdentifier));

        $this->subject->flush();

        self::assertFalse($this->subject->has($entryIdentifier));
    }

    /**
     * @throws Exception
     * @throws InvalidDataException
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     * @throws Zend_Search_Lucene_Search_QueryParserException
     */
    public function testTagsRemovalAlsoRemovesAssociatedData(): void
    {
        $this->subject->set('identifier1', 'whatever data', ['tag1', 'tag2'], 3600);
        $this->subject->set('identifier2', 'whatever data', ['tag2', 'tag3'], 3600);
        $this->subject->set('identifier3', 'whatever data', ['tag4', 'tag5'], 3600);

        $this->subject->flushByTags(['tag1', 'tag2']);
        static::assertFalse($this->subject->has('identifier1'), 'The data should not be found after removing the tag.');
        static::assertFalse($this->subject->has('identifier2'), 'The data should not be found after removing the tag.');
        static::assertTrue($this->subject->has('identifier3'), 'The data should be found after removing the tag.');
    }

    /**
     * @throws Exception
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Search_QueryParserException
     * @throws Zend_Search_Lucene_Exception
     * @throws InvalidDataException
     */
    public function testCommitingTwiceFindsOneActualDocument(): void
    {
        $noData = $this->subject->get('notthere');

        $this->subject->set('twicecheck', 'whatever data');
        // flush forces a commit from the ram buffer
        $this->subject->flushByTag('sometagthatdoesnotexist');
        static::assertFalse($noData);

        $this->subject->set('twicecheck', 'some other data');
        $this->subject->flushByTag('sometagthatdoesnotexist');

        $data = $this->subject->get('twicecheck');
        static::assertNotFalse($data);
        static::assertSame('some other data', $data);
    }

    /**
     * Test that the garbage collection function removes expired entries and keeps valid ones.
     *
     * @throws \Exception
     */
    public function testGarbageCollectionRemovesExpiredEntries(): void
    {
        $entryIdentifierPast = 'pastData';
        $entryIdentifierFuture = 'futureData';
        $dataPast = 'dataWithPastExpiry';
        $dataFuture = 'dataWithFutureExpiry';
        $futureLifetime = 3600; // 1 hour from now

        $this->subject->set($entryIdentifierPast, $dataPast, [], 1);
        $this->subject->set($entryIdentifierFuture, $dataFuture, [], $futureLifetime);

        $this->addTimeToSubject(300);

        $this->subject->collectGarbage();

        static::assertFalse($this->subject->has('pastData'), 'Past data should be removed by garbage collection.');
        static::assertTrue($this->subject->has($entryIdentifierFuture), 'Future data should remain after garbage collection.');
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     * @throws InvalidDataException
     * @throws Zend_Search_Lucene_Exception
     */
    public function testSetGetCacheEntriesWithLifetime(): void
    {
        $entryIdentifier = 'uniqueIdentifierLifetime';
        $data = 'cachedData';
        $tags = ['aTag'];
        $lifetime = 3600;

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        static::assertSame($data, $this->subject->get($entryIdentifier), 'Data should be available before it expires.');

        $this->addTimeToSubject(3605);
        static::assertFalse($this->subject->get($entryIdentifier), 'Data should be expired after the lifetime has passed.');
    }

    /**
     * @throws ReflectionException
     */
    private function addTimeToSubject(int $int): void
    {
        $reflection = new ReflectionClass($this->subject);
        $execTimeProperty = $reflection->getProperty('execTime');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $execTimeProperty->setAccessible(true);

        $previousValue = $execTimeProperty->getValue($this->subject);
        $execTimeProperty->setValue($this->subject, $previousValue + $int);
    }
}
