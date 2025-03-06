#!/bin/sh
rm composer.lock
docker run -u1000 -v ./:/app -w /app ghcr.io/pluswerk/php-dev:nginx-8.1-alpine sh -c 'git config --global --add safe.directory /app && composer require typo3/testing-framework typo3/minimal="^11" -q -n -W --dev && composer test && vendor/bin/phpstan analyse'
docker run -u1000 -v ./:/app -w /app ghcr.io/pluswerk/php-dev:nginx-8.1-alpine sh -c 'git config --global --add safe.directory /app && composer require typo3/testing-framework typo3/minimal="^12" -q -n -W --dev && composer test && vendor/bin/phpstan analyse'
docker run -u1000 -v ./:/app -w /app ghcr.io/pluswerk/php-dev:nginx-8.2-alpine sh -c 'git config --global --add safe.directory /app && composer require typo3/testing-framework typo3/minimal="^11" -q -n -W --dev && composer test && vendor/bin/phpstan analyse'
docker run -u1000 -v ./:/app -w /app ghcr.io/pluswerk/php-dev:nginx-8.2-alpine sh -c 'git config --global --add safe.directory /app && composer require typo3/testing-framework typo3/minimal="^12" -q -n -W --dev && composer test && vendor/bin/phpstan analyse'
docker run -u1000 -v ./:/app -w /app ghcr.io/pluswerk/php-dev:nginx-8.3-alpine sh -c 'git config --global --add safe.directory /app && composer require typo3/testing-framework typo3/minimal="^11" -q -n -W --dev && composer test && vendor/bin/phpstan analyse'
docker run -u1000 -v ./:/app -w /app ghcr.io/pluswerk/php-dev:nginx-8.3-alpine sh -c 'git config --global --add safe.directory /app && composer require typo3/testing-framework typo3/minimal="^12" -q -n -W --dev && composer test && vendor/bin/phpstan analyse'
