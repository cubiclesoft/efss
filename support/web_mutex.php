<?php
	// Web-based mutex class.  Emulates blocking and non-blocking mutex objects for all platforms.  PHP 4.3.2 or above required.
	// (C) 2011 CubicleSoft.  All Rights Reserved.

	class WebMutex
	{
		private $name, $locked;

		public function __construct($name)
		{
			$this->name = $name;
			$this->locked = 0;
		}

		public function __destruct()
		{
			if ($this->locked)
			{
				$this->locked = 1;
				$this->Unlock();
			}
		}

		// Locks the mutex.  Will unlock mutexes obtained for longer than the maximum script execution time.
		// Note:  Doesn't use flock() or System V Semaphores - both are buggy or non-existent under Windows/other platforms!
		public function Lock($block = true, $maxlock = false)
		{
			if ($this->locked)
			{
				$this->locked++;

				return true;
			}

			$startts = $this->microtime_float();

			// Get the basic lock.
			do
			{
				$fp = @fopen($this->name . ".lock", "xb");
				if ($fp === false && $maxlock !== false && $maxlock > 0)
				{
					$ts = @filemtime($this->name . ".lock");
					if ($ts !== false && time() - $ts > $maxlock)
					{
						$fp = @fopen($this->name . ".stale", "xb");
						if ($fp !== false)
						{
							@unlink($this->name . ".lock");
							fclose($fp);
							$fp = @fopen($this->name . ".lock", "xb");
							@unlink($this->name . ".stale");
						}
					}
				}

				if ($fp === false && $block && function_exists("usleep"))  usleep(rand(0, 100000));
			} while ($fp === false && ($block === true || $this->microtime_float() - $startts <= $block));

			if ($fp === false)  return false;

			fclose($fp);

			$this->name = substr(realpath($this->name . ".lock"), 0, -5);
			$this->locked = 1;

			return true;
		}

		public function Unlock()
		{
			if (!$this->locked)  return false;

			$this->locked--;
			if ($this->locked)  return true;

			$result = @unlink($this->name . ".lock");

			return true;
		}

		private function microtime_float()
		{
			$ts = explode(" ", microtime());
			$ts = (float)$ts[0] + (float)$ts[1];

			return $ts;
		}
	}
?>