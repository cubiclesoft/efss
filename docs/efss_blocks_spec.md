EFSS Block Types Specification
==============================

There are five block types that EFSS understands and each type was briefly covered.  This specification covers each block type in detail.  Before beginning though, it is important to mention a few things about the data types that are seen:

* All integers are stored in big-endian format.
* Block numbers are 4-byte integers. Limit is roughly 2^31 blocks.
* Sizes inside blocks are 2-byte integers. Maximum block size is 32KB as a result.
* DateTime field length is dependent upon the timestamp mode of the EFSS data store. 4 bytes for UNIX timestamps, 20 bytes for UTC timestamps. The default timestamp mode is UTC.
* Linked lists have their next block number at the start of the block.

Each block type is actually data encoded into a block as follows:

* 7 bytes - Randomly generated garbage.
* 1 bytes - The type of the block.
* 2 bytes - Size of the data.
* [DATA]
* 20 bytes - SHA1 hash of the data.
* Remaining bytes - Randomly generated garbage.

Doing the math shows that 30 bytes of every block is "wasted" on various bits of verification and anti-pattern bytes.  It really isn't so bad though, especially when data compression enters the picture.

Without further ado, let's dive into the structure of EFSS block types.

First Block
-----------

This is always the first block of any EFSS file system.  The first block's structure is as follows:

* 2 bytes - File system version (should be EFSS_VERSION)
* 2 bytes - Block size of all blocks (kind of redundant verification)
* 1 byte - Directory entry mode
* 4 bytes - Next block number once all unused blocks have been used.
* 1 byte - Timestamp mode
* DateTime - Created timestamp

The first block tells EFSS how to operate.  If any of the first three blocks in EFSS are corrupted, the file system can't be mounted.

Directory Block
---------------

This is a linked list containing part or all of a directory.  Directories store information about links to directories, files, and symbolic links.  The directory block's structure is as follows:

* 4 bytes - Next block number in the linked list

Followed by zero or more encapsulated directory entries as follows:

* 2 bytes - Length of the entry (minus these two bytes)
* 1 byte - Entry type (0 = Directory, 1 = File, 2 = Symbolic Link)
* DateTime - Created timestamp
* 2 bytes - Length of name
* String - Name
* 2 bytes - Permissions and flags (rwxrwxrwx + setuid + setguid + stickybit - aka rwsrwsrwt, files have compression and "inline" flags cirwsrwsrwt)
* 2 bytes - Length of owner name
* String - Owner name
* 2 bytes - Length of group name
* String - Group name

For directories:

* 4 bytes - First block number of child directory

For normal files (not inline):

* 8 bytes - Length of file data (uncompressed)
* 8 bytes - Length of file data
* 4 bytes - First block number of file data

For inline files:

* 4 bytes - Length of file data (uncompressed)
* 2 bytes - Length of file data
* String - File data

For symbolic links:

* 2 bytes - Length of symbolic link target
* String - Symbolic link target

The first (root) directory is always the second block in EFSS.  Directories are the most complex block type in EFSS.  Depending on the first block settings, directories may be compressed.

File Block
----------

This is a linked list containing part or all of a file.  The file block's structure is as follows:

* 4 bytes - Next block number in the linked list
* String - Data

Depending on the file's flags, the data may be compressed.

Unused List Block
-----------------

This is a linked list containing a number of unused blocks.  The unused block's structure is as follows:

* 4 bytes - Next block number in the linked list

Followed by zero or more of:

* 4 bytes - Unused block number

Unused blocks are added and removed in stack order.  To alleviate fragmentation issues, accumulate a lot of block numbers before flushing reverse order block numbers to disk.

Unused Block
------------

There isn't much to say about this block type.  It contains nothing beyond being wrapped as a normal block but with a unique block type.  This is done instead of pure random garbage because `EFSS::CheckFS()` depends on every block being valid.  It also doesn't hurt since most of the block consists of random garbage anyway (i.e. minus the usual structure of a block).
