WebMutex Class:  'support/web_mutex.php'
========================================

The WebMutex class offers a cross-platform, named mutex solution for very old versions of PHP.  Using the [PECL sync](http://php.net/manual/en/book.sync.php) extension instead is a better option since WebMutex can frequently result in a broken lock file if the application exits prematurely while a lock is held.

Example usage:

```php
<?php
	require_once "support/web_mutex.php";

	$mutex = new WebMutex(__FILE__);
	if (!$mutex->Lock())
	{
		echo "Unable to obtain mutex.\n";

		exit();
	}

	// Do work here.

	$mutex->Unlock();
?>
```

WebMutex::__construct($name)
----------------------------

Access:  public

Parameters:

* $name - A string containing a full path and filename to a web-writable location on the web server.

Returns:  Nothing.

This function initializes the class.

WebMutex::Lock($block = true, $maxlock = false)
-----------------------------------------------

Access:  public

Parameters:

* $block - A boolean the determines whether or not to wait for a lock to be acquired (Default is true).
* $maxlock - A boolean of false or an integer indicating how long a lock should exist (Default is false).

Returns:  A boolean of true if the lock was successfully obtained, false otherwise.

This function attempts to obtain a lock on the mutex.  The lock file is `$name . ".lock"`.

WebMutex::Unlock()
------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the unlock was successful, false otherwise.

This function unlocks the mutex.

WebMutex::microtime_float()
---------------------------

Access:  private

Parameters:  None.

Returns:  The result of `microtime()` as a float.

This is a very old function from long before microtime() had a second parameter to get a float value as a response.
