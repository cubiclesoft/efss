ReadWriteLock Class:  'support/read_write_lock.php'
===================================================

The ReadWriteLock class offers a cross-platform, named reader-writer solution for very old versions of PHP.  Using the [PECL sync](http://php.net/manual/en/book.sync.php) extension instead is a better option since ReadWriteLock can frequently result in broken lock files if the application exits prematurely while a lock is held.

This class depends on WebMutex.

Example usage:

```php
<?php
	require_once "support/read_write_lock.php";

	$rwlock = new ReadWriteLock(__FILE__);

	// Read lock.
	if (!$rwlock->Lock())
	{
		echo "Unable to obtain read lock.\n";

		exit();
	}

	// Do work here.

	$rwlock->Unlock();


	// Write lock.
	if (!$rwlock->Lock(true))
	{
		echo "Unable to obtain write lock.\n";

		exit();
	}

	// Do work here.

	$rwlock->Unlock();
?>
```

ReadWriteLock::__construct($name)
---------------------------------

Access:  public

Parameters:

* $name - A string containing a full path and filename to a web-writable location on the web server.

Returns:  Nothing.

This function initializes the class.

ReadWriteLock::IsLocked()
-------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if a lock as been obtained (read or write), false otherwise.

This function returns whether a lock has been obtained.

ReadWriteLock::Lock($write = false, $block = true, $maxlock = false)
--------------------------------------------------------------------

Access:  public

Parameters:

* $write - A boolean indicating whether or not the lock is a write lock (Default is false).
* $block - A boolean the determines whether or not to wait for a lock to be acquired (Default is true).
* $maxlock - A boolean of false or an integer indicating how long a lock should exist (Default is false).

Returns:  A boolean of true if the lock was successfully obtained, false otherwise.

This function attempts to obtain a read or write lock.  The lock files are `$name . ".lock"` for the global mutex, `$name . ".writer.lock"` for a write lock, and `$name . ".readers.lock"` for a read lock.

ReadWriteLock::Unlock()
-----------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the unlock was successful, false otherwise.

This function unlocks a held read or write lock.

WebMutex::microtime_float()
---------------------------

Access:  private

Parameters:  None.

Returns:  The result of `microtime()` as a float.

This is a very old function from long before microtime() had a second parameter to get a float value as a response.
