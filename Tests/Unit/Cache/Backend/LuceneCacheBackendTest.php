<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Tests\Unit\Cache\Backend;

use Nimut\TestingFramework\TestCase\UnitTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend;

class LuceneCacheBackendTest extends UnitTestCase
{
    protected LuceneCacheBackend $subject;

    protected CacheManager $cacheManager;

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
        $GLOBALS['EXEC_TIME'] = time();
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
     */
    public function testSetAndGetCacheEntries(): void
    {
        $entryIdentifier = 'uniqueIdentifier';
        $data = 'cachedData';
        $tags = ['aTag'];
        $lifetime = 3600;

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        $this->assertSame($data, $this->subject->get($entryIdentifier));
    }

    /**
     * Test if the backend correctly removes cache entries.
     */
    public function testRemoveCacheEntries(): void
    {
        $entryIdentifier = 'anotherUniqueIdentifier';
        $data = 'dataToCache';

        $this->subject->set($entryIdentifier, $data, [], 3600);
        $this->assertTrue($this->subject->has($entryIdentifier));

        $this->subject->remove($entryIdentifier);
        $this->assertFalse($this->subject->has($entryIdentifier));
    }

    /**
     * Test tag removal also removes associated data.
     */
    public function testTagRemovalAlsoRemovesAssociatedData(): void
    {
        $entryIdentifier = 'taggedDataIdentifier';
        $data = 'dataWithTag';
        $tags = ['importantTag'];
        $lifetime = 3600;

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        $this->assertSame($data, $this->subject->get($entryIdentifier));

        $entryIdentifier = 'taggedDataIdentifier2';
        $tags = ['importantTag2'];

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        $this->assertSame($data, $this->subject->get($entryIdentifier));

        $this->subject->flushByTag('importantTag');

        $this->assertFalse($this->subject->has('taggedDataIdentifier'), 'The data should not be found after removing the tag.');
        $this->assertTrue($this->subject->has('taggedDataIdentifier2'), 'The data should be found after removing the tag.');
    }

    public function testTagsRemovalAlsoRemovesAssociatedData(): void
    {
        $this->subject->set('identifier1', 'whatever data', ['tag1', 'tag2'], 3600);
        $this->subject->set('identifier2', 'whatever data', ['tag2', 'tag3'], 3600);
        $this->subject->set('identifier3', 'whatever data', ['tag4', 'tag5'], 3600);

        $this->subject->flushByTags(['tag1', 'tag2']);
        $this->assertFalse($this->subject->has('identifier1'), 'The data should not be found after removing the tag.');
        $this->assertFalse($this->subject->has('identifier2'), 'The data should not be found after removing the tag.');
        $this->assertTrue($this->subject->has('identifier3'), 'The data should be found after removing the tag.');
    }

    public function testCommitingTwiceFindsOneActualDocument(): void
    {
        $noData = $this->subject->get('notthere');

        $this->subject->set('twicecheck', 'whatever data');
        // flush forces a commit from the ram buffer
        $this->subject->flushByTag('sometagthatdoesnotexist');
        $this->assertFalse($noData);

        $this->subject->set('twicecheck', 'some other data');
        $this->subject->flushByTag('sometagthatdoesnotexist');

        $data = $this->subject->get('twicecheck');
        $this->assertNotFalse($data);
        $this->assertSame('some other data', $data);
    }

    /**
     * Test that the garbage collection function removes expired entries and keeps valid ones.
     */
    public function testGarbageCollectionRemovesExpiredEntries(): void
    {
        $entryIdentifierPast = 'pastData';
        $entryIdentifierFuture = 'futureData';
        $dataPast = 'dataWithPastExpiry';
        $dataFuture = 'dataWithFutureExpiry';
        $expiredLifetime = -3600;  // 1 hour ago
        $futureLifetime = 3600; // 1 hour from now

        for ($i = 0; $i < 1030; $i++) {
            $this->subject->set($entryIdentifierPast . $i, $dataPast, [], $expiredLifetime - $i);
        }

        $this->subject->set($entryIdentifierFuture, $dataFuture, [], $futureLifetime);

        $this->subject->collectGarbage();

        $this->assertFalse($this->subject->has('pastData0'), 'Past data should be removed by garbage collection.');
        $this->assertTrue($this->subject->has($entryIdentifierFuture), 'Future data should remain after garbage collection.');
    }

    public function testSetGetCacheEntriesWithLifetime(): void
    {
        $entryIdentifier = 'uniqueIdentifierLifetime';
        $data = 'cachedData';
        $tags = ['aTag'];
        $lifetime = 3600;

        $reflection = new \ReflectionClass($this->subject);
        $execTimeProperty = $reflection->getProperty('execTime');
        $execTimeProperty->setAccessible(true);

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);

        $this->assertSame($data, $this->subject->get($entryIdentifier), 'Data should be available before it expires.');

        $oldTime = $execTimeProperty->getValue($this->subject);
        $execTimeProperty->setValue($this->subject, $oldTime + $lifetime + 1);

        $this->assertFalse($this->subject->get($entryIdentifier), 'Data should be expired after the lifetime has passed.');
    }
}
