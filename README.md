Encrypted File Storage System (EFSS)
====================================

EFSS stores data securely in a file structure without having to install or compile anything beyond PHP (i.e. not [FUSE](http://fuse.sourceforge.net/), not a PHP extension, and runs virtually everywhere).

EFSS is a [file system](http://en.wikipedia.org/wiki/File_system) with transparent encryption and compression implemented in userland code in the PHP scripting language.  It comes with a robust suite of tools to manage EFSS data stores that include a command-line shell and a powerful library that can be used in various PHP programming projects.  The specification for the file system is open to allow for portability to other programming languages.  EFSS can be used to store hierarchical file-like data securely outside of a database in a low-write, moderate-read, PHP-based web environment.

Note that EFSS was originally intended to be used as a backup system.  As a result, there are references to features that are primarily only seen in backup systems (e.g. incrementals) and those references and features should be largely ignored.  For backup purposes, use [Cloud Backup](https://github.com/cubiclesoft/cloud-backup) instead.

Features
--------

* A real, but virtual, file system with block data storage and reader-writer locking.
* Transparent encryption with dual AES256 keys.
* Transparent compression with zlib deflate.  Only enabled if the host supports it (e.g. PHP compiled with zlib support).
* Works the same under Windows, Mac, and Linux.  Basically, any OS that runs PHP.
* Accurately emulates directories, files, symbolic links, permissions, owners, and groups.
* Command-line tools to create, check/verify, and manage EFSS data stores.
* A developer-friendly PHP class to create and mount EFSS data stores and use functions familiar to PHP programmers such as fopen(), mkdir(), unlink(), opendir(), etc. to manipulate files, directories, and symbolic links.
* An extensive test suite to validate that EFSS will work without any issues on a given host.
* Deleted files and directories in EFSS data stores are securely overwritten with random data before being assigned to the unused blocks list.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your project.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Command-line Tools
------------------

EFSS comes with a suite of command-line tools for performing common maintenance tasks with EFSS data stores.  Each command-line tool utilizes the underlying 'support/efss.php' classes to manipulate and navigate EFSS data stores.  Each tool has a number of options that can be seen by running the tool without any options.  For example, running 'php create.php' will display the options for 'create.php'.

The following is a high-level breakdown of the command-line tools that are available:

* create.php - Creates new EFSS data stores that are compatible with the other EFSS command-line tools.
* sync.php - Clones/synchronizes directories, files, and symbolic links to and from EFSS data stores while attempting to keep permissions intact.
* shell.php - Mounts an EFSS data store and presents an interactive shell that has quite a few commands.  Type 'help' in the shell to get a list of commands such as 'ls', 'grep', 'rm', 'findstr', etc.  Each command has its own set of options that can be viewed with the '-?' option.  For example, 'ls -?'.  New commands for the shell can be added by writing a shell extension.
* backup.php - Backs up a remote EFSS data store.  Requires a compatible RESTful server API.  Use of this script is not recommended.
* check.php - Runs a thorough series of tests of a base file plus incrementals to make sure that the files haven't been corrupted.  A few of the other tools have similar features, but this is specialized for doing extensive checks.

Using the EFSS Class
--------------------

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

More documentation can be found in the 'docs' directory of this repository.

* [EFSS class](https://github.com/cubiclesoft/efss/blob/master/docs/efss.md) - Core documentation for the various functions in the EFSS class.
* [Test suite](https://github.com/cubiclesoft/efss/blob/master/test_suite/run.php) - Additional examples of using the EFSS class can be found in the test suite.

Limitations
-----------

Okay, so EFSS is not perfect.  There are a few limitations of this software that hopefully won't be show stopper issues for you.

Under PHP, there are theoretical, untested limits for EFSS data stores.  Even though attempts have been made to avoid issues, the limit can be as low as 2GB under 32-bit PHP (64-bit PHP won't encounter a 2GB restriction).  The normal limit of EFSS data stores is 8.7TB at the default block size of 4096 bytes (2^31 * 4KB).  The absolute limit of EFSS data stores is 70.3TB at a block size of 32,768 bytes (2^31 * 32KB).

EFSS is designed for reading data, not writing data.  Performance when writing new files (transparent encryption and compression, after all) and creating new directories is a bit on the sluggish end of things.  Reading data, however, is a bit faster, as is to be expected.

If an EFSS data store becomes corrupted, especially the first three blocks, there is currently no easy way to fix the data store so that it will mount.  I welcome pull requests if you can figure out how to solve the issues.

How EFSS Works
--------------

A minimal EFSS data store consists of four files:  The main encrypted block file, a file containing last updated timestamps for each block in the main file, a hashes file containing a hash of each block (for rapid verification), and a 2KB file containing a unique serial.  If you use 'create.php' to make an EFSS data store, it will also create a PHP file containing the key/IV information that the other command-line tools will use to be able to mount the file system (i.e. decrypt the block file).

EFSS tracks five different block types within the block file:

* First block - The very first block in an EFSS data store.  There is only one block of this type.
* Directory - A linked list of directory entries.  Can point at other directories, files, and symbolic links.
* File - A linked list of file data.  Contains part of a file's data.
* Unused list - A linked list of unused blocks.
* Unused block - Contains random data outside of identifying the block as unused.

Linked lists start with a block number to the next block.  The last node references block zero (an impossible reference) to indicate the termination point of the linked list.

A special type of file known as an "inline file" may be written as part of a directory block to avoid wasting space (i.e. file blocks are not used).  The rule is that the file data must occupy less than 45% of the block size.  If compression is available, the original, uncompressed data could potentially be slightly larger than 45% as long as the compressed data meets the 45% criteria.  The shell can help identify inline files via the 'i' flag.

EFSS also does a lot of directory structure traversal operations to calculate absolute paths.  A directory block cache exists to save blocks in RAM to avoid hitting the disk too frequently.  The relevant directory block cache entry is wiped whenever an associated block is written to disk so that the block is loaded again on the next read operation.  This approach does have a minor performance penalty but guarantees the stability of directory structures.

More Information
----------------

Documentation and examples can be found in the 'docs' directory of this repository.

* [EFSS blocks spec](https://github.com/cubiclesoft/efss/blob/master/docs/efss_blocks_spec.md) - The technical specification on how blocks are stored in EFSS.
* [EFSSIncremental class](https://github.com/cubiclesoft/efss/blob/master/docs/efss_incremental_helpers.md) - A set of helper functions for constructing and managing incrementals.
* [ReadWriteLock class](https://github.com/cubiclesoft/efss/blob/master/docs/read_write_lock.md) - The locking class used by EFSS.
* [WebMutex class](https://github.com/cubiclesoft/efss/blob/master/docs/web_mutex.md) - The underlying mutex class used by ReadWriteLock.
