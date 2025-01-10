# Use Lucene as Cache Backend for your TYPO3 projects
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
        'optimize' => false,
        'compress' => true,
    ],
    'groups' => [
      'pages',
    ]
];
```

The Option "indexName" must not contain other than the following chars: *a-zA-Z0-9\-_*

### Performance

The issue to develop that cache was a usage of very many cache Tags.

maxBufferedDocs is set to 1000 here, that means that up to 1000 documents are buffered before the writeout, that is good for large imports if you have some spare ram. Meanwhite that caching information is not available to other PHP processes.

Keep in mind that some operations (flushes and garbage collection) will always commit the buffer first to have a full index to search in.


#### optimize

The optimize setting sounds like a always good idea, but it is a very ressource intensive job so it is disabled by default.

### compress

Filters the data through gzcompress (must be compiled into your PHP installation) to reduce data

### Keep in mind 

This extenion relies on using the SingleSpaceTokenizer with the lucene package, so if you already use lucene in your project, your tokenizer is overwritten which could lead into problems.
*This is a todo we work on*

Use the proper cache technology for your needs, lucene may fit well for a page cache with lots of entries and tags, while others with more write/read operations go better with database/redis or even apcu if you do not have concurrency problems.

# Considerdations

In the example
```
'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
```
Was set, that is the default frontend. This extension ships with the dropin
```
'frontend' => \Weakbit\LuceneCache\Cache\Frontend\VariableFrontend::class,
```

Which uses igbinary if installed, or msgpack if installed. These have some improvements in performance, but you may go with the default frontent as well.

# Additional Resources

For more detailed information, refer to the following resources:

[Lucene-cache GitHub Repository](https://github.com/andersundsehr/lucene-cache)

[TYPO3 Documentation on Caching Framework](https://docs.typo3.org/m/typo3/reference-coreapi/12.4/en-us/ApiOverview/CachingFramework/Index.html****)

These resources provide comprehensive documentation and examples to help you get started with the lucene-cache backend for TYPO3.

# Credits

This extension is inspired by Benni Mack's [https://github.com/bmack/local-caches](bmack/local-caches)
