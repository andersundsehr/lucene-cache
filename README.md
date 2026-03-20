# Use Lucene as Cache Backend for your TYPO3 projects

[![CI](https://github.com/andersundsehr/lucene-cache/actions/workflows/tasks.yml/badge.svg)](https://github.com/andersundsehr/lucene-cache/actions/workflows/tasks.yml)
[![codecov](https://codecov.io/gh/andersundsehr/lucene-cache/graph/badge.svg)](https://codecov.io/gh/andersundsehr/lucene-cache)

Provides a cache backend for TYPO3 that stores all cache information in Lucene index.

## Key Features of lucene-cache for TYPO3 
- Efficient Caching: Uses Lucene's indexing and search capabilities to store and retrieve cached content quickly.
- Scalability: Can handle large volumes of data and perform well under high load, making it suitable for large TYPO3 installations.
- Flexibility: Provides flexible configuration options to tailor the caching behavior to specific needs.
- Integration: Seamlessly integrates with TYPO3's caching framework, allowing for easy setup and use within TYPO3 projects.

## Installation and Configuration
To use the lucene-cache backend in your TYPO3 project, follow these steps:

### Install the Extension
   You can install the lucene-cache extension via Composer:

```sh
composer require andersundsehr/lucene-cache
```

### Example Configuration for the Cache
```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend::class,
    'options' => [
        'defaultLifetime' => 604800,
        'maxBufferedDocs' => 1000,
        'compression' => true,
        'compressionAlgorithm' => 'zstd', // optional: auto-detects if not set
        'compressionMinSize' => 256,      // optional: skip compression for small data
    ],
    'groups' => [
      'pages',
    ]
];
```

The Option "indexName" must not contain other than the following chars: *a-zA-Z0-9\-_*

### Performance

The issue to develop that cache was a usage of very many cache tags.

maxBufferedDocs is set to 1000 here, that means that up to 1000 documents are buffered before to write, that is good for large imports if you have some spare ram. Meanwhile, that caching information is not available to other PHP processes.

Keep in mind that some operations (flushes and garbage collection) will always commit the buffer first to have a full index to search in.

### compression

Enable compression to reduce disk space usage. The extension supports multiple compression algorithms and will auto-detect the best available one.

```php
'options' => [
    'compression' => true,
    'compressionAlgorithm' => 'zstd',  // 'zstd', 'gzdeflate', or 'gzcompress'
    'compressionLevel' => 3,           // -1 to 9 (default: -1 = auto)
    'compressionMinSize' => 256,       // minimum bytes to compress (default: 256)
],
```

**Available algorithms (in order of preference):**

| Algorithm    | Speed  | Ratio     | Requirement |
|--------------|--------|-----------|-------------|
| `zstd`       | ⚡ Fast | Excellent | ext-zstd    |
| `gzdeflate`  | Medium | Good      | ext-zlib    |
| `gzcompress` | Medium | Good      | ext-zlib    |

- **Auto-detection**: If `compressionAlgorithm` is not set, the best available algorithm is used automatically.
- **Minimum size**: Data smaller than `compressionMinSize` bytes is not compressed (overhead not worth it).
- **Backward compatible**: Existing caches using legacy `gzcompress` will still decompress correctly.

For more detailed information, refer to the following resources:

[Lucene-cache GitHub Repository](https://github.com/andersundsehr/lucene-cache)

[TYPO3 Documentation on Caching Framework](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/CachingFramework/Index.html#caching-framework)

These resources provide comprehensive documentation and examples to help you get started with the lucene-cache backend for TYPO3.

# Credits

This extension is inspired by Benni Mack's [bmack/local-caches](https://github.com/bmack/local-caches)
