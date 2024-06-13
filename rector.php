<?php

declare(strict_types=1);

use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\Privatization\Rector\Class_\FinalizeClassesWithoutChildrenRector;
use Ssch\TYPO3Rector\Rector\v11\v0\DateTimeAspectInsteadOfGlobalsExecTimeRector;
use PLUS\GrumPHPConfig\RectorSettings;
use Rector\Config\RectorConfig;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Php81\Rector\ClassConst\FinalizePublicClassConstantRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->parallel();
    $rectorConfig->importNames();
    $rectorConfig->importShortClasses();
    $rectorConfig->cacheClass(FileCacheStorage::class);
    $rectorConfig->cacheDirectory('./var/cache/rector');

    $rectorConfig->paths(
        array_filter(explode("\n", (string)shell_exec("git ls-files | xargs ls -d 2>/dev/null | grep -E '\.(php|html|typoscript)$'")))
    );

    // define sets of rules
    $rectorConfig->sets(
        [
            ...RectorSettings::sets(true),
            ...RectorSettings::setsTypo3(false),
        ]
    );

    // remove some rules
    // ignore some files
    $rectorConfig->skip(
        [
            ...RectorSettings::skip(),
            ...RectorSettings::skipTypo3(),
            FinalizePublicClassConstantRector::class,
            PrivatizeFinalClassPropertyRector::class,
            PrivatizeFinalClassMethodRector::class,
            FinalizeClassesWithoutChildrenRector::class,
            DateTimeAspectInsteadOfGlobalsExecTimeRector::class,
            RemoveExtraParametersRector::class,

            /**
             * rector should not touch these files
             */
            //__DIR__ . '/src/Example',
            //__DIR__ . '/src/Example.php',
        ]
    );
};
