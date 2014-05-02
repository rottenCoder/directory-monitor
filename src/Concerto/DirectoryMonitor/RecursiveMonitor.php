<?php

	namespace Concerto\DirectoryMonitor;
	use Concerto\TextExpressions\RegularExpression as RegExp;
	use DirectoryIterator;
	use Evenement\EventEmitterTrait;
	use React\EventLoop\LoopInterface;

	const CREATE = 'create';
	const DELETE = 'delete';
	const MODIFY = 'modify';
	const WRITE = 'write';
	const NOTICE = 'notice';

	/**
	 * Recursive directory monitor using Inotify.
	 */
	class RecursiveMonitor {
		use EventEmitterTrait;

		/**
		 * The root directory being watched.
		 */
		protected $directory;

		protected $handler;

		protected $loop;

		/**
		 * Mask used to create watchers.
		 */
		protected $mask;

		/**
		 * Paths to Inotify watch descriptors.
		 */
		protected $paths;

		/**
		 * Ignore/notice rules.
		 */
		protected $rules;

		/**
		 * Inotify watch descriptors.
		 */
		protected $watchers;

		public function __construct(LoopInterface $loop, $directory) {
			$this->directory = realpath($directory);
			$this->loop = $loop;
			$this->mask = IN_CLOSE_WRITE | IN_CREATE | IN_DELETE | IN_MODIFY | IN_MOVE;
			$this->paths = [];
			$this->rules = [];
			$this->watchers = [];

			if (false === $this->directory || false === is_dir($this->directory)) {
				throw new MonitorException("Given path '{$$directory}' does not exist.", MonitorException::NOT_EXIST);
			}

			if (false === is_dir($this->directory)) {
				throw new MonitorException("Given path '{$$directory}' is not a directory.", MonitorException::NOT_DIRECTORY);
			}

			$this->handler = inotify_init();
			stream_set_blocking($this->handler, 0);
			$this->loop->addReadStream($this->handler, $this);

			$this->add($this->directory);
		}

		public function __invoke() {
			if (false !== ($events = inotify_read($this->handler))) {
				foreach ($events as $event) {
					$event = (object)$event;

					if (false === isset($this->watchers[$event->wd])) continue;

					$current = $this->watchers[$event->wd];
					$path = $current->real . '/' . $event->name;
					$relative = $this->getRelativePath($this->directory, $path);

					// Add and remove watches:
					if (IN_ISDIR === ($event->mask & IN_ISDIR)) {
						if (IN_MOVED_FROM === ($event->mask & IN_MOVED_FROM)) {
							$this->remove($path);
						}

						else if (
							IN_CREATE === ($event->mask & IN_CREATE)
							|| IN_MOVED_TO === ($event->mask & IN_MOVED_TO)
						) {
							$this->add($path);
						}
					}

					// Don't emit notices for this:
					if ($this->isPathIgnored('/' . $relative)) continue;

					if (
						IN_MOVED_FROM === ($event->mask & IN_MOVED_FROM)
						|| IN_DELETE === ($event->mask & IN_DELETE)
					) {
						$this->emit(DELETE, [$relative, $this->directory]);
					}

					else if (
						IN_CREATE === ($event->mask & IN_CREATE)
						|| IN_MOVED_TO === ($event->mask & IN_MOVED_TO)
					) {
						$this->emit(CREATE, [$relative, $this->directory]);
					}

					else if (IN_MODIFY === ($event->mask & IN_MODIFY)) {
						$this->emit(MODIFY, [$relative, $this->directory]);
					}

					else if (IN_CLOSE_WRITE === ($event->mask & IN_CLOSE_WRITE)) {
						$this->emit(WRITE, [$relative, $this->directory]);
					}

					$this->emit(NOTICE, [$relative, $this->directory]);
				}
			}
		}

		public function isPathIgnored($path) {
			$state = false;

			foreach ($this->rules as $data) {
				if ($data->expression->test($path)) $state = $data->ignored;
			}

			return $state;
		}

		protected function add($path) {
			$watcher = inotify_add_watch($this->handler, $path, $this->mask);
			$this->paths[$path] = $watcher;
			$this->watchers[$watcher] = (object)[
				'real' =>		$path,
				'relative' =>	$this->getRelativePath($this->directory, $path)
			];

			foreach (new DirectoryIterator($path) as $file) {
				if ($file->isDot() || false === $file->isDir()) continue;

				$this->add($file->getPathname());
			}
		}

		public function close() {
			if (false === is_resource($this->handler)) return;

			$this->loop->removeReadStream($this->handler);
			fclose($this->handler);
			$this->handler = false;
			$this->watchers = [];
		}

		protected function getRelativePath($from, $to) {
			$from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
			$to = is_dir($to)   ? rtrim($to, '\/') . '/'   : $to;
			$from = str_replace('\\', '/', $from);
			$to = str_replace('\\', '/', $to);

			$from = explode('/', $from);
			$to = explode('/', $to);
			$relPath = $to;

			foreach ($from as $depth => $dir) {
				if ($dir === $to[$depth]) {
					array_shift($relPath);
				}

				else {
					$remaining = count($from) - $depth;

					if ($remaining > 1) {
						$padLength = (count($relPath) + $remaining - 1) * -1;
						$relPath = array_pad($relPath, $padLength, '..');
						break;
					}

					else {
						$relPath[0] = $relPath[0];
					}
				}
			}

			return rtrim(implode('/', $relPath), '/');
		}

		/**
		 * Ignore events matching the given expression.
		 *
		 * The expression is applied to the full path of each item relative to
		 * the root directory being monitored.
		 *
		 * For example, if your directory is `/root/path` and an event was caused
		 * by `/root/path/some/file` then the expression would be executed against
		 * the path `/some/file`.
		 *
		 * @param	string|Concerto\TextExpressions\RegularExpression	$expression
		 */
		public function ignore($expression) {
			if (false === ($expression instanceof RegExp)) {
				$expression = new RegExp($expression, 'i');
			}

			$this->rules[] = (object)[
				'expression' =>		$expression,
				'ignored' =>		true
			];
		}

		/**
		 * Notice events matching the given expression. The opposite of `ignore`.
		 *
		 * @param	string|Concerto\TextExpressions\RegularExpression	$expression
		 */
		public function notice($expression) {
			if (false === ($expression instanceof RegExp)) {
				$expression = new RegExp($expression, 'i');
			}

			$this->rules[] = (object)[
				'expression' =>		$expression,
				'ignored' =>		false
			];
		}

		protected function remove($path) {
			if (false === isset($this->paths[$path])) return;

			$watcher = $this->paths[$path];
			unset($this->watchers[$watcher]);
			unset($this->paths[$path]);

			inotify_rm_watch($this->handler, $watcher);
		}
	}