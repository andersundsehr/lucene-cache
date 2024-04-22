## Description

This extension adds a new Cache Backend for the TYPO3 Caching framework, using a PHP implementation of the lucene index to hold the Data. The goal is have a ram-independent cache.


### Example Configuration for the Cache
```
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend::class,
    'options' => [
        'defaultLifetime' => 3600,
        'indexName' => 'pages'
    ],
    'groups' => ['all']
];
```

The Option "indexName" must not contain other than the following chars: *a-zA-Z0-9\-_*

You should consider to set a proper serializer to your PHP Installation e.g. msgpack or igbinary

To be done:
- implement metrics (hits/misses/inserts/deletions)
- Code quality coverage
