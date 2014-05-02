<?php

	namespace Concerto\DirectoryMonitor\Tests;
	use Concerto\DirectoryMonitor\RecursiveMonitor;
	use React\EventLoop\Factory as EventLoopFactory;
	use PHPUnit_Framework_TestCase as TestCase;

	/**
	 * @covers Concerto\DirectoryMonitor\RecursiveMonitor
	 */
	class RecursiveMonitorTest extends TestCase {
		public function testBasics() {
			$root = __DIR__ . '/Scratch';
			$loop = EventLoopFactory::create();
			$monitor = new RecursiveMonitor($loop, $root);
			$log = (object)[
				'create' =>		false,
				'delete' =>		false,
				'modify' =>		false,
				'write' =>		false
			];

			$monitor->on('create', function($path, $root) use ($log) {
				$log->create = $path;
			});

			$monitor->on('delete', function($path, $root) use ($log) {
				$log->delete = $path;
			});

			$monitor->on('modify', function($path, $root) use ($log) {
				$log->modify = $path;
			});

			$monitor->on('write', function($path, $root) use ($log) {
				$log->write = $path;
			});

			$loop->futureTick(function() use ($loop, $root) {
				touch($root . '/foobar');
				unlink($root . '/foobar');
			});

			// Timeout and hope?
			$loop->addTimer(1, function() use ($loop) {
				$loop->stop();
			});

			$loop->run();
			$monitor->close();

			$this->assertEquals($log->create, 'foobar');
			$this->assertEquals($log->delete, 'foobar');
			$this->assertFalse($log->modify);
			$this->assertEquals($log->write, 'foobar');
		}

		public function testIgnore() {
			$root = __DIR__ . '/Scratch';
			$loop = EventLoopFactory::create();
			$monitor = new RecursiveMonitor($loop, $root);
			$log = (object)[
				'create' =>		false,
				'delete' =>		false,
				'modify' =>		false,
				'write' =>		false
			];

			$monitor->ignore('/foobar$');

			$this->assertTrue($monitor->isPathIgnored('/foobar'));

			$monitor->notice('/foobar$');

			$this->assertFalse($monitor->isPathIgnored('/foobar'));
		}
	}