## Description

This extension adds a new Cache Backend for the TYPO3 Caching framework, using a PHP implementation of the lucene index to hold the Data. The goal is have a ram-independent cache.

You have to decide if it makes sense to use this Backend, in general, the bigger the Cache gets the more sense lays in using lucene.

### Example Configuration for the Cache
```
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \Weakbit\LuceneCache\Cache\Backend\LuceneCacheBackend::class,
    'options' => [
        'defaultLifetime' => 604800,
        'indexName' => 'pages',
        'maxBufferedDocs' => 1000,
    ],
    'groups' => ['all']
];
```

The Option "indexName" must not contain other than the following chars: *a-zA-Z0-9\-_*


### Performance

The performance heavily depends on your setup und needs. In my case i checked it with a modern system using SATA SSD.

Also the Setup created quite very many Tags.

maxBufferedDocs is set to 1000 here, that means that up to 1000 documents are buffered before the writeout, that is good for large imports if you have some spare ram.
But keep in mind that a lookup (has,get,remove,flush) will always commit the buffer first to have a full index to search in.

wkbdef Feed took 2708.3520388603 seconds
wkbserial Feed took 5111.1553301811 seconds
wkbmsgpack Feed took 4740.0134928226 seconds
wkbigbinary Feed took 4511.0633621216 seconds

2.9G    wkbigbinary
2.9G    wkbmsgpack
3.0G    wkbserial

DB: 4.1+1.9G

-> weniger feed ohne compression testen

Die werte sind erstmal schlecht, nun aber prüfen ob der tag-lookup bei def und serial das gleiche ergebnis liefert und was da mehr performt.

Dann könnte man noch schauen ob eine art rambuffer genutzt werden kann und erst zu laufzeitende persistiert wird, bzw ggfs z.b. vor einem tag lookup persistieren dann braucht man da keine eigene logik erstellen.


### Keep in mind 

Cache is indented to be nearly valid, but concurrency or programatically issues between the cache layer and your data might lead into inconsistency, that is why you should think about queues and transactions as soon as the cache must be precise.

This extenion relies on using the SingleSpackTokenizer with the lucene package, so if you already use lucene in your project, your tokenizer is overwritten which could lead into problems.
*This is a todo we work on*

# Considerdations

You should consider to set a proper serializer to your PHP Installation e.g. msgpack or igbinary

To be done:
- implement metrics (hits/misses/inserts/deletions)
- Code quality coverage
- flushByTags could generate a better lucene query and perform quite good
