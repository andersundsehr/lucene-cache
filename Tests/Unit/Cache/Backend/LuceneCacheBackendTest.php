<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Tests\Unit\Cache\Backend;

use Nimut\TestingFramework\TestCase\UnitTestCase;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend;

class LuceneCacheBackendTest extends UnitTestCase
{
    protected LuceneCacheBackend $subject;

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
        $GLOBALS['EXEC_TIME'] = time();
        $this->removeDirectory(Environment::getVarPath() . '/weakbit/');
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
        $this->subject = new LuceneCacheBackend('Testing', ['indexName' => 'testing']);

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
        $this->subject = new LuceneCacheBackend('Testing', ['indexName' => 'testing']);

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
        $this->subject = new LuceneCacheBackend('Testing', ['indexName' => 'testing']);

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
        $this->subject = new LuceneCacheBackend('Testing', ['indexName' => 'testing']);

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
        $this->subject = new LuceneCacheBackend('Testing', ['indexName' => 'testing']);
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
        $this->subject = new LuceneCacheBackend('Testing', ['indexName' => 'testing']);

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

    protected function removeDirectory(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        /** @var array<string> $content */
        $content = scandir($dir);
        foreach ($content as $item) {
            if ($item == '.') {
                continue;
            }

            if ($item == '..') {
                continue;
            }

            if (!$this->removeDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }
}
