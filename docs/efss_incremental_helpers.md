EFSSIncremental Class:  'support/efss.php'
==========================================

The EFSSIncremental helper static functions are designed to make it easier to generate standard EFSS incremental data sets that the core EFSS class can utilize.  These functions read, write, merge, and verify both incrementals and base files.

Note that EFSS was originally intended to be used as a backup system and so there are references to features that are primarily seen only in backup systems (e.g. incrementals) and should be ignored.  For backup purposes, use [Cloud Backup](https://github.com/cubiclesoft/cloud-backup) instead.

EFSSIncremental::ForceUnlock($filename)
---------------------------------------

Access:  public static

Parameters:

* $filename - A string containing a lock filename.

Returns:  Nothing.

This static function forces a locked EFSS data store to become unlocked.  It isn't recommended to call this from automated code unless you really know what you are doing.  Lock files exist to protect the data store from corruption.

EFSSIncremental::Delete($filename)
----------------------------------

Access:  public static

Parameters:

* $filename - A string containing a EFSS data store filename.

Returns:  Nothing.

This static function deletes all files related to an EFSS data store (including '.php' files).  Take care that users can't call this function somehow (e.g. via a URL parameter) with a rogue filename.

EFSSIncremental::WritePHPFile($basefile, $key1, $iv1, $key2, $iv2, $blocksize, $lockfile)
-----------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $basefile - A string containing the base filename.
* $key1 - A string containing an AES256 compatible key.
* $iv1 - A string containing a CBC compatible IV.
* $key2 - A string containing another AES256 compatible key.
* $iv2 - A string containing another CBC compatible IV.
* $blocksize - An integer containing the block size of $basefile.
* $lockfile - A boolean of false or a string containing the lock file to use.

Returns: Nothing.

This static function creates a PHP file for a new or existing backup.

EFSSIncremental::GetLock($lockfile, $writelock, $waitforlock = true, $maxtime = 20)
-----------------------------------------------------------------------------------

Access:  public static

Parameters:

* $lockfile - A string containing a lock filename.
* $writelock - A boolean that specifies whether or not this is a writer lock.
* $waitforlock - A boolean of true or an integer specifying how long to wait for the lock (Default is true, waits indefinitely).
* $maxtime - An integer representing the maximum amount of time to wait if $waitforlock is an integer (Default is 20).

Returns:  A standard array of information.

This static function obtains a lock on the specified file in either multiple-read or exclusive write mode.

EFSSIncremental::Read($filename, $since, $startblock, $origlastwrite, $maxtime = 20, $blocksize = 4096, $len = 10485760)
------------------------------------------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $filename - A string containing the base filename to read from.
* $since - A string or integer containing a timestamp since the last update.
* $startblock - An integer specifying the starting block.
* $origlastwrite - An integer specifying the last write timestamp returned by the first call to this function.
* $maxtime - A boolean or integer representing the maximum amount of time to spend reading data (Default is 20).
* $blocksize - An integer containing the block size of $filename (Default is 4096).
* $len - An integer specifying the maximum amount of block data to return (Default is 10485760 - roughly 10MB).

Returns:  A standard array of information.

This static function assumes a lock has been established with `EFSSIncremental::GetLock()` and retrieves incremental data since the `$since` timestamp up to `$len` bytes or `$maxtime` expires.  The returned array can be used to construct a response to a client or server or just stored in an incremental file locally.  The 10MB limit helps avoid PHP memory limits.

EFSSIncremental::Write($filename, $startblock, $blockdata, $lastupdateddata, $md5, $sha1, $blocknumdata = false, $serial = false, $blocksize = 4096, $finalize = false)
-----------------------------------------------------------------------------------------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $filename - A string containing the base or incremental filename to write to.
* $startblock - An integer specifying the starting block.
* $blockdata - A string containing the binary block data to write.
* $lastupdateddata - A string containing the binary last updated timestamps to write.
* $md5 - A hex string containing the md5 of the input data.
* $sha1 - A hex string containing the sha1 of the input data.
* $blocknumdata - A boolean of false or a string containing the binary block number mapping data to write (Default is false).
* $serial - A boolean of false or a string containing the serial to write (Default is false).
* $blocksize - An integer containing the block size of $filename (Default is 4096).
* $finalize - A boolean specifying whether or not this is the last write operation (Default is false).

Returns:  A standard array of information.

This static function assumes a lock has been established with `EFSSIncremental::GetLock()` and writes base file or incremental data to disk.  Be sure to verify that all input data is of the exact and correct size before writing anything out to disk.

EFSSIncremental::WriteFinalize($filename)
-----------------------------------------

Access:  public static

Parameters:

* $filename - A string containing the base or incremental filename to write to.

Returns:  A standard array of information.

This static function assumes a lock has been established with `EFSSIncremental::GetLock()` and finalizes the base or incremental file (primarily deletes the '.partial' file).

EFSSIncremental::MakeReadOnly($basefile, $readonly = true)
----------------------------------------------------------

Access:  public static

Parameters:

* $basefile - A string containing the base filename.
* $readonly - A boolean that specifies whether or not the base file should be read-only.

Returns:  A standard array of information.

This static function verifies that the base file exists and isn't an incremental and then creates or deletes a '.readonly' file based on the value of `$readonly`.

EFSSIncremental::LastUpdated($filename)
---------------------------------------

Access:  public static

Paramaters:

* $filename - A string containing the base or incremental filename to get the last updated timestamp of.

Returns:  A standard array of information.

This static function assumes a lock has been established with `EFSSIncremental::GetLock()` and returns the last updated timestamp of the first last updated entry of `$filename`.  The first timestamp is guaranteed to always be the most recent timestamp.

EFSSIncremental::Merge($basefile, $incrementalfile, $blocksize = 4096, $delete = true)
--------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $basefile - A string containing the base filename.
* $incrementalfile - A string containing an incremental filename.
* $blocksize - An integer containing the block size of $basefile and $incrementalfile (Default is 4096).
* $delete - A boolean that specifies whether or not the original incremental file data is to be deleted after the merge completes (Default is true).

Returns:  A standard array of information.

This static function assumes a lock has been established with `EFSSIncremental::GetLock()` and merges the incremental data in `$incrementalfile` into `$basefile`. When `$delete` is true, the incremental files are deleted and a '.partial' file is created that has to be deleted directly with code later on.  This function is useful for managing a set of rolling incrementals.  Since merges don't check the data being merged (because the data is encrypted), it is a good idea to verify the backup before performing the merge.

EFSSIncremental::Verify($key1, $iv1, $key2, $iv2, $incrementalfile, $blocksize = 4096, $callbackfunc = false)
-------------------------------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $key1 - A string containing an AES256 compatible key.
* $iv1 - A string containing a CBC compatible IV.
* $key2 - A string containing another AES256 compatible key.
* $iv2 - A string containing another CBC compatible IV.
* $incrementalfile - A string containing a base or incremental filename.

Returns:  A standard array of information.

This static function assumes a lock has been established with `EFSSIncremental::GetLock()` and then proceeds to verify that the blocks of the incremental are not corrupted.  While a base file can be verified using this function (`EFSS::CheckFS()` is better), this is really intended for incremental verification because incrementals can't be mounted by themselves.  If either of the keys or IVs are empty strings, the function attempts to verify using the hashes file.

EFSSIncremental::MakeReverseDiffIncremental($basefile, $incrementalfile, $blocksize = 4096)
-------------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $basefile - A string containing the base filename.
* $incrementalfile - A string containing an incremental filename.
* $blocksize - An integer containing the block size of $basefile and $incrementalfile (Default is 4096).

Returns:  A standard array of information.

This static function assumes a lock has been established with `EFSSIncremental::GetLock()` and then proceeds to create a reverse diff.
