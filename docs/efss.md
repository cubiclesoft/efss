EFSS Class:  'support/efss.php'
===============================

The EFSS class contains everything needed to programmatically create and manage Encrypted File Storage System (EFSS) data stores.

 The bulk of the functionality is nearly identical to PHP's [file](http://www.php.net/manual/en/ref.filesystem.php) and [directory](http://www.php.net/manual/en/ref.dir.php) functions with some limitations here and there and a few extra features here and there.  The only major difference between PHP and EFSS is the return value of each function, which is always a standard information array in the EFSS class.  Every function in the EFSS class is tested in the test suite, so there is usually at least one working example.

Note that EFSS was originally intended to be used as a backup system and so there are references to features that are primarily seen only in backup systems (e.g. incrementals) and should be ignored.  For backup purposes, use [Cloud Backup](https://github.com/cubiclesoft/cloud-backup) instead.

Example usage:

```php
<?php
	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once "support/efss.php";

	// DO NOT use the keys and IVs here for your storage system.  Running 'create.php' will generate the necessary information.
	$key1 = pack("H*", "13c21eb0d95f5bcb49c1aef598efddc8eac7295092ffbd99c6c1f28d0b0dc9e3");
	$iv1 = pack("H*", "3d38ae9c7cf6bcb449cbf9c368103ec6");
	$key2 = pack("H*", "2e444c2eceafe40cbdf564ad48f6ca99dbb23d43f7b630d89646a48ef0d67a02");
	$iv2 = pack("H*", "95dfd6e8b753c594c52d95fef5cd4511");

	$efss = new EFSS();
	if (file_exists($rootpath . "/my_efss.dat"))  $result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/my_efss.dat", EFSS_MODE_EXCL);
	else  $result = $efss->Create($key1, $iv1, $key2, $iv2, $rootpath . "/my_efss.dat");
	if (!$result["success"])
	{
		echo "Unable to create/mount EFSS data store.  Error:  " . $result["error"] . "\n";

		exit();
	}

	$efss->mkdir("test/test2");
	$efss->file_put_contents("/test/test2/test.txt", "It works!");

	$result = $efss->file_get_contents("test/test2/test.txt");
	echo $result["data"];

	$efss->Unmount();
?>
```

EFSS::__construct()
-------------------

Access:  public

Parameters:  None.

Returns:  Nothing.

This function initializes the EFSS class.

EFSS::Translate()
-----------------

Access:  _internal_ static

Parameters:  String + arguments

Returns: Nothing.

This internal function is intended for use in a multilingual support environment to translate human-readable input strings to another language.

EFSS::Create($key1, $iv1, $key2, $iv2, $basefile, $lockfile = false, $blocksize = 4096, $timestamp = EFSS_TIMESTAMP_UTC, $dirmode = EFSS_DIRMODE_DEFAULT)
---------------------------------------------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $key1 - A string containing an AES256 compatible key.
* $iv1 - A string containing a CBC compatible IV.
* $key2 - A string containing another AES256 compatible key.
* $iv2 - A string containing another CBC compatible IV.
* $basefile - A string containing the base filename to use.
* $lockfile - A boolean of false (uses `$basefile`) or a string containing the lock file to use (Default is false).
* $blocksize - An integer containing the block size to use (Default is 4096).
* $timestamp - An integer of either EFSS_TIMESTAMP_UTC or EFSS_TIMESTAMP_UNIX (Default is EFSS_TIMESTAMP_UTC).
* $dirmode - An integer set of flags containing the desired directory mode (Default is EFSS_DIRMODE_DEFAULT).

Returns:  A standard array of information.

This function creates a brand new EFSS data store after evaluating the inputs.  A user should not invent values for the first five options and should let a real random number generator do the work.  The block size must be a multiple of 4096 and less than or equal to 32768.  The timestamp setting dictates how timestamps are stored.  The UTC format is highly recommended despite occupying 5 times the storage space so that the file can cleanly cross over to another host without being incorrect.  The directory mode can be one or more of the following options:  EFSS_DIRMODE_COMPRESS, EFSS_DIRMODE_CASE_INSENSITIVE.  The default directory mode is recommended.

EFSS::Mount($key1, $iv1, $key2, $iv2, $basefile, $mode, $lockfile = false, $blocksize = 4096, $incrementals = array(), $reversediff = false, $waitforlock = true)
-----------------------------------------------------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $key1 - A string containing an AES256 compatible key.
* $iv1 - A string containing a CBC compatible IV.
* $key2 - A string containing another AES256 compatible key.
* $iv2 - A string containing another CBC compatible IV.
* $basefile - A string containing the base filename to use.
* $mode - An integer indicating the mode to mount the file system with.
* $lockfile - A boolean of false (uses $basefile) or a string containing the lock file to use (Default is false).
* $blocksize - An integer containing the block size to use (Default is 4096).
* $incrementals - An array containing a list of incremental files to mount as well in the order specified (Default is array()).
* $waitforlock - A boolean or integer indicating how long to wait for a lock (Default is true).

Returns:  A standard array of information.

This function mounts an EFSS data store, obtaining an appropriate lock on the data in the process.  Note that `$blocksize` has to be the same value as used for `EFSS::Create()`.

This function also mounts incrementals if they are specified but will only successfully complete the mount if `$mode` is EFSS_MODE_READ.

EFSS::Unmount()
---------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function unmounts a mounted file system.

EFSS::SetDefaultOwner($ownername)
---------------------------------

Access:  public

Parameters:

* $ownername - A string containing the default owner name to use.

Returns:  Nothing.

This function sets the default owner name that will be used when creating directories, files, and symbolic links.

EFSS::SetDefaultGroup($groupname)
---------------------------------

Access:  public

Parameters:

* $groupname - A string containing the default group name to use.

Returns:  Nothing.

This function sets the default group name that will be used when creating directories, files, and symbolic links.

EFSS::GetDirMode()
------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the mode that the file system was mounted with.

This function just returns the value of the directory mode variable stored in the internal class.

EFSS::fopen_write($filename, $filemode = 0664, $compress = EFSS_COMPRESS_DEFAULT, $ownername = false, $groupname = false, $created = false, $data = false)
----------------------------------------------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $filename - A string containing a filename to create.
* $filemode - An integer representing the mode of the new file (Default is 0664 octal).
* $compress - An integer representing the compression mode to use (Default is EFSS_COMPRESS_DEFAULT).
* $ownername - A boolean of false or a string containing the owner name to use (Default is false).
* $groupname - A boolean of false or a string containing the group name to use (Default is false).
* $created - A boolean of false or an integer representing the UNIX creation timestamp (Default is false).
* $data - A boolean of false or a string containing the entire file's data to write (Default is false).

Returns:  A standard array of information.

This function is used when writing files.  The default behavior is to just open a file for writing (replacing any existing file).  The most interesting feature is the `$data` option.  When `$data` is a string and is less than 45% of the EFSS block size, the file data is inlined with the directory block.  The other options are useful for saving time by avoiding altering the directory block a zillion times for a single file.

EFSS::internal_fflush($fp, $finalize = false)
---------------------------------------------

Access:  private

Parameters:

* $fp - A valid EFSS file handle.
* $finalize - A boolean that specifies whether or not this is the last write operation and should be finalized (Default is false).

Returns:  A standard array of information.

This internal function is used to write out pending data in the buffer to disk either when enough data has been appended to the output buffer or `EFSS::fclose()` is called.  When `$finalize` is true, the last blocks are written and the directory block is updated with file size information (if necessary).

EFSS::ReadMoreFileData($fp)
---------------------------

Access:  private

Parameters:

* $fp - A valid EFSS file handle.

Returns:  A standard array of information.

This internal function is used to read more file data from disk.

EFSS::internal_stat($filename, $lastsymlink)
--------------------------------------------

Access:  private

Parameters:

* $filename - A string containing a path to a resource.
* $lastsymlink - A boolean to pass to `EFSS::LoadPath()` to indicate whether or not to follow a symlink if it is the last entry.

Returns:  A standard array of information.

This internal function generates a `stat()` compatible array.

EFSS::GetFileInfo($filename)
----------------------------

Access:  public

Parameters:

* $filename - A string containing a path to a resource.

Returns:  A standard array of information.

This function returns the internal `EFSS_DirEntry_DirFile` structure for the fully resolved path/file specified by `$filename`.

EFSS::CheckFS()
---------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function does an exhaustive check of the mounted EFSS data store for validity.  Similar to 'fsck' and 'chkdsk' but does not repair issues.

EFSS::Defrag($path, $recursive, $pathcallbackfunc = false)
----------------------------------------------------------

Access:  public

Parameters:

* $path - A string containing a path to defragment.
* $recursive - A boolean that indicates whether or not to defragment all subdirectories.
* $pathcallbackfunc - A valid callback function for a current path callback (Default is false).  The callback function must accept three parameters - callback($path, $numfragments, $startts).  The callback is called approximately once per second.

Returns:  A standard array of information.

This function defragments the specified path.  Fragmentation can happen when adding and removing lots of files over a long period of time.

EFSS::ResolvePath($path, $dirinfo = false)
------------------------------------------

Access:  private

Parameters:

* $path - A string containing an unsanitized relative path.
* $dirinfo - A boolean of false (current directory) or an array containing valid directory mapping information (Default is false).

Returns:  An array containing the mapped directory.

This internal function converts a relative path into an absolute path.  It doesn't check the absolute path against the mounted EFSS data store.

EFSS::DirInfoToPath($dirinfo)
-----------------------------

Access:  private

Parameters:

* $dirinfo - An array containing directory mapping information.

Returns:  A string containing the path.

This internal function converts directory mapping information to a displayable or reusable string.

EFSS::LoadPath($path, $dirinfo = false, $lastentrytype = EFSS_DIRTYPE_DIR, $fullentry = false, $lastsymlink = false)
--------------------------------------------------------------------------------------------------------------------

Access:  private

Parameters:

* $path - A string containing a path to load.
* $dirinfo - A boolean of false (current directory) or an array containing valid directory mapping information (Default is false).
* $lastentrytype - An integer representing the allowed last directory entry type for what the caller expects to be returned (Default is EFSS_DIRTYPE_DIR).
* $fullentry - A boolean that specifies whether or not to return the full directory entry information (Default is false).
* $lastsymlink - A boolean that specifies whether or not to follow the last entry if it is a symbolic link (Default is false).

Returns:  A standard array of information.

This internal function is used extensively by many other EFSS functions to locate directories, files, and symbolic links in the directory hierarchy.

EFSS::ReadDirBlock($blocknum, $findname = false)
------------------------------------------------

Access:  private

Parameters:

* $blocknum - An integer representing the directory block to read.
* $findname - A boolean of false (return all entries in the block) or a string to match to an entry in the block (Default is false).

Returns:  A standard array of information.

This internal function is used to load a directory block from disk into the directory block cache or use the block from the cache if it is already loaded.

EFSS::FindDirInsertPos($blocknum, $newname)
-------------------------------------------

Access:  private

Parameters:

* $blocknum - An integer representing the starting directory block.
* $newname - A string containing a new name.

Returns:  A standard array of information.

This internal function locates the block number and position in the entries where a new directory, file, or symbolic link will be inserted based on the supplied $newname.  If it already exists, an error will be returned with the block number, directory entries, and position of the existing entry.

EFSS::WriteDirBlock($dir, $blocknum)
------------------------------------

Access:  private

Parameters:

* $dir - An object of EFSS_DirEntries.
* $blocknum - An integer representing where the directory block should be stored.

Returns:  A standard array of information.

This internal function writes one or more directory block entries.  If a directory block is too big to fit into a single block, it automatically pulls entries off the end until the block fits, then prepends them to the next block.  The process repeats until the directory blocks settle.

EFSS::NextUnusedBlock()
-----------------------

Access:  private

Parameters:  None.

Returns:  A standard array of information.

This internal function locates the next unused block and returns its block number to the caller.

EFSS::ReloadUnused()
--------------------

Access:  private

Parameters:  None.

Returns:  A standard array of information.

This internal function reloads the unused block linked list.  Typically called after the current list of unused blocks is exhausted to locate the next section of blocks.

EFSS::FreeLinkedList($blocknum)
-------------------------------

Access:  private

Parameters:

* $blocknum - An integer representing the first block in a linked list.

Returns:  A standard array of information.

This internal function frees a linked list of blocks.  Writes random data to each unused block before adding it to the list of unused blocks.

EFSS::FreeUsedBlock($blocknum, $flush = false)
----------------------------------------------

Access:  private

Parameters:

* $blocknum - An integer representing the block number to free.
* $flush - A boolean indicating whether or not to flush the unusued block list to disk.

Returns:  A standard array of information.

This internal function frees a single block.  The flush option allows a bunch of blocks to be queued up in such a way so as to minimize file system fragmentation.

EFSS::Lock($mode, $waitforlock = true)
--------------------------------------

Access:  private

Parameters:

* $mode - An integer representing a valid lock mode.
* $waitforlock - A boolean or an integer indicating how long to wait for the lock (Default is true).

Returns:  A standard array of information.

This internal function is used when creating and mounting the file system to establish the appropriate reader/writer lock.

EFSS::RawWriteBlock($data, $blocknum, $type)
--------------------------------------------

Access:  private

Parameters:

* $data - A string containing data to store. Must be block size - 30 bytes or less.
* $blocknum - An integer representing the block number where the block will be written.
* $type - A one byte string representing the type of block this will be.

Returns:  A standard array of information.

This internal function encrypts then writes a block of data to the physical file system.

EFSS::RawReadBlock($blocknum, $type)
------------------------------------

Parameters:

* $blocknum - An integer representing the block number where the block will be written.
* $type - A one byte string representing the type of block this should be (or EFSS_BLOCKTYPE_ANY).

Returns:  A standard array of information.

This internal function reads a block of data from the physical file system, decrypts it, verifies that it isn't corrupt, and that it is of the type that the calling function has asked for.

EFSS::RawDecryptBlock($cipher1, $cipher2, $block, $type)
--------------------------------------------------------

Access:  private

Parameters:

* $cipher1 - A valid Crypt_AES object.
* $cipher2 - A valid Crypt_AES object.
* $block - A string containing a block of data to decrypt.
* $type - A one byte string representing the type of block this should be (or EFSS_BLOCKTYPE_ANY).

Returns:  A standard array of information.

This static function decrypts a block, verifies that it isn't corrupt, and that it is of the type that the calling function has asked for.

EFSS::RawSeekBlock($blocknum)
-----------------------------

Access:  private

Parameters:

* $blocknum - An integer representing the block number where the block will be read/written.

Returns:  The return value of `fseek()`.

This internal function seeks to a specific block.

EFSS::RawSeekUpdate($blocknum)
------------------------------

Access:  private

Parameters:

* $blocknum - An integer representing the block number where the block will be read/written.

Returns:  The return value of `fseek()`.

This internal function seeks to a specific block update.

EFSS::RawSeekHashes($blocknum)
------------------------------

Access:  private

Parameters:

* $blocknum - An integer representing the block number where the block hash will be read/written.

Returns:  The return value of `fseek()`.

This internal function seeks to a specific block hash location.

EFSS::RawSeek($fp, $pos)
------------------------

Access:  public static

Parameters:

* $fp - A resource handle to a file on the real file system.
* $pos - An integer or floating point value representing the absolute position to seek to.

Returns:  The return value of fseek().

This static function seeks to the desired location in a file.  If `$pos` is greater than 1GB, it seeks 1GB at a time until the amount is less than 1GB and then seeks the remainder.

EFSS::ConvertFromUTCDateTime($ts)
---------------------------------

Access:  public static

Parameters:

* $ts - A string containing a timestamp in "YYYY-MM-DD HH:MM:SS" format.

Returns:  An integer in local UNIX timestamp format.

This static function converts a UTC string to the local UNIX timestamp.  Used extensively by various EFSS functions.

EFSS::ConvertFromLocalDateTime($ts)
-----------------------------------

Access:  public static

Parameters:

* $ts - A string containing a timestamp in "YYYY-MM-DD HH:MM:SS" format.

Returns:  An integer in local UNIX timestamp format.

This static function converts a local timestamp string to the local UNIX timestamp.  Used primarily by a couple shell extensions in 'shell_exts/main.php' to convert command-line timestamp strings.

EFSS::ConvertToUTCDateTime($ts)
-------------------------------

Access:  public static

Parameters:

* $ts - An integer in local UNIX timestamp format.

Returns:  A string in "YYYY-MM-DD HH:MM:SS" UTC format right-padded to 20 characters.

This static function takes a local UNIX timestamp and converts it into a UTC EFSS data store friendly format.

EFSS::UnpackInt($data)
----------------------

Access:  public static

Parameters:

* $data - A two, four, or eight byte string representing a 16-bit, 32-bit, or 64-bit integer respectively in big-endian format.

Returns:  An integer (or float/double) on success, a boolean of false on failure.

This static function takes big-endian strings as input and outputs regular integers (or float/double if a 64-bit number is too large).  On error (e.g. not enough data), false is returned.

EFSS::PackInt64($num)
---------------------

Access:  public static

Parameters:

* $num - An integer or float/double.

Returns:  An eight byte string in big-endian format representing the input with overflow cutoff.

This static function takes integers and floats/doubles as input and outputs a big-endian string representing the number.  Due to the precision issues of float/double, large numbers hit a ceiling under 32-bit PHP (a rough limit is detected in the test suite).  64-bit PHP will likely not convert an int to float/double, so the precision issues aren't likely to be an issue.

EFSS Copy Helpers
-----------------

The EFSS "copy helper" classes are designed to act as an abstraction away from real file systems and EFSS file systems to facilitate cloning directories, files, and symlinks.  They are used primarily by the command-line tool 'sync.php' to reduce the amount of code required to handle four different scenarios:  Real to EFSS, EFSS to real, EFSS to EFSS (but not the same), and real to real.

There are four copy helper classes:

* EFSS_CopyHelper - An abstract base class used by the other classes. It primarily manages common variables to the other classes and also returns custom stat() and lstat() call results and sets stats, including owner and group names, for real and EFSS file systems.
* EFSS_DirCopyHelper - Designed to walk and create real and EFSS directories without the caller needing to know the underlying file system.
* EFSS_SymlinkCopyHelper - Designed to retrieve and create symbolic links without the caller needing to know the underlying file system.
* EFSS_FileCopyHelper - Designed to retrieve file data and create files without the caller needing to know the underlying file system.

The 'sync.php' script actually isn't 100% file system agnostic, especially when it comes to files.  Inline files are a fairly unique feature of EFSS, so 'sync.php' makes sure that files under 1MB use the EFSS file_put_contents() call for target EFSS data stores so that EFSS inlining takes place.
