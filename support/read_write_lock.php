<?php
	// Web-based reader/writer lock class.
	// (C) 2013 CubicleSoft.  All Rights Reserved.

	if (!class_exists("WebMutex"))  require_once "web_mutex.php";

	class ReadWriteLock
	{
		private $mutex, $writermutex, $reader, $name, $lastblock, $lastmaxlock;

		public function __construct($name)
		{
			$this->name = $name;
			$this->mutex = new WebMutex($name);
			$this->writermutex = false;
			$this->reader = false;
			$this->lastblock = true;
			$this->lastmaxlock = false;
		}

		public function __destruct()
		{
			if ($this->IsLocked())  $this->Unlock();
		}

		public function IsLocked()
		{
			return ($this->writermutex !== false || $this->reader);
		}

		public function Lock($write = false, $block = true, $maxlock = false)
		{
			if ($this->writermutex !== false || $this->reader)  return false;

			// Obtain the main mutex.
			if (!$this->mutex->Lock($block, $maxlock))  return false;

			$startts = $this->microtime_float();

			if ($write)
			{
				// Obtain the writer mutex.  Exclusive write.
				$this->writermutex = new WebMutex($this->name . ".writer");
				if (!$this->writermutex->Lock($block, $maxlock))
				{
					$this->mutex->Unlock();

					return false;
				}

				$this->mutex->Unlock();

				// Wait for readers to disappear.
				while (file_exists($this->name . ".readers") && ($block === true || $this->microtime_float() - $startts <= $block))
				{
					if (function_exists("usleep"))  usleep(rand(0, 100000));
				}

				// Check for readers.
				if (file_exists($this->name . ".readers"))
				{
					$this->writermutex->Unlock();
					$this->writermutex = false;

					return false;
				}
			}
			else
			{
				// Wait for a writer lock to disappear.
				while (file_exists($this->name . ".writer.lock") && ($block === true || $this->microtime_float() - $startts <= $block))
				{
					$this->mutex->Unlock();

					if (function_exists("usleep"))  usleep(rand(0, 100000));
					else  sleep(1);

					if (!$this->mutex->Lock($block, $maxlock))  return false;
				}

				// Check for a writer lock.
				if (file_exists($this->name . ".writer.lock"))
				{
					$this->mutex->Unlock();

					return false;
				}

				// Create/update the number of readers.
				file_put_contents($this->name . ".readers", ((int)@file_get_contents($this->name . ".readers")) + 1);

				$this->name = substr(realpath($this->name . ".readers"), 0, -8);
				$this->mutex->Unlock();

				$this->reader = true;
			}

			// Save the call info for the unlock call.
			$this->lastblock = $block;
			$this->lastmaxlock = $maxlock;

			return true;
		}

		public function Unlock()
		{
			if (!$this->IsLocked())  return false;

			if ($this->writermutex !== false)
			{
				$result = $this->writermutex->Unlock();
				$this->writermutex = false;
			}
			else if ($this->reader)
			{
				// Obtain the main mutex.
				if (!$this->mutex->Lock($this->lastblock, $this->lastmaxlock))  return false;

				// Update the number of readers.
				$num = ((int)@file_get_contents($this->name . ".readers")) - 1;
				if ($num < 1)  @unlink($this->name . ".readers");
				else  file_put_contents($this->name . ".readers", $num);

				$this->mutex->Unlock();

				$this->reader = false;
			}

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