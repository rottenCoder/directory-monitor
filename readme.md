# Directory Monitor Component

Library for monitoring a directory for changes using Inotify.

[![Build Status](https://secure.travis-ci.org/concertophp/directory-monitor.png?branch=master)](http://travis-ci.org/concertophp/directory-monitor)


## Install

The recommended way to install Directory Monitor is [through composer](http://getcomposer.org).

```JSON
{
    "require": {
        "concerto/directory-monitor": "0.*"
    }
}
```


## Usage

Create an instance of `RecursiveMonitor` and listen to the events you need:

```php
use Concerto\DirectoryMonitor\RecursiveMonitor;
use React\EventLoop\Factory as EventLoopFactory;

$loop = EventLoopFactory::create();
$monitor = new RecursiveMonitor($loop, __DIR__);

// Fired on any Inotify event:
$monitor->on('notice', function($path, $root) {
	echo "Notice: {$path} in {$root}\n";
});

$monitor->on('create', function($path, $root) {
	echo "Created: {$path} in {$root}\n";
});

$monitor->on('delete', function($path, $root) {
	echo "Deleted: {$path} in {$root}\n";
});

$monitor->on('modify', function($path, $root) {
	echo "Modified: {$path} in {$root}\n";
});

$monitor->on('write', function($path, $root) {
	echo "Wrote: {$path} in {$root}\n";
});

$loop->run();
```

You can also ignore files using regular expressions:

```php
// Ignore hidden files:
$monitor->ignore('/\.');

// Ignore the temp folder:
$monitor->ignore('^/tmp');

// Ignore all .cache files:
$monitor->ignore('\.cache$');
```

And you can notice previously ignored files:

```php
// Notice compiled templates:
$monitor->notice('^/tmp/templates');
```

The expression is applied to the full path of each item relative to
the root directory being monitored. For example, if your directory
is `/root/path` and an event was caused by `/root/path/some/file`
then the expression would be executed against the path `/some/file`.