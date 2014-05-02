<?php

	namespace Concerto\DirectoryMonitor;
	use Exception;

	class MonitorException extends Exception {
		const NOT_EXIST = 0;
		const NOT_DIRECTORY = 1;
	}