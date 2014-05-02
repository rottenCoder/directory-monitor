#!/bin/bash
set -e
set -o pipefail

if [ "$TRAVIS_PHP_VERSION" != "hhvm" ]; then

    # install 'inotify' PHP extension
    echo "yes" | pecl install inotify
    echo "extension=inotify.so" >> "$(php -r 'echo php_ini_loaded_file();')"

fi

composer self-update
composer install --dev --prefer-source