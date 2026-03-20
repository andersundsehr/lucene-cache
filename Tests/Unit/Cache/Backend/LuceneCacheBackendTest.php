<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Tests\Unit\Cache\Backend;

use stdClass;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame($data, $this->subject->get($entryIdentifier));
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
        self::assertTrue($this->subject->has($entryIdentifier));

        $this->subject->remove($entryIdentifier);
        self::assertFalse($this->subject->has($entryIdentifier));
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
        self::assertSame($data, $this->subject->get($entryIdentifier));

        $entryIdentifier = 'taggedDataIdentifier2';
        $tags = ['importantTag2'];

        $this->subject->set($entryIdentifier, $data, $tags, $lifetime);
        self::assertSame($data, $this->subject->get($entryIdentifier));

        $this->subject->flushByTag('importantTag');

        self::assertFalse($this->subject->has('taggedDataIdentifier'), 'The data should not be found after removing the tag.');
        self::assertTrue($this->subject->has('taggedDataIdentifier2'), 'The data should be found after removing the tag.');
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
        $this->subject->set('identifier1', 'whatever data', ['tag1', 'tag2', 'tag-6', 'tag_7'], 3600);
        $this->subject->set('identifier2', 'whatever data', ['tag2', 'tag3', 'tag-8', 'tag_9'], 3600);
        $this->subject->set('identifier3', 'whatever data', ['tag4', 'tag5', 'tag-10', 'tag_11'], 3600);

        $this->subject->flushByTags(['tag1', 'tag2', 'tag-6', 'tag_7']);
        self::assertFalse($this->subject->has('identifier1'), 'The data should not be found after removing the tag.');
        self::assertFalse($this->subject->has('identifier2'), 'The data should not be found after removing the tag.');
        self::assertTrue($this->subject->has('identifier3'), 'The data should be found after removing the tag.');
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
        self::assertFalse($noData);

        $this->subject->set('twicecheck', 'some other data');
        $this->subject->flushByTag('sometagthatdoesnotexist');

        $data = $this->subject->get('twicecheck');
        self::assertNotFalse($data);
        self::assertSame('some other data', $data);
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

        self::assertFalse($this->subject->has('pastData'), 'Past data should be removed by garbage collection.');
        self::assertTrue($this->subject->has($entryIdentifierFuture), 'Future data should remain after garbage collection.');
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
        self::assertSame($data, $this->subject->get($entryIdentifier), 'Data should be available before it expires.');

        $this->addTimeToSubject(3605);
        self::assertFalse($this->subject->get($entryIdentifier), 'Data should be expired after the lifetime has passed.');
    }

    /**
     * @throws ReflectionException
     */
    private function addTimeToSubject(int $int): void
    {
        $reflection = new ReflectionClass($this->subject);
        $execTimeProperty = $reflection->getProperty('execTime');

        $previousValue = $execTimeProperty->getValue($this->subject);
        $execTimeProperty->setValue($this->subject, $previousValue + $int);
    }

    /**
     * @return array<string, array{algorithm: string, requiredFunction: string}>
     */
    public static function compressionAlgorithmProvider(): array
    {
        return [
            'zstd' => [
                'algorithm' => 'zstd',
                'requiredFunction' => 'zstd_compress',
            ],
            'gzdeflate' => [
                'algorithm' => 'gzdeflate',
                'requiredFunction' => 'gzdeflate',
            ],
            'gzcompress' => [
                'algorithm' => 'gzcompress',
                'requiredFunction' => 'gzcompress',
            ],
        ];
    }

    /**
     * @throws NoSuchCacheException
     * @throws Exception
     * @throws InvalidDataException
     */
    #[DataProvider('compressionAlgorithmProvider')]
    public function testCompressionAlgorithms(string $algorithm, string $requiredFunction): void
    {
        if (!function_exists($requiredFunction)) {
            self::markTestSkipped(sprintf(
                'Compression algorithm "%s" requires function "%s" which is not available.',
                $algorithm,
                $requiredFunction
            ));
        }

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->setCacheConfigurations([
            'lucene_cache_compression_test_' . $algorithm => [
                'backend' => LuceneCacheBackend::class,
                'options' => [
                    'maxBufferedDocs' => 0,
                    'compression' => true,
                    'compressionAlgorithm' => $algorithm,
                    'compressionMinSize' => 1, // Compress even small data for testing
                ],
            ],
        ]);

        $cache = $cacheManager->getCache('lucene_cache_compression_test_' . $algorithm);
        $backend = $cache->getBackend();
        assert($backend instanceof LuceneCacheBackend);

        $entryIdentifier = 'compression_test_' . $algorithm;
        // Use data larger than default minSize to ensure compression is applied
        $data = str_repeat('This is test data for compression algorithm testing. ', 20);
        $tags = ['compressionTest'];
        $lifetime = 3600;

        $backend->set($entryIdentifier, $data, $tags, $lifetime);
        $retrievedData = $backend->get($entryIdentifier);

        self::assertSame($data, $retrievedData, sprintf(
            'Data should be correctly stored and retrieved using "%s" compression.',
            $algorithm
        ));

        // Clean up
        $backend->flush();
    }

    /**
     * Test that compression is skipped for data smaller than compressionMinSize.
     *
     * @throws NoSuchCacheException
     * @throws ReflectionException
     */
    public function testCompressionMinSizeRespected(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->setCacheConfigurations([
            'lucene_cache_minsize_test' => [
                'backend' => LuceneCacheBackend::class,
                'options' => [
                    'maxBufferedDocs' => 0,
                    'compression' => true,
                    'compressionMinSize' => 1000, // Only compress data > 1000 bytes
                ],
            ],
        ]);

        $cache = $cacheManager->getCache('lucene_cache_minsize_test');
        $backend = $cache->getBackend();
        assert($backend instanceof LuceneCacheBackend);

        // Use reflection to access the private compress method
        $reflection = new ReflectionClass($backend);
        $compressMethod = $reflection->getMethod('compress');

        // Small data (should NOT be compressed - returned as-is)
        $smallData = 'Small data';
        $compressedSmall = $compressMethod->invoke($backend, $smallData);
        self::assertSame(
            $smallData,
            $compressedSmall,
            'Small data should not be compressed (returned unchanged).'
        );

        // Large data (should be compressed - will have prefix)
        $largeData = str_repeat('Large data for compression testing. ', 100);
        $compressedLarge = $compressMethod->invoke($backend, $largeData);

        // Compressed data should have a prefix: \x00 followed by algorithm identifier (Z, D, or C)
        self::assertNotSame(
            $largeData,
            $compressedLarge,
            'Large data should be compressed (different from original).'
        );
        self::assertStringStartsWith(
            "\x00",
            $compressedLarge,
            'Compressed data should start with the compression prefix.'
        );
        self::assertContains(
            $compressedLarge[1],
            ['Z', 'D', 'C'],
            'Compressed data should have a valid algorithm identifier (Z=zstd, D=gzdeflate, C=gzcompress).'
        );

        // Verify the compressed data is actually smaller (for highly compressible data)
        self::assertLessThan(
            strlen($largeData),
            strlen((string) $compressedLarge),
            'Compressed data should be smaller than the original for repetitive data.'
        );

        // Clean up
        $backend->flush();
    }

    /**
     * Test compression with various data types and edge cases.
     *
     * @throws NoSuchCacheException
     * @throws Exception
     * @throws InvalidDataException
     */
    public function testCompressionWithVariousDataTypes(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->setCacheConfigurations([
            'lucene_cache_data_types_test' => [
                'backend' => LuceneCacheBackend::class,
                'options' => [
                    'maxBufferedDocs' => 0,
                    'compression' => true,
                    'compressionMinSize' => 1,
                ],
            ],
        ]);

        $cache = $cacheManager->getCache('lucene_cache_data_types_test');
        $backend = $cache->getBackend();
        assert($backend instanceof LuceneCacheBackend);

        $testCases = [
            'empty_string' => '',
            'binary_data' => "\x00\x01\x02\x03\x04\x05",
            'unicode_data' => 'Héllo Wörld! 你好世界 🎉',
            'json_data' => json_encode(['key' => 'value', 'nested' => ['array' => [1, 2, 3]]]),
            'serialized_object' => serialize(['object' => new stdClass()]),
            'highly_compressible' => str_repeat('AAAAAAAAAA', 500),
            'random_looking' => base64_encode(random_bytes(500)),
        ];

        foreach ($testCases as $identifier => $data) {
            assert(is_string($data));
            $backend->set($identifier, $data, [], 3600);
            self::assertSame(
                $data,
                $backend->get($identifier),
                sprintf('Data type "%s" should be stored and retrieved correctly.', $identifier)
            );
        }

        // Clean up
        $backend->flush();
    }

    /**
     * Test findIdentifiersByTag returns correct identifiers.
     *
     * @throws Exception
     * @throws InvalidDataException
     * @throws Zend_Search_Exception
     * @throws Zend_Search_Lucene_Exception
     */
    public function testFindIdentifiersByTag(): void
    {
        $this->subject->set('entry1', 'data1', ['sharedTag', 'uniqueTag1'], 3600);
        $this->subject->set('entry2', 'data2', ['sharedTag', 'uniqueTag2'], 3600);
        $this->subject->set('entry3', 'data3', ['otherTag'], 3600);

        $identifiers = $this->subject->findIdentifiersByTag('sharedTag');
        sort($identifiers);

        self::assertCount(2, $identifiers, 'Should find exactly 2 entries with sharedTag.');
        self::assertSame(['entry1', 'entry2'], $identifiers, 'Should return correct identifiers.');

        $uniqueIdentifiers = $this->subject->findIdentifiersByTag('uniqueTag1');
        self::assertCount(1, $uniqueIdentifiers, 'Should find exactly 1 entry with uniqueTag1.');
        self::assertSame(['entry1'], $uniqueIdentifiers);

        $noIdentifiers = $this->subject->findIdentifiersByTag('nonExistentTag');
        self::assertCount(0, $noIdentifiers, 'Should return empty array for non-existent tag.');
    }

    /**
     * Test setCompressionAlgorithm throws exception for invalid algorithm.
     */
    public function testSetCompressionAlgorithmThrowsExceptionForInvalidAlgorithm(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported compression algorithm "invalid"');

        $this->subject->setCompressionAlgorithm('invalid');
    }

    /**
     * Test setCompressionAlgorithm throws exception when zstd is not available.
     */
    public function testSetCompressionAlgorithmThrowsExceptionForMissingZstd(): void
    {
        if (function_exists('zstd_compress')) {
            self::markTestSkipped('This test requires zstd extension to NOT be installed.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zstd compression requires ext-zstd');

        $this->subject->setCompressionAlgorithm('zstd');
    }

    /**
     * Test setCompressionLevel respects boundaries.
     *
     * @throws ReflectionException
     */
    public function testSetCompressionLevelBoundaries(): void
    {
        $reflection = new ReflectionClass($this->subject);
        $compressionLevelProperty = $reflection->getProperty('compressionLevel');

        // Test valid levels
        $this->subject->setCompressionLevel(5);
        self::assertSame(5, $compressionLevelProperty->getValue($this->subject), 'Level 5 should be accepted.');

        $this->subject->setCompressionLevel(-1);
        self::assertSame(-1, $compressionLevelProperty->getValue($this->subject), 'Level -1 (default) should be accepted.');

        $this->subject->setCompressionLevel(0);
        self::assertSame(0, $compressionLevelProperty->getValue($this->subject), 'Level 0 should be accepted.');

        $this->subject->setCompressionLevel(9);
        self::assertSame(9, $compressionLevelProperty->getValue($this->subject), 'Level 9 should be accepted.');

        // Test invalid levels (should be ignored)
        $this->subject->setCompressionLevel(-2);
        self::assertSame(9, $compressionLevelProperty->getValue($this->subject), 'Level -2 should be ignored (value unchanged).');

        $this->subject->setCompressionLevel(10);
        self::assertSame(9, $compressionLevelProperty->getValue($this->subject), 'Level 10 should be ignored (value unchanged).');
    }

    /**
     * Test get() retrieves data from buffer before commit.
     *
     * @throws NoSuchCacheException
     * @throws Exception
     * @throws InvalidDataException
     */
    public function testGetRetrievesFromBuffer(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->setCacheConfigurations([
            'lucene_cache_buffer_test' => [
                'backend' => LuceneCacheBackend::class,
                'options' => [
                    // High buffer limit - data stays in buffer
                    'maxBufferedDocs' => 1000,
                    'compression' => false,
                ],
            ],
        ]);

        $cache = $cacheManager->getCache('lucene_cache_buffer_test');
        $backend = $cache->getBackend();
        assert($backend instanceof LuceneCacheBackend);

        $backend->set('buffered_entry', 'buffered_data', [], 3600);

        // Data should be retrievable from buffer (not committed yet)
        self::assertSame('buffered_data', $backend->get('buffered_entry'), 'Should retrieve data from buffer.');

        $backend->flush();
    }

    /**
     * Test has() checks buffer before commit.
     *
     * @throws NoSuchCacheException
     * @throws Exception
     * @throws InvalidDataException
     */
    public function testHasChecksBuffer(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->setCacheConfigurations([
            'lucene_cache_has_buffer_test' => [
                'backend' => LuceneCacheBackend::class,
                'options' => [
                    'maxBufferedDocs' => 1000,
                    'compression' => false,
                ],
            ],
        ]);

        $cache = $cacheManager->getCache('lucene_cache_has_buffer_test');
        $backend = $cache->getBackend();
        assert($backend instanceof LuceneCacheBackend);

        self::assertFalse($backend->has('buffered_has_entry'), 'Entry should not exist initially.');

        $backend->set('buffered_has_entry', 'some_data', [], 3600);

        // has() should find the entry in buffer
        self::assertTrue($backend->has('buffered_has_entry'), 'Should find entry in buffer.');

        $backend->flush();
    }

    /**
     * Test decompression of legacy gzcompress data (without prefix).
     *
     * @throws ReflectionException
     */
    public function testDecompressLegacyData(): void
    {
        $reflection = new ReflectionClass($this->subject);
        $decompressMethod = $reflection->getMethod('decompress');

        // Simulate legacy compressed data (gzcompress without prefix)
        $originalData = 'This is legacy compressed data that uses gzcompress without the new prefix format.';
        /** @noinspection PhpComposerExtensionStubsInspection */
        $legacyCompressed = gzcompress($originalData);
        self::assertIsString($legacyCompressed, 'gzcompress should return a string.');

        // Verify it starts with 0x78 (zlib header) and has no prefix
        self::assertSame(0x78, ord($legacyCompressed[0]), 'Legacy gzcompress data should start with 0x78.');
        self::assertNotSame("\x00", $legacyCompressed[0], 'Legacy data should not have the new prefix.');

        // Decompress should handle legacy format
        $decompressed = $decompressMethod->invoke($this->subject, $legacyCompressed);
        self::assertSame($originalData, $decompressed, 'Legacy gzcompress data should be decompressed correctly.');
    }

    /**
     * Test that expired data in buffer returns false.
     *
     * @throws NoSuchCacheException
     * @throws ReflectionException
     * @throws Exception
     * @throws InvalidDataException
     */
    public function testExpiredDataInBufferReturnsFalse(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->setCacheConfigurations([
            'lucene_cache_expired_buffer_test' => [
                'backend' => LuceneCacheBackend::class,
                'options' => [
                    'maxBufferedDocs' => 1000,
                    'compression' => false,
                ],
            ],
        ]);

        $cache = $cacheManager->getCache('lucene_cache_expired_buffer_test');
        $backend = $cache->getBackend();
        assert($backend instanceof LuceneCacheBackend);

        // Set entry with short lifetime
        $backend->set('expiring_buffer_entry', 'will_expire', [], 10);

        // Should be available initially
        self::assertSame('will_expire', $backend->get('expiring_buffer_entry'), 'Data should be available before expiry.');
        self::assertTrue($backend->has('expiring_buffer_entry'), 'has() should return true before expiry.');

        // Simulate time passing using reflection
        $reflection = new ReflectionClass($backend);
        $execTimeProperty = $reflection->getProperty('execTime');
        $currentTime = $execTimeProperty->getValue($backend);
        $execTimeProperty->setValue($backend, $currentTime + 20);

        // Now the buffered data should be considered expired
        self::assertFalse($backend->get('expiring_buffer_entry'), 'Expired buffered data should return false.');
        self::assertFalse($backend->has('expiring_buffer_entry'), 'has() should return false for expired buffered data.');

        $backend->flush();
    }
}
