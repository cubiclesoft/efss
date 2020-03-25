<?php
	// Encrypted File Storage System class
	// (C) 2013 CubicleSoft.  All Rights Reserved.

	require_once "phpseclib/AES.php";
	require_once "read_write_lock.php";
	require_once "random.php";
	require_once "deflate_stream.php";

	define("EFSS_VERSION", 1);

	define("EFSS_TIMESTAMP_UTC", 0);
	define("EFSS_TIMESTAMP_UNIX", 1);

	define("EFSS_MODE_READ", 0);
	define("EFSS_MODE_EXCL", 1);

	define("EFSS_DIRMODE_DEFAULT", 0x00);
	define("EFSS_DIRMODE_COMPRESS", 0x01);
	define("EFSS_DIRMODE_CASE_INSENSITIVE", 0x02);

	define("EFSS_FLAG_COMPRESS", 0x2000);
	define("EFSS_FLAG_INLINE", 0x1000);

	define("EFSS_PERM_O_S", 0x0800);
	define("EFSS_PERM_G_S", 0x0400);
	define("EFSS_PERM_W_T", 0x0200);

	define("EFSS_PERM_O_R", 0x0100);
	define("EFSS_PERM_O_W", 0x0080);
	define("EFSS_PERM_O_X", 0x0040);
	define("EFSS_PERM_G_R", 0x0020);
	define("EFSS_PERM_G_W", 0x0010);
	define("EFSS_PERM_G_X", 0x0008);
	define("EFSS_PERM_W_R", 0x0004);
	define("EFSS_PERM_W_W", 0x0002);
	define("EFSS_PERM_W_X", 0x0001);

	define("EFSS_PERM_ALLOWED_DIR", 0x0FFF);
	define("EFSS_PERM_ALLOWED_FILE", 0x0FFF);
	define("EFSS_PERM_ALLOWED_FILE_INTERNAL", 0x3FFF);
	define("EFSS_PERM_ALLOWED_SYMLINK", 0x0000);

	define("EFSS_COPYMODE_DEFAULT", 0x00);
	define("EFSS_COPYMODE_REAL_SOURCE", 0x01);
	define("EFSS_COPYMODE_REAL_DEST", 0x02);
	define("EFSS_COPYMODE_SYNC_TIMESTAMP", 0x04);
	define("EFSS_COPYMODE_SYNC_DATA", 0x08);

	define("EFSS_COMPRESS_DEFAULT", 0);
	define("EFSS_COMPRESS_NONE", 1);

	define("EFSS_DIRTYPE_DIR", 0);
	define("EFSS_DIRTYPE_FILE", 1);
	define("EFSS_DIRTYPE_SYMLINK", 2);
	define("EFSS_DIRTYPE_ANY", -1);

	define("EFSS_BLOCKTYPE_ANY", "\x00");
	define("EFSS_BLOCKTYPE_FIRST", "\x01");
	define("EFSS_BLOCKTYPE_DIR", "\x02");
	define("EFSS_BLOCKTYPE_FILE", "\x03");
	define("EFSS_BLOCKTYPE_UNUSED_LIST", "\x04");
	define("EFSS_BLOCKTYPE_UNUSED", "\x05");

	// Maximum size of EFSS under PHP is ~8.7TB @ 4KB block sizes.  Maximum size of mounted incremental updates is ~2.2TB @ 4KB block sizes.
	class EFSS
	{
		private $version = EFSS_VERSION, $ts, $rng, $cipher1, $cipher2, $basefile, $blocksize, $dirmode, $timestamp, $defownername, $defgroupname, $mode, $readwritelock, $fp, $fp2, $fp3, $incrementals;
		private $firstblock, $unusedblock, $unusedblockpos, $dirblockcache, $dirnamemapcache, $dirlisttimescache, $dirinsertmapcache, $dirlastgarbagecollect, $basedirinfo, $openfiles, $mounted, $debugfp, $lastwrite, $written;
		private $rdiff, $rdiff_fp, $rdiff_fp2, $rdiff_fp3, $rdiff_fp4, $rdiff_numblocks, $rdiff_maxblocks;

		public function __construct()
		{
			$this->mounted = false;

			if (!defined("EFSS_DEBUG_LOG"))  $this->debugfp = false;
			else  $this->debugfp = fopen(EFSS_DEBUG_LOG, "ab");
		}

		public function __destruct()
		{
			$this->Unmount();
		}

		public static function Translate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			$args[0] = "[EFSS] " . $args[0];

			if (defined("EFSS_DEBUG_LOG"))
			{
				$e = new Exception;
				$trace = str_replace("\n", "\n[EFSS] ", str_replace("\r", "\n", str_replace("\r\n", "\n", $e->getTraceAsString())));
				$args[0] .= "%s";
				$args[] = "\n[EFSS] " . $trace . "\n";
			}

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}

		public function Create($key1, $iv1, $key2, $iv2, $basefile, $lockfile = false, $blocksize = 4096, $timestamp = EFSS_TIMESTAMP_UTC, $dirmode = EFSS_DIRMODE_DEFAULT)
		{
			if (file_exists($basefile) || file_exists($basefile . ".updates") || file_exists($basefile . ".serial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system already exists."), "errorcode" => "already_exists");
			if ($blocksize == 0 || $blocksize % 4096 != 0 || $blocksize > 32768)  return array("success" => false, "error" => EFSS::Translate("The block size must be a multiple of 4096 and able to fit into an 'unsigned short'."), "errorcode" => "invalid_blocksize");
			if (($dirmode & EFSS_DIRMODE_COMPRESS) && (!function_exists("gzcompress") || !function_exists("gzuncompress")))  return array("success" => false, "error" => EFSS::Translate("The compressed directory mode is unsupported because one or more required PHP functions are unavailable."), "errorcode" => "unsupported_dirmode");

			// Initialize the class.
			$this->rng = new CSPRNG();
			$this->cipher1 = new Crypt_AES();
			$this->cipher1->setKey($key1);
			$this->cipher1->setIV($iv1);
			$this->cipher1->disablePadding();
			$this->cipher2 = new Crypt_AES();
			$this->cipher2->setKey($key2);
			$this->cipher2->setIV($iv2);
			$this->cipher2->disablePadding();
			$this->basefile = $basefile;
			$this->blocksize = $blocksize;
			$this->dirmode = $dirmode;
			$this->timestamp = $timestamp;
			$this->defownername = "";
			$this->defgroupname = "";
			if (!$this->Lock($lockfile, EFSS_MODE_EXCL))  return array("success" => false, "error" => EFSS::Translate("Unable to obtain exclusive lock."), "errorcode" => "lock_failed");
			$this->fp = fopen($basefile, "wb");
			if ($this->fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open block file."), "errorcode" => "block_file_open");
			$this->fp2 = fopen($basefile . ".updates", "wb");
			if ($this->fp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open updates file."), "errorcode" => "updates_file_open");
			$this->fp3 = fopen($basefile . ".hashes", "wb");
			if ($this->fp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open hashes file."), "errorcode" => "hashes_file_open");
			$this->incrementals = array();
			$this->rdiff = false;
			$this->ts = time();

			// Write the serial number.
			file_put_contents($basefile . ".serial", bin2hex($this->rng->GetBytes(1024)));

			$this->mounted = true;
			$this->lastwrite = 0;
			$this->written = false;

			// Create the first block.
			$this->firstblock = new EFSS_FirstBlock;
			$this->firstblock->version = $this->version;
			$this->firstblock->blocksize = $this->blocksize;
			$this->firstblock->dirmode = $this->dirmode;
			$this->firstblock->nextblock = 3;
			$this->firstblock->timestamp = $this->timestamp;
			$this->firstblock->created = $this->ts;

			// Write the first block.
			$result = $this->RawWriteBlock($this->firstblock->serialize(), 0, EFSS_BLOCKTYPE_FIRST);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Unable to write the first block."), "errorcode" => "first_block_write", "info" => $result);

			// Create an unused block.
			$this->unusedblock = new EFSS_Unused;
			$this->unusedblock->nextblock = 0;
			$this->unusedblock->unusedblocks = array();

			// Write the unused block.
			$result = $this->RawWriteBlock($this->unusedblock->serialize(), 1, EFSS_BLOCKTYPE_UNUSED_LIST);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Unable to write the root unused block."), "errorcode" => "root_unused_block_write", "info" => $result);

			// Create the root directory block.
			$rootdir = new EFSS_DirEntries;
			$rootdir->timestamp = $this->timestamp;
			$rootdir->nextblock = 0;
			$rootdir->entries = array();

			// Write the root directory block.
			$this->dirblockcache = array();
			$this->dirnamemapcache = array();
			$this->dirlisttimescache = array();
			$this->dirinsertmapcache = array();
			$this->dirlastgarbagecollect = time();
			$result = $this->WriteDirBlock($rootdir, 2, 2);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Unable to write the root directory block."), "errorcode" => "root_dir_block_write", "info" => $result);

			// Unount.
			$result = $this->Unmount();
			if (!$result["success"])  return $result;

			return array("success" => true);
		}

		public function Mount($key1, $iv1, $key2, $iv2, $basefile, $mode, $lockfile = false, $blocksize = 4096, $incrementals = array(), $reversediff = false, $waitforlock = true)
		{
			if (!file_exists($basefile) || !file_exists($basefile . ".updates") || !file_exists($basefile . ".serial") || file_exists($basefile . ".blocknums") || file_exists($basefile . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system does not exist."), "errorcode" => "does_not_exist");
			if ($blocksize == 0 || $blocksize % 4096 != 0 || $blocksize > 32768)  return array("success" => false, "error" => EFSS::Translate("The block size must be a multiple of 4096 and able to fit into an 'unsigned short'."), "errorcode" => "invalid_blocksize");
			if (count($incrementals) && $mode != EFSS_MODE_READ)  return array("success" => false, "error" => EFSS::Translate("The encrypted file storage system can only be opened for reading when using the incrementals option."), "errorcode" => "reading_only");
			if (count($incrementals) && $reversediff)  return array("success" => false, "error" => EFSS::Translate("The encrypted file storage system can't create a reverse diff when using the incrementals option."), "errorcode" => "reverse_diff_error");
			if ($reversediff !== false && $mode == EFSS_MODE_READ)  return array("success" => false, "error" => EFSS::Translate("The encrypted file storage system can't create a reverse diff when opening for reading."), "errorcode" => "writing_only");
			if (file_exists($basefile . ".readonly") && $mode != EFSS_MODE_READ)  return array("success" => false, "error" => EFSS::Translate("The encrypted file storage system can only be opened for reading."), "errorcode" => "reading_only");

			// Initialize the class.
			$this->rng = new CSPRNG();
			$this->cipher1 = new Crypt_AES();
			$this->cipher1->setKey($key1);
			$this->cipher1->setIV($iv1);
			$this->cipher1->disablePadding();
			$this->cipher2 = new Crypt_AES();
			$this->cipher2->setKey($key2);
			$this->cipher2->setIV($iv2);
			$this->cipher2->disablePadding();
			$this->basefile = $basefile;
			$this->blocksize = $blocksize;
			$this->defownername = "";
			$this->defgroupname = "";
			if (!$this->Lock($lockfile, $mode, $waitforlock))  return array("success" => false, "error" => EFSS::Translate("Unable to obtain lock."), "errorcode" => "lock_failed");
			$this->fp = fopen($basefile, ($mode == EFSS_MODE_EXCL ? "r+b" : "rb"));
			if ($this->fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open block file."), "errorcode" => "block_file_open");
			if ($mode == EFSS_MODE_EXCL)
			{
				$this->fp2 = fopen($basefile . ".updates", "r+b");
				if ($this->fp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open updates file."), "errorcode" => "updates_file_open");

				// Read in the last written timestamp.
				$this->lastwrite = EFSS::ConvertFromUTCDateTime(fread($this->fp2, 20));
				$this->written = false;
				fseek($this->fp2, 0);

				// Open the hashes file.
				if (file_exists($basefile . ".hashes"))
				{
					$this->fp3 = fopen($basefile . ".hashes", "r+b");
					if ($this->fp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open hashes file."), "errorcode" => "hashes_file_open");
				}
				else
				{
					$this->fp3 = fopen($basefile . ".hashes", "w+b");
					if ($this->fp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open hashes file."), "errorcode" => "hashes_file_open");

					// Generate hashes.
					$numblocks = (int)(1048576 / $this->blocksize);
					$incblockdata = fread($this->fp, 1048576);
					$incupdates = fread($this->fp2, 20 * $numblocks);
					while ($incblockdata !== false && $incblockdata != "")
					{
						$y = (int)(strlen($incupdates) / 20);
						for ($x = 0; $x < $y; $x++)
						{
							$data = substr($incblockdata, $x * $blocksize, $blocksize);
							$data .= substr($incupdates, $x * 20, 20);

							fwrite($this->fp3, md5($data, true) . sha1($data, true));
						}

						$incblockdata = fread($this->fp, 1048576);
						$incupdates = fread($this->fp2, 20 * $numblocks);
					}
				}
			}
			$this->incrementals = array();
			if (count($incrementals))
			{
				$serial = trim(file_get_contents($basefile . ".serial"));
				$incrementals = array_reverse($incrementals);
				foreach ($incrementals as $filename)
				{
					if (!file_exists($filename) || !file_exists($filename . ".updates") || !file_exists($filename . ".serial") || !file_exists($filename . ".blocknums") || file_exists($filename . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system incremental '%s' does not exist.", $filename), "errorcode" => "does_not_exist");

					$serial2 = trim(file_get_contents($filename . ".serial"));
					if ($serial !== $serial2)  return array("success" => false, "error" => EFSS::Translate("Incremental block file '%s' serial does not match base file serial.", $filename), "errorcode" => "increment_serial_mismatch");

					$fp = fopen($filename, "rb");
					if ($fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental block file '%s'.", $filename), "errorcode" => "increment_block_file_open");

					$fp2 = fopen($filename . ".blocknums", "rb");
					if ($fp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental block numbers file '%s'.", $filename), "errorcode" => "increment_block_nums_file_open");
					fseek($fp2, 0, SEEK_END);
					$size = (int)(ftell($fp2) / 4);
					if ($size <= 250000)
					{
						fclose($fp2);
						$fp2 = file_get_contents($filename . ".blocknums");
					}

					$this->incrementals[] = array($fp, $fp2, $size);
				}
			}
			$this->rdiff = false;
			$this->ts = time();

			$this->mounted = true;

			// Read the first block.
			$result = $this->RawReadBlock(0, EFSS_BLOCKTYPE_FIRST);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("The first block of the encrypted file storage system is corrupt."), "errorcode" => "first_block_corrupt", "info" => $result);

			// Finalize initialization with information from the first block.
			$this->firstblock = new EFSS_FirstBlock;
			if (!$this->firstblock->unserialize($result["data"]))  return array("success" => false, "error" => EFSS::Translate("The first block of the encrypted file storage system is invalid."), "errorcode" => "first_block_failure");
			if ($this->blocksize != $this->firstblock->blocksize)  return array("success" => false, "error" => EFSS::Translate("The first block of the encrypted file storage system is invalid.  Block sizes do not match."), "errorcode" => "first_block_failure");
			$this->dirmode = $this->firstblock->dirmode;
			$this->timestamp = $this->firstblock->timestamp;
			if (($this->dirmode & EFSS_DIRMODE_COMPRESS) && (!function_exists("gzcompress") || !function_exists("gzuncompress")))  return array("success" => false, "error" => EFSS::Translate("The compressed directory mode is unsupported because one or more required PHP functions are unavailable."), "errorcode" => "unsupported_dirmode");

			// Initialize the reverse diff.
			if ($mode != EFSS_MODE_READ && file_exists($basefile . ".rdiff") && file_exists($basefile . ".rdiff.updates"))  $reversediff = true;
			$this->rdiff = ($reversediff !== false);
			if ($this->rdiff)
			{
				if (!file_exists($basefile . ".rdiff") || !file_exists($basefile . ".rdiff.updates") || !file_exists($basefile . ".rdiff.hashes") || !file_exists($basefile . ".rdiff.blockmap") || !file_exists($basefile . ".rdiff.blockinfo"))
				{
					// Create empty block and update files.
					file_put_contents($basefile . ".rdiff", "");
					file_put_contents($basefile . ".rdiff.updates", "");
					file_put_contents($basefile . ".rdiff.hashes", "");

					// Create a fast block mapping file.
					$fp = fopen($basefile . ".rdiff.blockmap", "wb");
					$size = $this->firstblock->nextblock;
					if ($size > 262144)
					{
						$data = str_repeat("\xFF\xFF\xFF\xFF", 262144);
						while ($size > 262144)
						{
							fwrite($fp, $data);
							$size -= 262144;
						}
					}
					$data = str_repeat("\xFF\xFF\xFF\xFF", $size);
					fwrite($fp, $data);
					fclose($fp);

					// Create a block information file.
					file_put_contents($basefile . ".rdiff.blockinfo", "0|" . $this->firstblock->nextblock);
				}

				$this->rdiff_fp = fopen($basefile . ".rdiff", "ab");
				if ($this->rdiff_fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff block file '%s'.", $basefile . ".rdiff"), "errorcode" => "rdiff_block_file_open");

				$this->rdiff_fp2 = fopen($basefile . ".rdiff.updates", "ab");
				if ($this->rdiff_fp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff updates file '%s'.", $basefile . ".rdiff.updates"), "errorcode" => "rdiff_updates_file_open");

				$this->rdiff_fp3 = fopen($basefile . ".rdiff.hashes", "ab");
				if ($this->rdiff_fp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff hashes file '%s'.", $basefile . ".rdiff.hashes"), "errorcode" => "rdiff_hashes_file_open");

				$this->rdiff_fp4 = fopen($basefile . ".rdiff.blockmap", "r+b");
				if ($this->rdiff_fp4 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff block mapping file '%s'.", $basefile . ".rdiff.blockmap"), "errorcode" => "rdiff_block_map_file_open");

				$data = explode("|", trim(file_get_contents($basefile . ".rdiff.blockinfo")));
				$this->rdiff_numblocks = (int)$data[0];
				$this->rdiff_maxblocks = (int)$data[1];
				if ($this->rdiff_maxblocks > $this->firstblock->nextblock)  return array("success" => false, "error" => EFSS::Translate("Reverse diff block info file '%s' is corrupt.", $basefile . ".rdiff.blockinfo"), "errorcode" => "rdiff_block_info_corrupt");
			}

			// Read the last unused block that has available blocks.
			$result = $this->ReloadUnused();
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("An error occurred while loading available unused blocks."), "errorcode" => "unused_reload", "info" => $result);

			// Set the current directory to the root.
			$this->basedirinfo = array();

			// Read the first root directory block.
			$this->dirblockcache = array();
			$this->dirnamemapcache = array();
			$this->dirlisttimescache = array();
			$this->dirinsertmapcache = array();
			$this->dirlastgarbagecollect = time();
			$result = $this->ReadDirBlock(2);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("The root directory block of the encrypted file storage system is corrupt or invalid."), "errorcode" => "root_dir_block_corrupt", "info" => $result);

			// Initialize file handles.
			$this->openfiles = array();

			return array("success" => true);
		}

		public function Unmount()
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			if (is_array($this->openfiles))
			{
				$keys = array_keys($this->openfiles);
				foreach ($keys as $key)  $this->fclose($key);
			}

			if (is_resource($this->fp2))
			{
				// Write the most recent class timestamp to the first block's update.  Allows write-betweens to be detected.
				if ($this->written)
				{
					$this->ts = time();
					$result = $this->RawWriteBlock($this->firstblock->serialize(), 0, EFSS_BLOCKTYPE_FIRST);
					if (!$result["success"])  return $result;
				}

				fclose($this->fp2);
				$this->fp2 = false;
			}

			if (is_resource($this->fp3))  fclose($this->fp3);

			if ($this->rdiff)
			{
				file_put_contents($this->basefile . ".rdiff.blockinfo", $this->rdiff_numblocks . "|" . $this->rdiff_maxblocks);

				fclose($this->rdiff_fp4);
				fclose($this->rdiff_fp3);
				fclose($this->rdiff_fp2);
				fclose($this->rdiff_fp);
			}

			for ($x = 0; $x < count($this->incrementals); $x++)
			{
				if (is_resource($this->incrementals[$x][1]))  fclose($this->incrementals[$x][1]);
				if (is_resource($this->incrementals[$x][0]))  fclose($this->incrementals[$x][0]);
			}
			$this->incrementals = array();

			if (is_resource($this->fp))  fclose($this->fp);

			if (is_object($this->readwritelock))  $this->readwritelock->Unlock();

			$this->mounted = false;

			return array("success" => true);
		}

		public function SetDefaultOwner($ownername)
		{
			$this->defownername = $ownername;
		}

		public function SetDefaultGroup($groupname)
		{
			$this->defgroupname = $groupname;
		}

		public function GetDirMode()
		{
			return $this->dirmode;
		}

		public function getcwd()
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			return array("success" => true, "cwd" => $this->DirInfoToPath($this->basedirinfo));
		}

		public function chdir($dirname)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->LoadPath($dirname, false, EFSS_DIRTYPE_DIR, true);
			if (!$result["success"])  return $result;

			$this->basedirinfo = $result["dirinfo"];

			return array("success" => true);
		}

		public function mkdir($pathname, $mode = false, $recursive = false, $ownername = false, $groupname = false, $created = false)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			// Load the path information.
			$result = $this->LoadPath($pathname, false, EFSS_DIRTYPE_DIR, true);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("'%s' already exists.", $pathname), "errorcode" => "already_exists");
			if ($result["errorcode"] != "path_not_found")  return $result;
			if (!$recursive && $result["dirinfopos"] < count($result["dirinfo"]) - 1)  return $result;

			// Set the owner and group of the new directory.
			if ($ownername === false)
			{
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_O_S)  $ownername = $result["dirinfo"][$result["dirinfopos"] - 1][4]->ownername;
				else  $ownername = $this->defownername;
			}

			if ($groupname === false)
			{
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_G_S)  $ownername = $result["dirinfo"][$result["dirinfopos"] - 1][4]->groupname;
				else  $groupname = $this->defgroupname;
			}

			// Calculate the mode of the new directory.
			if ($mode === false)
			{
				// Owner w/ setuid.
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_O_S)
				{
					$mode = EFSS_PERM_O_S | ($result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & (EFSS_PERM_O_R | EFSS_PERM_O_W | EFSS_PERM_O_X));
				}
				else
				{
					$mode = EFSS_PERM_O_R | EFSS_PERM_O_W | EFSS_PERM_O_X;
				}

				// Group w/ setgid.
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_G_S)
				{
					$mode |= EFSS_PERM_G_S | ($result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & (EFSS_PERM_G_R | EFSS_PERM_G_W | EFSS_PERM_G_X));
				}
				else
				{
					$mode |= EFSS_PERM_G_R | EFSS_PERM_G_X;
				}

				// World w/ sticky bit.
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_W_T)
				{
					$mode |= EFSS_PERM_W_T | ($result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & (EFSS_PERM_W_R | EFSS_PERM_W_W | EFSS_PERM_W_X));
				}
				else
				{
					$mode |= EFSS_PERM_W_R | EFSS_PERM_W_X;
				}
			}

			if ($created === false)  $created = time();

			$dirinfo = $result["dirinfo"];
			$blocknum = $result["blocknum"];
			for ($x = $result["dirinfopos"]; $x < count($dirinfo); $x++)
			{
				$result = $this->FindDirInsertPos($blocknum, $dirinfo[$x]);
				if (!$result["success"])  return $result;

				// Obtain a new block for the new directory.
				$nextblocknum = $this->NextUnusedBlock();
				if (!$nextblocknum["success"])  return $nextblocknum;
				$nextblocknum = $nextblocknum["nextblock"];

				$tempdir = new EFSS_DirEntries;
				$tempdir->timestamp = $this->timestamp;
				$tempdir->nextblock = 0;
				$tempdir->entries = array();

				$result2 = $this->WriteDirBlock($tempdir, $nextblocknum, $nextblocknum);
				if (!$result2["success"])  return $result2;

				// Create the structure.
				$dirfile = new EFSS_DirEntry_DirFile;
				$dirfile->type = EFSS_DIRTYPE_DIR;
				$dirfile->created = $created;
				$dirfile->name = $dirinfo[$x];
				$dirfile->permflags = $mode & EFSS_PERM_ALLOWED_DIR;
				$dirfile->ownername = $ownername;
				$dirfile->groupname = $groupname;
				$dirfile->data = $nextblocknum;

				// Insert the entry.
				array_splice($result["dir"]->entries, $result["pos"], 0, array($dirfile));

				// Write the whole mess to disk.
				$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);
				if (!$result["success"])  return $result;

				$blocknum = $nextblocknum;
			}

			return $result;
		}

		public function rmdir($pathname, $recursive = false)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->LoadPath($pathname);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be removed."), "errorcode" => "root_dir_locked");
			$dirname = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];
			$pathname = $this->DirInfoToPath($result["dirinfo"]);

			// Make sure the directory is empty.
			$result = $this->opendir($pathname);
			if (!$result["success"])  return $result;
			$dir = $result["dir"];
			$dirblocknum = $dir["firstblock"];
			$result = $this->readdir($dir);
			if ($recursive)
			{
				while ($result["success"])
				{
					if ($result["info"]->type == EFSS_DIRTYPE_DIR)
					{
						$result = $this->rmdir($pathname . "/" . $result["name"], true);
						if (!$result["success"])  return $result;
					}
					else if ($result["info"]->type == EFSS_DIRTYPE_FILE)
					{
						$result = $this->unlink($pathname . "/" . $result["name"]);
						if (!$result["success"])  return $result;
					}

					$result = $this->readdir($dir);
				}

				if ($result["errorcode"] != "dir_end")  return $result;
			}
			else
			{
				if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Directory not empty."), "errorcode" => "dir_not_empty");
				if ($result["errorcode"] != "dir_end")  return $result;
			}

			$this->closedir($dir);

			// Load the entire directory block containing this directory.
			$result = $this->FindDirInsertPos($blocknum, $dirname);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Remove the directory entry.
			unset($result["dir"]->entries[$result["pos"]]);

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);
			if (!$result["success"])  return $result;

			// Remove directory blocks.
			$result = $this->FreeLinkedList($dirblocknum);
			if (!$result["success"])  return $result;

			// Recalculate the current directory.
			$result = $this->getcwd();
			$result = $this->chdir($result["cwd"]);
			if (!$result["success"])
			{
				if ($result["errorcode"] != "path_not_found")  return $result;

				$this->basedirinfo = array_slice($result["dirinfo"], 0, $result["dirinfopos"]);
				$result = array("success" => true);
			}

			return $result;
		}

		public function opendir($path)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->LoadPath($path);
			if (!$result["success"])  return $result;
			$path = $this->DirInfoToPath($result["dirinfo"]);

			$y = count($result["dirinfo"]);
			$blocknum = ($y == 0 ? 2 : $result["dirinfo"][$y - 1][1]);

			// Load the first block of the directory.
			$result = $this->ReadDirBlock($blocknum);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Failed to read directory block %u.", $blocknum), "errorcode" => "dir_block_read", "info" => $result);

			// Store the necessary information to navigate the directory.
			$handle = array(
				"firstblock" => $blocknum,
				"currblock" => $blocknum,
				"currdir" => $result["dir"],
				"done" => false
			);

			return array("success" => true, "dir" => $handle, "path" => $path);
		}

		public function readdir(&$handle, $raw = false)
		{
			if (!is_array($handle))  return array("success" => false, "error" => EFSS::Translate("Not a valid handle."), "errorcode" => "invalid_handle");
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($handle["done"])  return array("success" => false, "error" => EFSS::Translate("Reached end of directory entries."), "errorcode" => "dir_end");

			if ($raw)
			{
				$prevhandle = array(
					"firstblock" => $handle["firstblock"],
					"currblock" => $handle["currblock"],
					"currdir" => clone $handle["currdir"],
					"done" => $handle["done"]
				);

				if ($handle["currdir"]->nextblock <= 1)  $handle["done"] = true;
				else
				{
					$blocknum = $handle["currdir"]->nextblock;
					$result = $this->ReadDirBlock($blocknum);
					if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Failed to read directory block %u.", $blocknum), "errorcode" => "dir_block_read", "info" => $result);

					$handle["currblock"] = $blocknum;
					$handle["currdir"] = $result["dir"];
				}

				return array("success" => true, "info" => $prevhandle);
			}
			else
			{
				while (!count($handle["currdir"]->entries) && $handle["currdir"]->nextblock > 1)
				{
					$blocknum = $handle["currdir"]->nextblock;
					$result = $this->ReadDirBlock($blocknum);
					if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Failed to read directory block %u.", $blocknum), "errorcode" => "dir_block_read", "info" => $result);

					$handle["currblock"] = $blocknum;
					$handle["currdir"] = $result["dir"];
				}

				if (!count($handle["currdir"]->entries))
				{
					$handle["done"] = true;

					return array("success" => false, "error" => EFSS::Translate("Reached end of directory entries."), "errorcode" => "dir_end");
				}

				$result = array_shift($handle["currdir"]->entries);
				$this->UpdateNameCache($handle["firstblock"], $result->name, $handle["currblock"]);

				return array("success" => true, "name" => $result->name, "info" => $result);
			}
		}

		public function rewinddir(&$handle)
		{
			if (!is_array($handle))  return array("success" => false, "error" => EFSS::Translate("Not a valid handle."), "errorcode" => "invalid_handle");
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$blocknum = $handle["firstblock"];

			// Load the first block of the directory.
			$result = $this->ReadDirBlock($blocknum);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Failed to read directory block %u.", $blocknum), "errorcode" => "dir_block_read", "info" => $result);

			$handle["currblock"] = $blocknum;
			$handle["currdir"] = $result["dir"];
			$handle["done"] = false;

			return array("success" => true);
		}

		public function closedir(&$handle)
		{
			unset($handle);
		}

		public function chown($dirfile, $ownername)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->realpath($dirfile);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be changed."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Update the owner name.
			$result["dir"]->entries[$result["pos"]]->ownername = $ownername;

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);

			return $result;
		}

		public function chgrp($dirfile, $groupname)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->realpath($dirfile);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be changed."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Update the group name.
			$result["dir"]->entries[$result["pos"]]->groupname = $groupname;

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);

			return $result;
		}

		public function chmod($dirfile, $mode)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->realpath($dirfile);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be changed."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Update the mode.
			if ($result["dir"]->entries[$result["pos"]]->type == EFSS_DIRTYPE_DIR)  $result["dir"]->entries[$result["pos"]]->permflags = ($result["dir"]->entries[$result["pos"]]->permflags & ~EFSS_PERM_ALLOWED_DIR) | ($mode & EFSS_PERM_ALLOWED_DIR);
			else  $result["dir"]->entries[$result["pos"]]->permflags = ($result["dir"]->entries[$result["pos"]]->permflags & ~EFSS_PERM_ALLOWED_FILE) | ($mode & EFSS_PERM_ALLOWED_FILE);

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);

			return $result;
		}

		public function copy($source, $dest, $copymode = EFSS_COPYMODE_DEFAULT)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			// Make sure the source exists.
			$sfp = new EFSS_FileCopyHelper;
			$result = $sfp->Init(($copymode & EFSS_COPYMODE_REAL_SOURCE ? true : $this), $source, "rb");
			if (!$result["success"])  return $result;

			// Get source stat.
			$result = $sfp->GetStat();
			if (!$result["success"])  return $result;
			$sfpstat = $result["stat"];

			// If synchronizing, make sure the destination doesn't match the source before copying.
			$datamatch = false;
			if (($copymode & EFSS_COPYMODE_SYNC_TIMESTAMP) || ($copymode & EFSS_COPYMODE_SYNC_DATA))
			{
				$dfp = new EFSS_FileCopyHelper;
				$result = $dfp->Init(($copymode & EFSS_COPYMODE_REAL_DEST ? true : $this), $dest, "rb");
				if ($result["success"])
				{
					if ($copymode & EFSS_COPYMODE_SYNC_TIMESTAMP)
					{
						// Get destination stat.
						$result = $dfp->GetStat();
						if (!$result["success"])  return $result;
						if ($result["stat"]["mtime"] === $sfpstat["mtime"])  $datamatch = true;
					}

					if (!$datamatch && ($copymode & EFSS_COPYMODE_SYNC_DATA))
					{
						do
						{
							// Compare up to 1MB chunks.
							$data = $sfp->Read(1048576);
							$data2 = $dfp->Read(1048576);
						} while ($data !== false && $data2 !== false && strlen($data) > 0 && strlen($data2) > 0 && $data === $data2);

						unset($dfp);

						$datamatch = ($data === $data2);

						// Reopen the source file if it didn't match the destination.
						if (!$datamatch)
						{
							$result = $sfp->Reopen("rb");
							if (!$result["success"])  return $result;
						}
					}
				}
			}

			if ($datamatch)  unset($sfp);
			else
			{
				// Read in 1MB.
				$data = $sfp->Read(1048576);
				if ($data === false)  return array("success" => false, "error" => EFSS::Translate("An error occurred while reading '%s'.", $source), "errorcode" => "source_read_error");

				// Use file_put_contents() to write the data if less than 1MB (potentially inline).
				if (strlen($data) < 1048576)
				{
					if ($copymode & EFSS_COPYMODE_REAL_DEST)
					{
						if (!file_put_contents($dest, $data))  return array("success" => false, "error" => EFSS::Translate("Error writing to '%s'.", $dest), "errorcode" => "write_error");
					}
					else
					{
						$result = $this->file_put_contents($dest, $data);
						if (!$result["success"])  return $result;
					}

					unset($sfp);
				}
				else
				{
					$dfp = new EFSS_FileCopyHelper;
					$result = $dfp->Init(($copymode & EFSS_COPYMODE_REAL_DEST ? true : $this), $dest, "wb");
					if (!$result["success"])  return $result;

					while (strlen($data) > 0)
					{
						if ($dfp->Write($data) === false)  return array("success" => false, "error" => EFSS::Translate("An error occurred while writing '%s'.", $dest), "errorcode" => "dest_write_error");

						// Copy 1MB chunks.
						$data = $sfp->Read(1048576);
						if ($data === false)  return array("success" => false, "error" => EFSS::Translate("An error occurred while reading '%s'.", $source), "errorcode" => "source_read_error");
					}

					unset($dfp);
					unset($sfp);
				}
			}

			// Open the destination for reading.
			$dfp = new EFSS_FileCopyHelper;
			$result = $dfp->Init(($copymode & EFSS_COPYMODE_REAL_DEST ? true : $this), $dest, "rb");
			if (!$result["success"])  return $result;

			// Set destination stat.
			$result = $dfp->SetStat($sfpstat);
			if (!$result["success"])  return $result;

			unset($dfp);

			return array("success" => true);
		}

		public function file_exists($filename)
		{
			return $this->realpath($filename);
		}

		// Only supports "rb" and "wb" modes.
		public function fopen($filename, $mode)
		{
			if ($mode !== "rb" && $mode !== "wb")  return array("success" => false, "error" => EFSS::Translate("Invalid fopen() mode.  Only 'rb' and 'wb' are supported."), "errorcode" => "fopen_mode");

			// Handle write mode with a separate function.
			if ($mode == "wb")  return $this->fopen_write($filename);

			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			// Locate the target file.
			$result = $this->realpath($filename);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be opened as a file."), "errorcode" => "root_dir_locked");
			$filename = $result["path"];
			if (isset($this->openfiles[$filename]))  return array("success" => false, "error" => EFSS::Translate("File is already open for reading or writing."), "errorcode" => "already_open");
			$fileinfo = $result["dirinfo"][count($result["dirinfo"]) - 1][4];
			if ($fileinfo->type != EFSS_DIRTYPE_FILE)  return array("success" => false, "error" => EFSS::Translate("'%s' is not a file.", $filename), "errorcode" => "not_a_file");

			// Generate a read only file handle.
			if (($fileinfo->permflags & EFSS_FLAG_COMPRESS) && !DeflateStream::IsSupported())  return array("success" => false, "error" => EFSS::Translate("The file cannot be opened because one or more required PHP functions are unavailable."), "errorcode" => "unsupported_filemode");
			$fp = array(
				"write" => false,
				"eof" => false,
				"pos" => 0,
				"fileinfo" => $fileinfo
			);

			if ($fileinfo->permflags & EFSS_FLAG_INLINE)
			{
				$fp["data"] = ($fileinfo->permflags & EFSS_FLAG_COMPRESS ? DeflateStream::Uncompress($fileinfo->data) : $fileinfo->data);
				$fp["origdata"] = $fp["data"];
				$fp["blocknum"] = 0;
			}
			else
			{
				$fp["data"] = "";
				$fp["firstblocknum"] = $fileinfo->data;
				$fp["blocknum"] = $fileinfo->data;
				if ($fileinfo->permflags & EFSS_FLAG_COMPRESS)
				{
					$fp["inflate"] = new DeflateStream;
					$fp["inflate"]->Init("rb");
				}
			}

			$this->openfiles[$filename] = $fp;

			return array("success" => true, "fp" => $filename);
		}

		public function fopen_write($filename, $filemode = 0664, $compress = EFSS_COMPRESS_DEFAULT, $ownername = false, $groupname = false, $created = false, $data = false)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->realpath($filename);
			if (isset($result["path"]))
			{
				$filename = $result["path"];
				if ($result["success"])  $this->unlink($filename);
			}
			if (isset($this->openfiles[$filename]))  return array("success" => false, "error" => EFSS::Translate("File is already open for reading or writing."), "errorcode" => "already_open");

			$filemode = $filemode & EFSS_PERM_ALLOWED_FILE;
			if ($compress == EFSS_COMPRESS_DEFAULT && !DeflateStream::IsSupported())  $compress = EFSS_COMPRESS_NONE;
			if ($compress == EFSS_COMPRESS_DEFAULT)  $filemode |= EFSS_FLAG_COMPRESS;

			// Locate the target directory.
			$result = $this->LoadPath($filename, false, EFSS_DIRTYPE_FILE, true);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("'%s' already exists.", $filename), "errorcode" => "already_exists");
			if ($result["errorcode"] != "path_not_found")  return $result;
			if ($result["dirinfopos"] < count($result["dirinfo"]) - 1)  return $result;
			$blocknum = $result["blocknum"];
			$newfile = $result["dirinfo"][count($result["dirinfo"]) - 1];

			// Set the owner and group of the new file.
			if ($ownername === false)
			{
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_O_S)  $ownername = $result["dirinfo"][$result["dirinfopos"] - 1][4]->ownername;
				else  $ownername = $this->defownername;
			}

			if ($groupname === false)
			{
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_G_S)  $ownername = $result["dirinfo"][$result["dirinfopos"] - 1][4]->groupname;
				else  $groupname = $this->defgroupname;
			}

			if ($created === false)  $created = time();

			// Get the insertion position in the target directory.
			$result = $this->FindDirInsertPos($blocknum, $newfile);
			if (!$result["success"])  return $result;

			// Determine inline status.
			$inline = false;
			if ($data !== false)
			{
				$origsize = strlen($data);

				if ($compress == EFSS_COMPRESS_DEFAULT)
				{
					$data2 = DeflateStream::Compress($data);
					if (strlen($data2) < strlen($data))  $data = $data2;
					else
					{
						// The uncompressed version is smaller.
						$compress = EFSS_COMPRESS_NONE;
						$filemode &= ~EFSS_FLAG_COMPRESS;
					}
				}

				if (strlen($data) <= (int)((double)$this->blocksize * 0.45))
				{
					$filemode |= EFSS_FLAG_INLINE;
					$inline = true;
				}
			}

			// Create the structure.
			$dirfile = new EFSS_DirEntry_DirFile;
			$dirfile->type = EFSS_DIRTYPE_FILE;
			$dirfile->created = (int)$created;
			$dirfile->name = $newfile;
			$dirfile->permflags = $filemode & EFSS_PERM_ALLOWED_FILE_INTERNAL;
			$dirfile->ownername = $ownername;
			$dirfile->groupname = $groupname;
			if ($data !== false)
			{
				$dirfile->fullsize = $origsize;
				$dirfile->disksize = strlen($data);
			}

			if ($inline)  $dirfile->data = $data;
			else
			{
				// Obtain a new block for the new file.
				$nextblocknum = $this->NextUnusedBlock();
				if (!$nextblocknum["success"])  return $nextblocknum;
				$nextblocknum = $nextblocknum["nextblock"];

				$tempdir = new EFSS_File;
				$tempdir->nextblock = 0;
				$tempdir->data = "";

				$result2 = $this->RawWriteBlock($tempdir->serialize(), $nextblocknum, EFSS_BLOCKTYPE_FILE);
				if (!$result2["success"])  return $result2;

				$dirfile->data = $nextblocknum;
			}

			// Insert the entry.
			array_splice($result["dir"]->entries, $result["pos"], 0, array($dirfile));

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);
			if (!$result["success"])  return $result;

			// Inline files can't be written any further.
			if ($filemode & EFSS_FLAG_INLINE)  return array("success" => true);

			// Generate a write file handle.
			$fp = array(
				"write" => true,
				"blocknum" => $nextblocknum,
				"data" => $tempdir,
				"pos" => 0,
				"pos2" => 0,
				"dirblocknum" => $blocknum,
				"fileinfo" => $dirfile,
				"updatedir" => ($data === false)
			);
			if ($data === false && $compress == EFSS_COMPRESS_DEFAULT)
			{
				$fp["deflate"] = new DeflateStream;
				$fp["deflate"]->Init("wb");
			}
			$this->openfiles[$filename] = $fp;

			// Data to be written was too large to inline, so write the data and close the file, which will flush it to disk.
			if ($data !== false)
			{
				$result = $this->fwrite($filename, $data);
				if (!$result["success"])  return $result;

				return $this->fclose($filename);
			}

			return array("success" => true, "fp" => $filename);
		}

		public function fclose($fp)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");

			if ($this->openfiles[$fp]["write"])  $this->internal_fflush($fp, true);

			unset($this->openfiles[$fp]);

			return array("success" => true);
		}

		public function feof($fp)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");

			return array("success" => true, "eof" => $this->openfiles[$fp]["eof"]);
		}

		public function fflush($fp)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if (!$this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for reading only."), "errorcode" => "read_only");

			return $this->internal_fflush($fp);
		}

		private function internal_fflush($fp, $finalize = false)
		{
			if ($finalize && isset($this->openfiles[$fp]["deflate"]))
			{
				$this->openfiles[$fp]["deflate"]->Finalize();
				$data = $this->openfiles[$fp]["deflate"]->Read();
				$this->openfiles[$fp]["pos2"] += strlen($data);
				$this->openfiles[$fp]["data"]->data .= $data;
				unset($this->openfiles[$fp]["deflate"]);
			}

			while (strlen($this->openfiles[$fp]["data"]->data) > $this->blocksize - 30 - 4)
			{
				// Obtain a new block.
				$nextblocknum = $this->NextUnusedBlock();
				if (!$nextblocknum["success"])  return $nextblocknum;
				$nextblocknum = $nextblocknum["nextblock"];

				$tempdir = new EFSS_File;
				$tempdir->nextblock = 0;
				$tempdir->data = "";

				$result = $this->RawWriteBlock($tempdir->serialize(), $nextblocknum, EFSS_BLOCKTYPE_FILE);
				if (!$result["success"])  return $result;

				// Write the current block out to disk.
				$this->openfiles[$fp]["data"]->nextblock = $nextblocknum;
				$data = substr($this->openfiles[$fp]["data"]->data, $this->blocksize - 30 - 4);
				$this->openfiles[$fp]["data"]->data = substr($this->openfiles[$fp]["data"]->data, 0, $this->blocksize - 30 - 4);

				$result = $this->RawWriteBlock($this->openfiles[$fp]["data"]->serialize(), $this->openfiles[$fp]["blocknum"], EFSS_BLOCKTYPE_FILE);
				if (!$result["success"])  return $result;

				// Replace the current block with the next block.
				$this->openfiles[$fp]["blocknum"] = $nextblocknum;
				$tempdir->data = $data;
				$this->openfiles[$fp]["data"] = $tempdir;
			}

			if ($finalize)
			{
				// Write the current block out to disk.
				$result = $this->RawWriteBlock($this->openfiles[$fp]["data"]->serialize(), $this->openfiles[$fp]["blocknum"], EFSS_BLOCKTYPE_FILE);
				if (!$result["success"])  return $result;

				$this->openfiles[$fp]["write"] = false;

				// Update the directory block with file size information.
				if ($this->openfiles[$fp]["updatedir"])
				{
					// Get the insertion position in the target directory.
					$result = $this->FindDirInsertPos($this->openfiles[$fp]["dirblocknum"], $this->openfiles[$fp]["fileinfo"]->name);
					if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("File not found in directory block."), "errorcode" => "file_not_found");
					if ($result["errorcode"] != "already_exists")  return $result;

					// Update file sizes.
					$result["dir"]->entries[$result["pos"]]->fullsize = $this->openfiles[$fp]["pos"];
					$result["dir"]->entries[$result["pos"]]->disksize = $this->openfiles[$fp]["pos2"];

					// Write the whole mess to disk.
					$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);
					if (!$result["success"])  return $result;
				}
			}

			return array("success" => true);
		}

		public function fgetc($fp)
		{
			return $this->fread($fp, 1);
		}

		public function fgetcsv($fp, $delimiter = ",", $enclosure = "\"")
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if ($this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for writing only."), "errorcode" => "write_only");
			if ($this->openfiles[$fp]["eof"])  return array("success" => false, "error" => EFSS::Translate("End of file reached."), "errorcode" => "eof");

			$delimiter = substr($delimiter, 0, 1);
			$enclosure = substr($enclosure, 0, 1);

			$line = array();
			$inside = false;
			$curritem = "";
			do
			{
				$result = $this->fgets($fp, FILE_IGNORE_NEW_LINES);
				if (!$result["success"])
				{
					if ($result["errorcode"] != "eof")  return $result;

					break;
				}

				$y = strlen($result["data"]);
				for ($x = 0; $x < $y; $x++)
				{
					$currchr = substr($result["data"], $x, 1);

					if (!$inside && $currchr == $delimiter)
					{
						$line[] = $curritem;
						$curritem = "";
					}
					else if ($currchr == $enclosure)
					{
						if ($inside && $x < $y - 1 && substr($result["data"], $x + 1, 1) == $enclosure)
						{
							$curritem .= $enclosure;
							$x++;
						}
						else
						{
							$inside = !$inside;
						}
					}
					else
					{
						$curritem .= $currchr;
					}
				}

				if ($inside)  $curritem .= "\r\n";
			} while ($inside);

			$line[] = $curritem;

			return array("success" => true, "line" => $line);
		}

		public function fgets($fp, $flags = 0)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if ($this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for writing only."), "errorcode" => "write_only");
			if ($this->openfiles[$fp]["eof"])  return array("success" => false, "error" => EFSS::Translate("End of file reached."), "errorcode" => "eof");

			$origsize = strlen($this->openfiles[$fp]["data"]);
			while (strpos($this->openfiles[$fp]["data"], "\r") === false && strpos($this->openfiles[$fp]["data"], "\n") === false)
			{
				$result = $this->ReadMoreFileData($fp);
				if (!$result["success"])
				{
					if ($result["errorcode"] != "eof")  return $result;

					break;
				}
			}

			$rpos = strpos($this->openfiles[$fp]["data"], "\r");
			$npos = strpos($this->openfiles[$fp]["data"], "\n");
			if ($rpos !== false && $npos === false && $rpos == strlen($this->openfiles[$fp]["data"]) - 1)
			{
				$result = $this->ReadMoreFileData($fp);
				if (!$result["success"] && $result["errorcode"] != "eof")  return $result;

				$npos = strpos($this->openfiles[$fp]["data"], "\n");
			}

			if ($rpos !== false && $npos !== false)
			{
				if ($rpos > $npos)  $rpos = false;
				else if ($rpos < $npos - 1)  $npos = false;
			}

			if ($rpos !== false && $npos !== false)  $len = $npos + 1;
			else if ($rpos !== false)  $len = $rpos + 1;
			else if ($npos !== false)  $len = $npos + 1;
			else  $len = strlen($this->openfiles[$fp]["data"]);

			$data = (string)substr($this->openfiles[$fp]["data"], 0, $len);
			$this->openfiles[$fp]["data"] = substr($this->openfiles[$fp]["data"], $len);
			if ($origsize == 0 && strlen($data) == 0)  $this->openfiles[$fp]["eof"] = true;
			$this->openfiles[$fp]["pos"] += strlen($data);

			if ($flags & FILE_IGNORE_NEW_LINES)
			{
				if ($rpos !== false)  $data = substr($data, 0, -1);
				if ($npos !== false)  $data = substr($data, 0, -1);
			}

			return array("success" => true, "data" => $data);
		}

		public function file_get_contents($filename)
		{
			$result = $this->fopen($filename, "rb");
			if (!$result["success"])  return $result;

			$fp = $result["fp"];
			$data = "";
			do
			{
				$result = $this->fread($fp, 1048576);
				if (!$result["success"])  return $result;

				$data .= $result["data"];
			} while (!$this->openfiles[$fp]["eof"]);

			$this->fclose($fp);

			return array("success" => true, "data" => $data);
		}

		public function file_put_contents($filename, $data)
		{
			return $this->fopen_write($filename, 0664, EFSS_COMPRESS_DEFAULT, false, false, time(), (string)$data);
		}

		public function file($filename, $flags = 0)
		{
			$result = $this->fopen($filename, "rb");
			if (!$result["success"])  return $result;
			$fp = $result["fp"];

			$lines = array();
			do
			{
				$result = $this->fgets($fp, ($flags & FILE_IGNORE_NEW_LINES));
				if (!$result["success"])
				{
					if ($result["errorcode"] != "eof")  return $result;

					break;
				}

				if (($flags & FILE_SKIP_EMPTY_LINES) && rtrim($result["data"]) == "")  continue;

				$lines[] = $result["data"];
			} while (1);

			$this->fclose($fp);

			return array("success" => true, "lines" => $lines);
		}

		public function filectime($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->GetFileInfo($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "created" => $result["fileinfo"]->created);
		}

		public function filegroupname($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->GetFileInfo($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "groupname" => $result["fileinfo"]->groupname);
		}

		public function fileownername($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->GetFileInfo($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "ownername" => $result["fileinfo"]->ownername);
		}

		public function fileinode($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->GetFileInfo($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "inode" => (is_string($result["fileinfo"]->data) ? 0 : $result["fileinfo"]->data));
		}

		public function fileperms($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->GetFileInfo($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "perms" => $result["fileinfo"]->permflags);
		}

		public function filesize($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->GetFileInfo($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "fullsize" => $result["fileinfo"]->fullsize, "disksize" => $result["fileinfo"]->disksize);
		}

		public function filetype($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			if (isset($this->openfiles[$filename]))  return array("success" => true, "type" => "file");

			$result = $this->LoadPath($filename, false, EFSS_DIRTYPE_ANY, true, true);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => true, "type" => "dir", "name" => "");
			$name = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$fileinfo = $result["dirinfo"][count($result["dirinfo"]) - 1][4];

			if ($fileinfo->type == EFSS_DIRTYPE_DIR)  return array("success" => true, "type" => "dir", "name" => $name);
			else if ($fileinfo->type == EFSS_DIRTYPE_FILE)  return array("success" => true, "type" => "file", "name" => $name);
			else if ($fileinfo->type == EFSS_DIRTYPE_SYMLINK)  return array("success" => true, "type" => "link", "name" => $name);

			return array("success" => true, "type" => "unknown", "name" => $name);
		}

		public function fpassthru($fp)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if ($this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for writing only."), "errorcode" => "write_only");
			if ($this->openfiles[$fp]["eof"])  return array("success" => false, "error" => EFSS::Translate("End of file reached."), "errorcode" => "eof");

			$startpos = $this->openfiles[$fp]["pos"];
			do
			{
				$result = $this->fread($fp, 65536);
				if (!$result["success"])  return $result;

				echo $result["data"];
			} while (!$this->openfiles[$fp]["eof"]);

			return array("success" => true, "read" => $this->openfiles[$fp]["pos"] - $startpos);
		}

		public function fputcsv($fp, $fields, $delimiter = ",", $enclosure = "\"")
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if (!$this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for reading only."), "errorcode" => "read_only");

			foreach ($fields as $num => $field)
			{
				$fields[$num] = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure;
			}
			$fields = implode($delimiter, $fields) . "\r\n";

			return $this->fwrite($fp, $fields);
		}

		public function fwrite($fp, $data)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if (!$this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for reading only."), "errorcode" => "read_only");

			// Full size.
			$y = strlen($data);
			$this->openfiles[$fp]["pos"] += $y;

			// Apply deflate compression to the data stream.
			if (isset($this->openfiles[$fp]["deflate"]))
			{
				$this->openfiles[$fp]["deflate"]->Write($data);
				$data = $this->openfiles[$fp]["deflate"]->Read();
			}

			// Disk size.
			$y2 = strlen($data);
			$this->openfiles[$fp]["pos2"] += $y2;

			// Write the data to the buffer and flush it if there is enough data to write to disk.
			$this->openfiles[$fp]["data"]->data .= $data;
			if (strlen($this->openfiles[$fp]["data"]->data) >= $this->blocksize - 30 - 4)
			{
				$result = $this->fflush($fp);
				if (!$result["success"])  return $result;
			}

			return array("success" => true, "len" => $y, "minlen" => $y2);
		}

		public function fread($fp, $len)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if ($this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for writing only."), "errorcode" => "write_only");
			if ($this->openfiles[$fp]["eof"])  return array("success" => false, "error" => EFSS::Translate("End of file reached."), "errorcode" => "eof");
			if ($len < 0)  return array("success" => false, "error" => EFSS::Translate("Invalid length."), "errorcode" => "invalid_length");
			if ($len == 0)  return array("success" => true, "data" => "");

			$origsize = strlen($this->openfiles[$fp]["data"]);
			while (strlen($this->openfiles[$fp]["data"]) < $len)
			{
				$result = $this->ReadMoreFileData($fp);
				if (!$result["success"])
				{
					if ($result["errorcode"] != "eof")  return $result;

					break;
				}
			}

			$data = (string)substr($this->openfiles[$fp]["data"], 0, $len);
			$this->openfiles[$fp]["data"] = substr($this->openfiles[$fp]["data"], $len);
			if ($origsize == 0 && strlen($data) == 0)  $this->openfiles[$fp]["eof"] = true;
			$this->openfiles[$fp]["pos"] += strlen($data);

			return array("success" => true, "data" => $data);
		}

		private function ReadMoreFileData($fp)
		{
			if ($this->openfiles[$fp]["blocknum"] == 0 && !isset($this->openfiles[$fp]["inflate"]))  return array("success" => false, "error" => EFSS::Translate("End of file reached."), "errorcode" => "eof");

			if ($this->openfiles[$fp]["blocknum"] == 0 && isset($this->openfiles[$fp]["inflate"]))
			{
				$this->openfiles[$fp]["inflate"]->Finalize();
				$this->openfiles[$fp]["data"] .= $this->openfiles[$fp]["inflate"]->Read();

				unset($this->openfiles[$fp]["inflate"]);
			}
			else
			{
				$result = $this->RawReadBlock($this->openfiles[$fp]["blocknum"], EFSS_BLOCKTYPE_FILE);
				if (!$result["success"])  return $result;

				$fileinfo = new EFSS_File;
				if (!$fileinfo->unserialize($result["data"]))  return array("success" => false, "error" => EFSS::Translate("File block %u of the encrypted file storage system is invalid.", $blocknum), "errorcode" => "file_block_failure");
				$this->openfiles[$fp]["blocknum"] = $fileinfo->nextblock;

				if (isset($this->openfiles[$fp]["inflate"]))
				{
					$this->openfiles[$fp]["inflate"]->Write($fileinfo->data);
					$this->openfiles[$fp]["data"] .= $this->openfiles[$fp]["inflate"]->Read();
				}
				else
				{
					$this->openfiles[$fp]["data"] .= $fileinfo->data;
				}
			}

			return array("success" => true);
		}

		public function fscanf($fp, $format)
		{
			$result = $this->fgets($fp);
			if (!$result["success"])  return $result;

			$result = call_user_func_array("sscanf", array_merge(array($result["data"], $format), func_get_args()));

			return array("success" => true, "data" => $result);
		}

		public function fseek($fp, $offset, $whence = SEEK_SET)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if ($this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for writing only."), "errorcode" => "write_only");

			if ($whence == SEEK_END)
			{
				if ($offset < 0)  $offset = 0;
				$offset = $this->openfiles[$fp]["fileinfo"]->fullsize - $offset;
				if ($offset < 0)  $offset = 0;
				$whence = SEEK_SET;
			}

			if ($whence == SEEK_CUR)
			{
				$offset = $this->openfiles[$fp]["pos"] + $offset;
				if ($offset < 0)  $offset = 0;
				if ($offset > $this->openfiles[$fp]["fileinfo"]->fullsize)  $offset = $this->openfiles[$fp]["fileinfo"]->fullsize;
				$whence = SEEK_SET;
			}

			if ($whence != SEEK_SET)  return array("success" => false, "error" => EFSS::Translate("Invalid whence."), "errorcode" => "invlaid_whence");

			$this->openfiles[$fp]["eof"] = false;

			if ($offset < 0)  return array("success" => false, "error" => EFSS::Translate("Invalid offset."), "errorcode" => "invalid_offset");
			if ($offset >= $this->openfiles[$fp]["pos"])  $offset -= $this->openfiles[$fp]["pos"];
			else
			{
				$result = $this->rewind($fp);
				if (!$result["success"])  return $result;
			}

			// Read in 65536 byte chunks until offset is less than the desired amount.
			while ($offset > 65536)
			{
				$result = $this->fread($fp, 65536);
				if (!$result["success"])
				{
					if ($result["errorcode"] != "eof")  return $result;

					break;
				}

				$offset -= 65536;
			}

			if ($offset <= 65536)
			{
				$result = $this->fread($fp, $offset);
				if (!$result["success"] && $result["errorcode"] != "eof")  return $result;
			}

			return array("success" => true);
		}

		public function fstat($fp)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");

			return $this->stat($fp);
		}

		public function ftell($fp)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");

			return array("success" => true, "pos" => $this->openfiles[$fp]["pos"]);
		}

		public function glob($path, $pattern, $flags = 0)
		{
			$result = $this->opendir($path);
			if (!$result["success"])  return $result;
			$dir = $result["dir"];

			$matches = array();
			$result = $this->readdir($dir);
			while ($result["success"])
			{
				if ((!($flags & GLOB_ONLYDIR) || $result["info"]->type == EFSS_DIRTYPE_DIR) && preg_match($pattern, $result["name"]))
				{
					if (($flags & GLOB_MARK) && $result["info"]->type == EFSS_DIRTYPE_DIR)  $result["name"] .= "/";
					$matches[] = $result["name"];
				}

				$result = $this->readdir($dir);
			}

			if ($result["errorcode"] != "dir_end")  return $result;

			$this->closedir($dir);

			return array("success" => true, "matches" => $matches, "pattern" => $pattern);
		}

		public function is_dir($filename)
		{
			$result = $this->filetype($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "result" => ($result["type"] == "dir"));
		}

		public function is_file($filename)
		{
			$result = $this->filetype($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "result" => ($result["type"] == "file"));
		}

		public function is_link($filename)
		{
			$result = $this->filetype($filename);
			if (!$result["success"])  return $result;

			return array("success" => true, "result" => ($result["type"] == "link"));
		}

		public function is_readable($filename)
		{
			return $this->file_exists($filename);
		}

		public function is_writable($filename)
		{
			return $this->file_exists($filename);
		}

		public function is_writeable($filename)
		{
			return $this->is_writable($filename);
		}

		public function lchgrp($filename, $groupname)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->LoadPath($filename, false, EFSS_DIRTYPE_ANY, true, true);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be changed."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Update the group name.
			$result["dir"]->entries[$result["pos"]]->groupname = $groupname;

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);

			return $result;
		}

		public function lchown($filename, $ownername)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->LoadPath($filename, false, EFSS_DIRTYPE_ANY, true, true);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be changed."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Update the owner name.
			$result["dir"]->entries[$result["pos"]]->ownername = $ownername;

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);

			return $result;
		}

		public function linkinfo($path)
		{
			return $this->is_link($path);
		}

		public function lstat($filename)
		{
			return $this->internal_stat($filename, true);
		}

		private function internal_stat($filename, $lastsymlink)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->LoadPath($filename, false, EFSS_DIRTYPE_ANY, true, $lastsymlink);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))
			{
				// Root directory.
				$stat = array(
					0 => 1,
					"dev" => 1,
					1 => 2,
					"ino" => 2,
					2 => 0777,
					"mode" => 0777,
					3 => 0,
					"nlink" => 0,
					4 => "",
					"uname" => "",
					5 => "",
					"gname" => "",
					6 => 0,
					"rdev" => 0,
					7 => -1,
					"size" => -1,
					8 => -1,
					"atime" => -1,
					9 => $this->firstblock->created,
					"mtime" => $this->firstblock->created,
					10 => $this->firstblock->created,
					"ctime" => $this->firstblock->created,
					11 => $this->blocksize,
					"blksize" => $this->blocksize,
					12 => -1,
					"blocks" => -1,
				);

				return array("success" => true, "stat" => $stat);
			}

			$fileinfo = $result["dirinfo"][count($result["dirinfo"]) - 1][4];

			$stat = array(
				0 => 1,
				"dev" => 1,
				1 => (is_string($fileinfo->data) ? 0 : $fileinfo->data),
				"ino" => (is_string($fileinfo->data) ? 0 : $fileinfo->data),
				2 => $fileinfo->permflags,
				"mode" => $fileinfo->permflags,
				3 => 0,
				"nlink" => 0,
				4 => $fileinfo->ownername,
				"uname" => $fileinfo->ownername,
				5 => $fileinfo->groupname,
				"gname" => $fileinfo->groupname,
				6 => 0,
				"rdev" => 0,
				7 => $fileinfo->fullsize,
				"size" => $fileinfo->fullsize,
				8 => -1,
				"atime" => -1,
				9 => $fileinfo->created,
				"mtime" => $fileinfo->created,
				10 => $fileinfo->created,
				"ctime" => $fileinfo->created,
				11 => $this->blocksize,
				"blksize" => $this->blocksize,
				12 => -1,
				"blocks" => -1,
			);

			return array("success" => true, "stat" => $stat);
		}

		public function parse_ini_file($filename, $process_sections = false, $scanner_mode = INI_SCANNER_NORMAL)
		{
			$result = $this->file_get_contents($filename);
			if (!$result["success"])  return $result;

			return $this->parse_ini_string($result["data"], $process_sections, $scanner_mode);
		}

		public function parse_ini_string($str, $process_sections = false, $scanner_mode = INI_SCANNER_NORMAL)
		{
			return array("success" => true, "data" => parse_ini_string($str, $process_sections, $scanner_mode));
		}

		public function readfile($filename)
		{
			$result = $this->fopen($filename, "rb");
			if (!$result["success"])  return $result;

			$fp = $result["fp"];
			$result = $this->fpassthru($fp);

			$this->fclose($fp);

			return $result;
		}

		public function readlink($path)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			if (isset($this->openfiles[$path]))  return array("success" => false, "error" => EFSS::Translate("Not a symbolic link."), "errorcode" => "not_symlink");

			$result = $this->LoadPath($path, false, EFSS_DIRTYPE_ANY, true, true);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Not a symbolic link."), "errorcode" => "not_symlink");
			$fileinfo = $result["dirinfo"][count($result["dirinfo"]) - 1][4];

			if ($fileinfo->type != EFSS_DIRTYPE_SYMLINK)  return array("success" => false, "error" => EFSS::Translate("Not a symbolic link."), "errorcode" => "not_symlink");

			return array("success" => true, "link" => $fileinfo->data);
		}

		public function realpath($path)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->LoadPath($path, false, EFSS_DIRTYPE_ANY, true);
			if (isset($result["dirinfo"]))
			{
				$result["path"] = $this->DirInfoToPath($result["dirinfo"]);
				if ($result["success"])
				{
					if (!count($result["dirinfo"]))  $result["type"] = "dir";
					else
					{
						$type = $result["dirinfo"][count($result["dirinfo"]) - 1][4]->type;

						if ($type == EFSS_DIRTYPE_DIR)  $result["type"] = "dir";
						else if ($type == EFSS_DIRTYPE_FILE)  $result["type"] = "file";
						else if ($type == EFSS_DIRTYPE_SYMLINK)  $result["type"] = "link";
						else  $result["type"] = "unknown";
					}
				}
			}

			return $result;
		}

		public function rename($oldpath, $destpath)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			// Remove the existing path.
			$result = $this->LoadPath($oldpath, false, EFSS_DIRTYPE_ANY, true, true);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be renamed."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Detach the original entry.
			$entry = $result["dir"]->entries[$result["pos"]];
			$origresult = $result;
			$origresult["dir"] = clone $origresult["dir"];
			unset($result["dir"]->entries[$result["pos"]]);

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);
			if (!$result["success"])  return $result;

			// From this point on, failure states have to restore the original directory.
			// Find the destination.
			$result = $this->LoadPath($destpath, false, EFSS_DIRTYPE_ANY, true, true);
			if ($result["success"])
			{
				$this->WriteDirBlock($origresult["dir"], $origresult["blocknum"], $origresult["firstblock"]);

				return array("success" => false, "error" => EFSS::Translate("'%s' already exists.", $destpath), "errorcode" => "already_exists");
			}
			if ($result["errorcode"] != "path_not_found" || $result["dirinfopos"] < count($result["dirinfo"]) - 1)
			{
				$this->WriteDirBlock($origresult["dir"], $origresult["blocknum"], $origresult["firstblock"]);

				return $result;
			}
			$blocknum = $result["blocknum"];
			$newname = $result["dirinfo"][count($result["dirinfo"]) - 1];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $newname);
			if (!$result["success"])
			{
				$this->WriteDirBlock($origresult["dir"], $origresult["blocknum"], $origresult["firstblock"]);

				return $result;
			}

			// Insert the entry.
			$entry->name = $newname;
			array_splice($result["dir"]->entries, $result["pos"], 0, array($entry));

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);
			if (!$result["success"])  $this->WriteDirBlock($origresult["dir"], $origresult["blocknum"], $origresult["firstblock"]);

			return $result;
		}

		public function rewind($fp)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (!isset($this->openfiles[$fp]))  return array("success" => false, "error" => EFSS::Translate("Invalid file handle."), "errorcode" => "invalid_handle");
			if ($this->openfiles[$fp]["write"])  return array("success" => false, "error" => EFSS::Translate("File is open for writing only."), "errorcode" => "write_only");

			$this->openfiles[$fp]["eof"] = false;
			$this->openfiles[$fp]["pos"] = 0;

			if (isset($this->openfiles[$fp]["origdata"]))  $this->openfiles[$fp]["data"] = $this->openfiles[$fp]["origdata"];
			else
			{
				$this->openfiles[$fp]["data"] = "";
				$this->openfiles[$fp]["blocknum"] = $this->openfiles[$fp]["firstblocknum"];
				if ($this->openfiles[$fp]["fileinfo"]->permflags & EFSS_FLAG_COMPRESS)
				{
					$this->openfiles[$fp]["inflate"] = new DeflateStream;
					$this->openfiles[$fp]["inflate"]->Init("rb");
				}
			}

			return array("success" => true);
		}

		public function stat($filename)
		{
			return $this->internal_stat($filename, false);
		}

		public function symlink($target, $link, $ownername = false, $groupname = false, $created = false)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$result = $this->LoadPath($link, false, EFSS_DIRTYPE_ANY, true, true);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("'%s' already exists.", $link), "errorcode" => "already_exists");
			if ($result["errorcode"] != "path_not_found")  return $result;
			if ($result["dirinfopos"] < count($result["dirinfo"]) - 1)  return $result;
			$blocknum = $result["blocknum"];
			$newlink = $result["dirinfo"][count($result["dirinfo"]) - 1];

			// Set the owner and group of the new file.
			if ($ownername === false)
			{
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_O_S)  $ownername = $result["dirinfo"][$result["dirinfopos"] - 1][4]->ownername;
				else  $ownername = $this->defownername;
			}

			if ($groupname === false)
			{
				if ($result["dirinfopos"] > 1 && $result["dirinfo"][$result["dirinfopos"] - 1][4]->permflags & EFSS_PERM_G_S)  $ownername = $result["dirinfo"][$result["dirinfopos"] - 1][4]->groupname;
				else  $groupname = $this->defgroupname;
			}

			if ($created === false)  $created = time();

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $newlink);
			if (!$result["success"])  return $result;

			// Create the structure.
			$dirfile = new EFSS_DirEntry_DirFile;
			$dirfile->type = EFSS_DIRTYPE_SYMLINK;
			$dirfile->created = (int)$created;
			$dirfile->name = $newlink;
			$dirfile->permflags = 0777;
			$dirfile->ownername = $ownername;
			$dirfile->groupname = $groupname;
			$dirfile->data = $target;

			// Insert the entry.
			array_splice($result["dir"]->entries, $result["pos"], 0, array($dirfile));

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);

			return $result;
		}

		public function tempnam($path, $prefix, $suffix = "")
		{
			if ($path == "")  $path = ".";

			$result = $this->opendir($path);
			if (!$result["success"])  return $result;
			$dir = $result["dir"];
			$blocknum = $dir["firstblock"];

			$result = $this->realpath($path);
			$path = $result["path"];
			if ($path == "/")  $path = "";

			do
			{
				$filename = $prefix . bin2hex($this->rng->GetBytes(32)) . $suffix;

				$result = $this->FindDirInsertPos($blocknum, $filename);
				if ($result["success"])  $exists = false;
				else if ($result["errorcode"] != "already_exists")  return $result;
				else  $exists = true;
			} while ($exists);

			return array("success" => true, "filename" => $path . "/" . $filename);
		}

		public function touch($filename, $time = false)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			if ($time === false)  $time = time();
			$result = $this->LoadPath($filename, false, EFSS_DIRTYPE_ANY, true, true);
			if (!$result["success"])
			{
				if ($result["errorcode"] != "path_not_found" || $result["dirinfopos"] < count($result["dirinfo"]) - 1)  return $result;

				return $this->fopen_write($filename, 0664, EFSS_COMPRESS_DEFAULT, false, false, $time, "");
			}
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Root directory can't be changed."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Update the created timestamp.
			$result["dir"]->entries[$result["pos"]]->created = (int)$time;

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);

			return $result;
		}

		public function unlink($filename)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$result = $this->LoadPath($filename, false, EFSS_DIRTYPE_ANY, true, true);
			if (!$result["success"])  return $result;
			if (!count($result["dirinfo"]))  return array("success" => false, "error" => EFSS::Translate("Unable to unlink the root directory."), "errorcode" => "root_dir_locked");
			$filename = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
			$blocknum = $result["dirinfo"][count($result["dirinfo"]) - 1][3];

			// Load the entire directory block.
			$result = $this->FindDirInsertPos($blocknum, $filename);
			if ($result["success"])  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");
			if ($result["errorcode"] != "already_exists")  return $result;

			// Process the type.
			$entry = $result["dir"]->entries[$result["pos"]];
			$type = $entry->type;
			if ($type == EFSS_DIRTYPE_DIR)  return array("success" => false, "error" => EFSS::Translate("Unable to unlink a directory."), "errorcode" => "directory_unlink");
			if ($type != EFSS_DIRTYPE_FILE && $type != EFSS_DIRTYPE_SYMLINK)  return array("success" => false, "error" => EFSS::Translate("Unknown directory entry type."), "errorcode" => "impossible");

			// Remove the directory entry.
			unset($result["dir"]->entries[$result["pos"]]);

			// Write the whole mess to disk.
			$result = $this->WriteDirBlock($result["dir"], $result["blocknum"], $result["firstblock"]);
			if (!$result["success"])  return $result;

			// Remove file blocks.
			if ($type == EFSS_DIRTYPE_FILE && !($entry->permflags & EFSS_FLAG_INLINE))
			{
				$result = $this->FreeLinkedList($entry->data);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function GetFileInfo($filename)
		{
			// If the file is already open, don't bother looking it up.
			if (isset($this->openfiles[$filename]))  $fileinfo = $this->openfiles[$filename]["fileinfo"];
			else
			{
				// Locate the target file.
				$result = $this->realpath($filename);
				if (!$result["success"])  return $result;
				if (count($result["dirinfo"]))  $fileinfo = $result["dirinfo"][count($result["dirinfo"]) - 1][4];
				else
				{
					$fileinfo = new EFSS_DirEntry_DirFile;
					$fileinfo->type = EFSS_DIRTYPE_DIR;
					$fileinfo->created = $this->firstblock->created;
					$fileinfo->name = "";
					$fileinfo->permflags = 0777;
					$fileinfo->ownername = "";
					$fileinfo->groupname = "";
					$fileinfo->data = 2;
					$fileinfo->fullsize = 0;
					$fileinfo->disksize = 0;
				}
			}

			return array("success" => true, "fileinfo" => $fileinfo);
		}

		public function CheckFS($blockcallbackfunc = false, $filecallbackfunc = false)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");

			$startts = microtime(true);

			$mask = array(0x80, 0x40, 0x20, 0x10, 0x08, 0x04, 0x02, 0x01);

			// Allocate enough RAM to represent # of blocks / 8.
			$numblocks = $this->firstblock->nextblock;
			$referenced = str_repeat("\x00", (int)($numblocks / 8));
			if ($numblocks % 8 != 0)
			{
				$byte = 0x00;
				$pos = $numblocks;
				while ($pos % 8 != 0)
				{
					$byte |= $mask[$pos % 8];
					$pos++;
				}

				$referenced .= chr($byte);
			}
			$referenced[0] = chr(ord($referenced[0]) | 0xE0);

			// Read all blocks in the file system.
			$ts = time();
			for ($x = 0; $x < $numblocks; $x++)
			{
				$result = $this->RawReadBlock($x, EFSS_BLOCKTYPE_ANY);
				if (!$result["success"])  return $result;

				if ($result["type"] === EFSS_BLOCKTYPE_FIRST)
				{
					if ($x > 0)  return array("success" => false, "error" => EFSS::Translate("Invalid 'firstblock' found at block %u.", $x), "errorcode" => "invalid_firstblock");
				}
				else if ($result["type"] === EFSS_BLOCKTYPE_DIR || $result["type"] === EFSS_BLOCKTYPE_FILE || $result["type"] === EFSS_BLOCKTYPE_UNUSED_LIST)
				{
					if (strlen($result["data"]) < 4)  return array("success" => false, "error" => EFSS::Translate("Block %u of the encrypted file storage system is invalid.", $x), "errorcode" => "block_failure");

					// Save a few cycles by only processing 4 bytes of data.
					$x2 = EFSS::UnpackInt(substr($result["data"], 0, 4));
					if ($x2 > 0)
					{
						if ($x2 > $numblocks || $x2 < 3 || $x2 == $x)  return array("success" => false, "error" => EFSS::Translate("Block %u references an out of range or invalid block %u.", $x, $x2), "errorcode" => "block_out_of_range");

						$byte = ord($referenced[(int)($x2 / 8)]);
						$pos = $x2 % 8;
						if ($byte & $mask[$pos])  return array("success" => false, "error" => EFSS::Translate("Block %u references an already referenced block %u.", $x, $x2), "errorcode" => "block_already_referenced");

						$byte |= $mask[$pos];
						$referenced[(int)($x2 / 8)] = chr($byte);
					}

					if ($result["type"] === EFSS_BLOCKTYPE_DIR)
					{
						$result = $this->ReadDirBlock($x);
						if (!$result["success"])  return $result;

						foreach ($result["dir"]->entries as $entry)
						{
							if ($entry->type == EFSS_DIRTYPE_DIR)
							{
								if ($entry->data > $numblocks || $entry->data < 3 || $entry->data == $x)  return array("success" => false, "error" => EFSS::Translate("Directory block %u references an out of range or invalid block %u with name '%s'.", $x, $entry->data, $entry->name), "errorcode" => "block_out_of_range");

								$byte = ord($referenced[(int)($entry->data / 8)]);
								$pos = $entry->data % 8;
								if ($byte & $mask[$pos])  return array("success" => false, "error" => EFSS::Translate("Directory block %u references an already referenced block %u with name '%s'.", $x, $entry->data, $entry->name), "errorcode" => "block_already_referenced");

								$byte |= $mask[$pos];
								$referenced[(int)($entry->data / 8)] = chr($byte);
							}
							else if ($entry->type == EFSS_DIRTYPE_FILE)
							{
								if (!($entry->permflags & EFSS_FLAG_INLINE))
								{
									if ($entry->data > $numblocks || $entry->data < 3 || $entry->data == $x)  return array("success" => false, "error" => EFSS::Translate("File block %u references an out of range or invalid block %u with name '%s'.", $x, $entry->data, $entry->name), "errorcode" => "block_out_of_range");

									$byte = ord($referenced[(int)($entry->data / 8)]);
									$pos = $entry->data % 8;
									if ($byte & $mask[$pos])  return array("success" => false, "error" => EFSS::Translate("File block %u references an already referenced block %u with name '%s'.", $x, $entry->data, $entry->name), "errorcode" => "block_already_referenced");

									$byte |= $mask[$pos];
									$referenced[(int)($entry->data / 8)] = chr($byte);
								}
							}
						}
					}
					else if ($result["type"] === EFSS_BLOCKTYPE_FILE)
					{
					}
					else if ($result["type"] === EFSS_BLOCKTYPE_UNUSED_LIST)
					{
						$unusedblock = new EFSS_Unused;
						if (!$unusedblock->unserialize($result["data"]))  return array("success" => false, "error" => EFSS::Translate("Unused block %u of the encrypted file storage system is invalid.", $x), "errorcode" => "unused_block_failure");

						foreach ($unusedblock->unusedblocks as $x2)
						{
							if ($x2 > $numblocks || $x2 < 3 || $x2 == $x)  return array("success" => false, "error" => EFSS::Translate("Block %u references an out of range or invalid block %u.", $x, $x2), "errorcode" => "block_out_of_range");

							$byte = ord($referenced[(int)($x2 / 8)]);
							$pos = $x2 % 8;
							if ($byte & $mask[$pos])  return array("success" => false, "error" => EFSS::Translate("Block %u references an already referenced block %u.", $x, $x2), "errorcode" => "block_already_referenced");

							$byte |= $mask[$pos];
							$referenced[(int)($x2 / 8)] = chr($byte);
						}
					}
				}
				else if ($result["type"] === EFSS_BLOCKTYPE_UNUSED)
				{
				}
				else
				{
					return array("success" => false, "error" => EFSS::Translate("Block %u is an invalid type.", $x), "errorcode" => "block_type_invalid");
				}

				if ($blockcallbackfunc !== false && $ts != time())
				{
					$blockcallbackfunc($x, $numblocks, $startts);
					$ts = time();
				}
			}

			// Check for unreferenced blocks.
			$y = strlen($referenced);
			for ($x = 0; $x < $y; $x++)
			{
				if (ord($referenced[$x]) !== 0xFF)
				{
					$blocknum = $x * 8;
					$byte = ord($referenced[$x]);
					for ($x2 = 0; $x2 < 8; $x2++)
					{
						if (($byte & $mask[$x2]) == 0)  return array("success" => false, "error" => EFSS::Translate("Block %u is never referenced.", $x + $x2), "errorcode" => "block_not_referenced");
					}
				}
			}

			// Walk the entire file system using legit functions.
			$dirs = array();
			$paths = array();
			$result = $this->opendir("/");
			if (!$result["success"])  return $result;
			$dirs[] = $result["dir"];
			$paths[] = $result["path"];
			$x = 1;
			while ($x)
			{
				$result = $this->readdir($dirs[$x - 1]);
				if (!$result["success"])
				{
					if ($result["errorcode"] != "dir_end")  return $result;

					$x--;
				}
				else
				{
					if ($result["info"]->type == EFSS_DIRTYPE_DIR)
					{
						$result2 = $this->opendir($paths[$x - 1] . "/" . $result["name"]);
						if (!$result2["success"])  return $result2;

						$dirs[$x] = $result2["dir"];
						$paths[$x] = $result2["path"];
						$x++;
					}
					else if ($result["info"]->type == EFSS_DIRTYPE_FILE && !($result["info"]->permflags & EFSS_FLAG_INLINE))
					{
						// Walk the linked list of blocks for the file.
						$blocknum = $result["info"]->data;
						while ($blocknum > 0)
						{
							// Read in the block.
							$result2 = $this->RawReadBlock($blocknum, EFSS_BLOCKTYPE_FILE);
							if (!$result2["success"])  return $result2;
							if (strlen($result2["data"]) < 4)  return array("success" => false, "error" => EFSS::Translate("Block %u of the encrypted file storage system is invalid.", $blocknum), "errorcode" => "block_failure");

							// Save a few cycles by only processing 4 bytes of data.
							$blocknum = EFSS::UnpackInt(substr($result2["data"], 0, 4));

							if ($filecallbackfunc !== false && $ts != time())
							{
								$filecallbackfunc($paths[$x - 1] . "/" . $result["name"], $startts);
								$ts = time();
							}
						}
					}

					if ($filecallbackfunc !== false && $ts != time())
					{
						$filecallbackfunc($paths[$x - 1] . "/" . $result["name"], $startts);
						$ts = time();
					}
				}
			}

			return array("success" => true, "startts" => $startts, "endts" => microtime(true));
		}

		public function Defrag($path, $recursive, $pathcallbackfunc = false)
		{
			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");

			$startts = microtime(true);

			$ts = time();
			$fragments = 0;

			$dirs = array();
			$paths = array();
			$result = $this->opendir($path);
			if (!$result["success"])  return $result;
			$dirs[] = $result["dir"];
			$paths[] = $result["path"];
			$x = 1;
			while ($x)
			{
				$result = $this->readdir($dirs[$x - 1], true);
				if (!$result["success"])
				{
					if ($result["errorcode"] != "dir_end")  return $result;

					$x--;
				}
				else
				{
					$handle = $result["info"];

					if (!count($handle["currdir"]->entries) && $handle["currdir"]->nextblock > 1)
					{
						// Copy the next block to the current block.
						// This code depends on raw directory reading pulling in the next block.
						$nextblocknum = $handle["currdir"]->nextblock;
						$dirs[$x - 1]["currblock"] = $handle["currblock"];

						$result = $this->WriteDirBlock($dirs[$x - 1]["currdir"], $dirs[$x - 1]["currblock"], -1);
						if (!$result["success"])  return $result;

						// Delete the newly detached block.  Flush the freed used blocks if the next block contains entries.
						$result = $this->FreeUsedBlock($nextblocknum, (count($dirs[$x - 1]["currdir"]->entries) > 0));
						if (!$result["success"])  return $result;

						$fragments++;
					}

					if ($recursive)
					{
						$x2 = $x;
						while (count($handle["currdir"]->entries))
						{
							$entry = array_shift($handle["currdir"]->entries);
							$result = array("success" => true, "name" => $entry->name, "info" => $entry);

							if ($result["info"]->type == EFSS_DIRTYPE_DIR)
							{
								$result2 = $this->opendir($paths[$x2 - 1] . "/" . $result["name"]);
								if (!$result2["success"])  return $result2;

								$dirs[$x] = $result2["dir"];
								$paths[$x] = $result2["path"];
								$x++;
							}
						}
					}

					if ($pathcallbackfunc !== false && $ts != time())
					{
						$pathcallbackfunc($paths[$x - 1], $fragments, $startts);
						$ts = time();
					}
				}
			}

			// Reset the directory caches.
			$this->dirnamemapcache = array();
			$this->dirlisttimescache = array();
			$this->dirinsertmapcache = array();

			return array("success" => true, "startts" => $startts, "endts" => microtime(true), "fragments" => $fragments);
		}

		private function ResolvePath($path, $dirinfo = false)
		{
			$path = str_replace("\\", "/", $path);
			if (substr($path, 0, 1) == "/")  $dirinfo = array();
			else if ($dirinfo === false)  $dirinfo = $this->basedirinfo;
			$parts = explode("/", $path);
			foreach ($parts as $part)
			{
				if ($part == "." || $part == "")
				{
				}
				else if ($part == "..")
				{
					array_pop($dirinfo);
				}
				else
				{
					$dirinfo[] = $part;
				}
			}

			return $dirinfo;
		}

		private function DirInfoToPath($dirinfo)
		{
			$pathname = "";
			foreach ($dirinfo as $info)
			{
				if (is_array($info))  $pathname .= "/" . $info[0];
				else if (is_string($info))  $pathname .= "/" . $info;
			}
			if ($pathname == "")  $pathname = "/";

			return $pathname;
		}

		private function LoadPath($path, $dirinfo = false, $lastentrytype = EFSS_DIRTYPE_DIR, $fullentry = false, $lastsymlink = false)
		{
			$symlinks = array();

			do
			{
				$restart = false;
				$dirinfo = $this->ResolvePath($path, $dirinfo);

				$y = count($dirinfo);
				$firstblock = 2;
				for ($x = 0; $x < $y; $x++)
				{
					if (is_string($dirinfo[$x]))
					{
						$num = $firstblock;

						if (isset($this->dirnamemapcache[$num]))
						{
							if (isset($this->dirnamemapcache[$num][$dirinfo[$x]]))  $num = $this->dirnamemapcache[$num][$dirinfo[$x]];
							else if (isset($this->dirnamemapcache[$num][""]))  $num = $this->dirnamemapcache[$num][""];
						}

						if (!isset($this->dirnamemapcache[$firstblock]))  $this->dirnamemapcache[$firstblock] = array();

						$this->dirlisttimescache[$firstblock] = time() + 1;
						do
						{
							$result = $this->ReadDirBlock($num, $dirinfo[$x], true);
							if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Failed to read directory block %u.", $num), "errorcode" => "dir_block_read", "info" => $result);

							if (!count($result["dir"]->names))  $this->dirinsertmapcache[$num] = array(false, $result["dir"]->nextblock);
							else
							{
								foreach ($result["dir"]->names as $name)
								{
									$this->dirnamemapcache[$firstblock][$name] = $num;
								}

								$this->dirinsertmapcache[$num] = array($result["dir"]->names[0], $result["dir"]->nextblock);
							}

							$this->dirnamemapcache[$firstblock][""] = $num;

							$currblock = $num;
							$num = $result["dir"]->nextblock;
						} while (!count($result["dir"]->entries) && $num > 1);

						if (!count($result["dir"]->entries))  return array("success" => false, "error" => EFSS::Translate("Path not found (%s).", $dirinfo[$x]), "errorcode" => "path_not_found", "dirinfo" => $dirinfo, "dirinfopos" => $x, "blocknum" => $firstblock, "symlinks" => $symlinks);
						if ($result["dir"]->entries[0]->type == EFSS_DIRTYPE_SYMLINK && (!$lastsymlink || $x < $y - 1))
						{
							$path = $result["dir"]->entries[0]->data;
							if (substr($path, -1) == "/")  $path = substr($path, 0, -1);
							if (isset($symlinks[$path]))  return array("success" => false, "error" => EFSS::Translate("Symlink infinite loop detected."), "errorcode" => "infinite_loop", "path" => $path);
							$symlinks[$path] = true;
							$x++;
							for (; $x < $y; $x++)  $path .= "/" . $dirinfo[$x];
							$restart = true;

							break;
						}

						$this->UpdateNameCache($firstblock, $dirinfo[$x], $currblock);

						if ($x < $y - 1 && $result["dir"]->entries[0]->type != EFSS_DIRTYPE_DIR)  return array("success" => false, "error" => EFSS::Translate("%s is not a directory.", $dirinfo[$x]), "errorcode" => "not_directory");
						else if ($x == $y - 1 && $lastentrytype != EFSS_DIRTYPE_ANY && $result["dir"]->entries[0]->type != $lastentrytype)  return array("success" => false, "error" => EFSS::Translate("%s is not the required type.", $dirinfo[$x]), "errorcode" => "incorrect_type");

						$dirinfo[$x] = array($result["dir"]->entries[0]->name, $result["dir"]->entries[0]->data, $firstblock, $currblock);
						if ($fullentry)  $dirinfo[$x][4] = $result["dir"]->entries[0];
					}

					$firstblock = $dirinfo[$x][1];
				}
			} while ($restart);

			return array("success" => true, "dirinfo" => $dirinfo, "symlinks" => $symlinks);
		}

		private function UpdateNameCache($firstblocknum, $name, $blocknum)
		{
			if (!isset($this->dirnamemapcache[$firstblocknum]))  $this->dirnamemapcache[$firstblocknum] = array();
			$this->dirnamemapcache[$firstblocknum][$name] = $blocknum;

			$this->dirlisttimescache[$firstblocknum] = time();

			if ($this->dirlastgarbagecollect + 30 < time())
			{
				$this->dirinsertmapcache = array();
				asort($this->dirlisttimescache);
				$this->dirlastgarbagecollect = time();
				foreach ($this->dirlisttimescache as $key => $time)
				{
					if ($time < $this->dirlastgarbagecollect - 5)
					{
						unset($this->dirnamemapcache[$key]);
						unset($this->dirlisttimescache[$key]);
					}
				}
			}
		}

		private function ReadDirBlock($blocknum, $findname = false, $allnames = false)
		{
			if (!isset($this->dirblockcache[$blocknum]))
			{
				$result = $this->RawReadBlock($blocknum, EFSS_BLOCKTYPE_DIR);
				if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("Directory block %u of the encrypted file storage system is corrupt.", $blocknum), "errorcode" => "dir_block_corrupt", "info" => $result);

				if ($this->dirmode & EFSS_DIRMODE_COMPRESS)  $result["data"] = @gzuncompress($result["data"]);

				$this->dirblockcache[$blocknum] = $result["data"];
				if (count($this->dirblockcache) > 50)
				{
					foreach ($this->dirblockcache as $key => $val)
					{
						unset($this->dirblockcache[$key]);
						if (count($this->dirblockcache) <= 50)  break;
					}
				}
			}
			else
			{
				$data = $this->dirblockcache[$blocknum];
				unset($this->dirblockcache[$blocknum]);
				$this->dirblockcache[$blocknum] = $data;
			}

			$dir = new EFSS_DirEntries;
			if (!$dir->unserialize($this->dirblockcache[$blocknum], $this->timestamp, $findname, (($this->dirmode & EFSS_DIRMODE_CASE_INSENSITIVE) > 0), $allnames))  return array("success" => false, "error" => EFSS::Translate("Directory block %u of the encrypted file storage system is invalid.", $blocknum), "errorcode" => "dir_block_failure");

			return array("success" => true, "dir" => $dir);
		}

		private function FindDirInsertPos($firstblock, $newname)
		{
			if (!isset($this->dirnamemapcache[$firstblock]))  $this->dirnamemapcache[$firstblock] = array();

			$this->dirlisttimescache[$firstblock] = time() + 1;

			// Scan the directory cache for the correct block to use.
			$blocknum = $firstblock;
			$minblock = $blocknum;
			do
			{
				if (!isset($this->dirinsertmapcache[$blocknum]))
				{
					$result = $this->ReadDirBlock($blocknum, "", true);
					if (!$result["success"])  return $result;
					$dir = $result["dir"];

					if (!count($dir->names))  $this->dirinsertmapcache[$blocknum] = array(false, $dir->nextblock);
					else
					{
						foreach ($dir->names as $name)
						{
							$this->dirnamemapcache[$firstblock][$name] = $blocknum;
						}

						$this->dirinsertmapcache[$blocknum] = array($dir->names[0], $dir->nextblock);
					}

					$this->dirnamemapcache[$firstblock][""] = $blocknum;
				}

				if ($this->dirinsertmapcache[$blocknum][0] !== false)
				{
					$name = $this->dirinsertmapcache[$blocknum][0];
					$cmp = ($this->dirmode & EFSS_DIRMODE_CASE_INSENSITIVE ? strnatcasecmp($name, $newname) : strnatcmp($name, $newname));
					if ($cmp == 0)
					{
						$result = $this->ReadDirBlock($blocknum);
						if (!$result["success"])  return $result;
						$dir = $result["dir"];

						return array("success" => false, "error" => EFSS::Translate("'%s' already exists.", $newname), "errorcode" => "already_exists", "firstblock" => $firstblock, "blocknum" => $blocknum, "dir" => $dir, "pos" => 0);
					}
					else if ($cmp < 0)  $minblock = $blocknum;
					else  break;
				}

				$blocknum = $this->dirinsertmapcache[$blocknum][1];
			} while ($blocknum > 1);

			// Load the block and locate the position.
			$blocknum = $minblock;
			$result = $this->ReadDirBlock($blocknum);
			if (!$result["success"])  return $result;
			$dir = $result["dir"];

			$y = count($dir->entries);
			if ($this->dirmode & EFSS_DIRMODE_CASE_INSENSITIVE)
			{
				for ($x = 0; $x < $y && strnatcasecmp($dir->entries[$x]->name, $newname) < 0; $x++);
				if ($x < $y && strnatcasecmp($dir->entries[$x]->name, $newname) == 0)  return array("success" => false, "error" => EFSS::Translate("'%s' already exists.", $newname), "errorcode" => "already_exists", "firstblock" => $firstblock, "blocknum" => $blocknum, "dir" => $dir, "pos" => $x);
			}
			else
			{
				for ($x = 0; $x < $y && strnatcmp($dir->entries[$x]->name, $newname) < 0; $x++);
				if ($x < $y && strnatcmp($dir->entries[$x]->name, $newname) == 0)  return array("success" => false, "error" => EFSS::Translate("'%s' already exists.", $newname), "errorcode" => "already_exists", "firstblock" => $firstblock, "blocknum" => $blocknum, "dir" => $dir, "pos" => $x);
			}

			return array("success" => true, "firstblock" => $firstblock, "blocknum" => $blocknum, "dir" => $dir, "pos" => $x);
		}

		private function WriteDirBlock($dir, $blocknum, $firstblock)
		{
			// Initialize the directory cache map.
			if (!isset($this->dirnamemapcache[$firstblock]))  $this->dirnamemapcache[$firstblock] = array();

			do
			{
				$prepend = array();

				// Clear the local cache.
				unset($this->dirblockcache[$blocknum]);
				unset($this->dirinsertmapcache[$blocknum]);

				// Serialize the directory.  The error condition should never happen.
				$data = $dir->serialize();
				if ($data === false)  return array("success" => false, "error" => EFSS::Translate("Serialization of directory block %u failed.", $blocknum), "errorcode" => "serialize_dir");

				// Compress the data.
				if ($this->dirmode & EFSS_DIRMODE_COMPRESS)  $data = @gzcompress($data);

				// Move entries to the prepend array if data is too large.
				while (strlen($data) > $this->blocksize - 30)
				{
					array_unshift($prepend, array_pop($dir->entries));

					// Make sure that prepend entries never contains all of the directory entries (infinite loop).
					if (!count($dir->entries))  return array("success" => false, "error" => EFSS::Translate("Directory entry '%s' is larger than the system block size.", $prepend[0]->name), "errorcode" => "data_size");

					// Make sure the next block is readable.
					if ($dir->nextblock == 0)
					{
						$result = $this->NextUnusedBlock();
						if (!$result["success"])  return $result;
						$dir->nextblock = $result["nextblock"];

						$tempdir = new EFSS_DirEntries;
						$tempdir->timestamp = $this->timestamp;
						$tempdir->nextblock = 0;
						$tempdir->entries = array();

						$result = $this->WriteDirBlock($tempdir, $dir->nextblock, $dir->nextblock);
						if (!$result["success"])  return $result;
					}

					// Serialize the directory.  The error condition should never happen.
					$data = $dir->serialize();
					if ($data === false)  return array("success" => false, "error" => EFSS::Translate("Serialization of directory block %u failed.", $blocknum), "errorcode" => "serialize_dir");

					// Compress the data.
					if ($this->dirmode & EFSS_DIRMODE_COMPRESS)  $data = @gzcompress($data);
				}

				// Write out the directory entries.
				$result = $this->RawWriteBlock($data, $blocknum, EFSS_BLOCKTYPE_DIR);
				if (!$result["success"])  return $result;

				// Update the directory cache map.
				foreach ($dir->entries as $entry)  $this->dirnamemapcache[$firstblock][$entry->name] = $blocknum;

				// If prepend doesn't contain entries, then bail.
				if (!count($prepend))  break;

				// Set up for next iteration.
				$blocknum = $dir->nextblock;
				$result = $this->ReadDirBlock($blocknum);
				if (!$result["success"])  return $result;
				$dir = $result["dir"];

				// Add prepend entries.
				$dir->entries = array_merge($prepend, $dir->entries);
			} while (1);

			return array("success" => true);
		}

		private function NextUnusedBlock()
		{
			if (!count($this->unusedblock->unusedblocks) && $this->unusedblockpos > 1)
			{
				$result = $this->ReloadUnused();
				if (!$result["success"])  return $result;
			}

			if (count($this->unusedblock->unusedblocks))
			{
				$blocknum = array_pop($this->unusedblock->unusedblocks);
				$result = $this->RawWriteBlock($this->unusedblock->serialize(), $this->unusedblockpos, EFSS_BLOCKTYPE_UNUSED_LIST);
				if (!$result["success"])  return $result;
			}
			else
			{
				$blocknum = $this->firstblock->nextblock;
				$result = $this->RawWriteBlock("", $blocknum, EFSS_BLOCKTYPE_UNUSED);
				if (!$result["success"])  return $result;

				$this->firstblock->nextblock++;
				$result = $this->RawWriteBlock($this->firstblock->serialize(), 0, EFSS_BLOCKTYPE_FIRST);
				if (!$result["success"])  return $result;
			}

			return array("success" => true, "nextblock" => $blocknum);
		}

		private function ReloadUnused()
		{
			// Read the first unused block.
			$result = $this->RawReadBlock(1, EFSS_BLOCKTYPE_UNUSED_LIST);
			if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("The first unused block of the encrypted file storage system is corrupt."), "errorcode" => "unused_block_corrupt", "info" => $result);

			// Load the data.
			$this->unusedblock = new EFSS_Unused;
			if (!$this->unusedblock->unserialize($result["data"]))  return array("success" => false, "error" => EFSS::Translate("The first unused block of the encrypted file storage system is invalid."), "errorcode" => "unused_block_failure");
			$this->unusedblockpos = 1;

			// Load each next unused block until no data is found.
			while ($this->unusedblock->nextblock > 0)
			{
				$result = $this->RawReadBlock($this->unusedblock->nextblock, EFSS_BLOCKTYPE_UNUSED_LIST);
				if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("An unused block of the encrypted file storage system is corrupt."), "errorcode" => "unused_block_corrupt", "info" => $result);

				$unusedblock = new EFSS_Unused;
				if (!$unusedblock->unserialize($result["data"]))  return array("success" => false, "error" => EFSS::Translate("An unused block of the encrypted file storage system is invalid."), "errorcode" => "unused_block_failure");
				$unusedblockpos = $this->unusedblock->nextblock;

				if (!count($unusedblock->unusedblocks))  break;

				$this->unusedblock = $unusedblock;
				$this->unusedblockpos = $unusedblockpos;
			}

			return array("success" => true);
		}

		private function FreeLinkedList($blocknum)
		{
			// Clear the local cache.
			unset($this->dirnamemapcache[$blocknum]);
			unset($this->dirlisttimescache[$blocknum]);

			while ($blocknum > 1)
			{
				// Clear the local cache.
				unset($this->dirblockcache[$blocknum]);

				// Read in the block.
				$result = $this->RawReadBlock($blocknum, EFSS_BLOCKTYPE_ANY);
				if (!$result["success"])  return $result;
				if ($result["type"] !== EFSS_BLOCKTYPE_DIR && $result["type"] !== EFSS_BLOCKTYPE_FILE && $result["type"] !== EFSS_BLOCKTYPE_UNUSED_LIST)  return array("success" => false, "error" => EFSS::Translate("Block %u of the encrypted file storage system is not a linked list.", $blocknum), "errorcode" => "block_is_not_list");
				if (strlen($result["data"]) < 4)  return array("success" => false, "error" => EFSS::Translate("Block %u of the encrypted file storage system is invalid.", $blocknum), "errorcode" => "block_failure");

				// Save a few cycles by only processing 4 bytes of data.
				$blocknum2 = EFSS::UnpackInt(substr($result["data"], 0, 4));

				// Append the current block to the unused blocks but don't flush if not the last block.
				$result = $this->FreeUsedBlock($blocknum, $blocknum2 < 2);
				if (!$result["success"])  return $result;

				$blocknum = $blocknum2;
			}

			return array("success" => true);
		}

		private function FreeUsedBlock($blocknum, $flush = false)
		{
			// Wipe data.
			$result = $this->RawWriteBlock("", $blocknum, EFSS_BLOCKTYPE_UNUSED);
			if (!$result["success"])  return $result;

			// Append block number to unused queue.
			array_push($this->unusedblock->unusedblocks, $blocknum);

			// Write out one or more unused blocks to disk.
			if ($flush || count($this->unusedblock->unusedblocks) >= 24576)
			{
				$maxblocks = (int)(($this->blocksize - 30 - 4) / 4);

				// Block numbers should be stored largest to smallest so that NextUnusedBlock() returns the smallest block first.
				rsort($this->unusedblock->unusedblocks);

				if (count($this->unusedblock->unusedblocks) > $maxblocks)
				{
					$blocks = $this->unusedblock->unusedblocks;
					do
					{
						$this->unusedblock->unusedblocks = array_splice($blocks, 0, $maxblocks);

						// If there isn't a next block, create it.  Guaranteed to exist.
						if ($this->unusedblock->nextblock == 0)
						{
							$blocknum = array_pop($blocks);

							// Create the structure.
							$unusedblock = new EFSS_Unused;
							$unusedblock->nextblock = 0;
							$unusedblock->unusedblocks = array();

							$result = $this->RawWriteBlock($unusedblock->serialize(), $blocknum, EFSS_BLOCKTYPE_UNUSED_LIST);
							if (!$result["success"])  return $result;

							$this->unusedblock->nextblock = $blocknum;
						}

						// Write the current block out.
						$result = $this->RawWriteBlock($this->unusedblock->serialize(), $this->unusedblockpos, EFSS_BLOCKTYPE_UNUSED_LIST);
						if (!$result["success"])  return $result;

						// Read in the next block.
						$result = $this->RawReadBlock($this->unusedblock->nextblock, EFSS_BLOCKTYPE_UNUSED_LIST);
						if (!$result["success"])  return array("success" => false, "error" => EFSS::Translate("An unused block of the encrypted file storage system is corrupt."), "errorcode" => "unused_block_corrupt", "info" => $result);

						$unusedblock = new EFSS_Unused;
						if (!$unusedblock->unserialize($result["data"]))  return array("success" => false, "error" => EFSS::Translate("An unused block of the encrypted file storage system is invalid."), "errorcode" => "unused_block_failure");
						$unusedblockpos = $this->unusedblock->nextblock;

						$this->unusedblock = $unusedblock;
						$this->unusedblockpos = $unusedblockpos;

					} while (count($blocks) > $maxblocks);

					$this->unusedblock->unusedblocks = $blocks;
				}

				// Write out the remaining block data.
				$result = $this->RawWriteBlock($this->unusedblock->serialize(), $this->unusedblockpos, EFSS_BLOCKTYPE_UNUSED_LIST);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		private function Lock($lockfile, $mode, $waitforlock = true)
		{
			if ($this->mounted)  return false;

			$this->mode = $mode;
			$this->readwritelock = new ReadWriteLock($lockfile === false ? $this->basefile : $lockfile);

			return $this->readwritelock->Lock($mode === EFSS_MODE_EXCL, $waitforlock);
		}

		private function RawWriteBlock($data, $blocknum, $type)
		{
			$blocknum = (int)$blocknum;
			if ($blocknum < 0)  return array("success" => false, "error" => EFSS::Translate("Invalid block number."), "errorcode" => "invalid_block_number");

			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if ($this->mode !== EFSS_MODE_EXCL)  return array("success" => false, "error" => EFSS::Translate("Not mounted with exclusive lock."), "errorcode" => "read_only_lock");
			if (strlen($data) > $this->blocksize - 30)  return array("success" => false, "error" => EFSS::Translate("Too much data.  Max size is %u.  Received %u.", $this->blocksize - 30, strlen($data)), "errorcode" => "data_size");
			if (strlen($type) != 1)  return array("success" => false, "error" => EFSS::Translate("Invalid block type specified."), "errorcode" => "invalid_block_type");

			// Wrap the block.  7 bytes of garbage + 1 byte type + 2 bytes for size + data + hash + garbage.
			$block = $this->rng->GetBytes(7);
			$block .= $type;
			$block .= pack("n", strlen($data));
			$block .= $data;
			$block .= sha1($data, true);
			if (strlen($block) < $this->blocksize)  $block .= $this->rng->GetBytes($this->blocksize - strlen($block));
			if (strlen($block) > $this->blocksize)  return array("success" => false, "error" => EFSS::Translate("Should never happen."), "errorcode" => "impossible");

			// Encrypt the block.
			$block = $this->cipher1->encrypt($block);

			// Alter block.
			$block = substr($block, -1) . substr($block, 0, -1);

			// Encrypt the block again.
			$block = $this->cipher2->encrypt($block);

			// Move to the target location.
			$this->RawSeekBlock($blocknum);
			$this->RawSeekUpdate($blocknum);
			$this->RawSeekHashes($blocknum);

			// Clone the block to the reverse diff.
			if ($this->rdiff && $blocknum < $this->rdiff_maxblocks)
			{
				EFSS::RawSeek($this->rdiff_fp4, $blocknum * 4);
				$data = fread($this->rdiff_fp4, 4);
				if ($data === "\xFF\xFF\xFF\xFF")
				{
					fwrite($this->rdiff_fp, fread($this->fp, $this->blocksize));
					fwrite($this->rdiff_fp2, fread($this->fp2, 20));
					fwrite($this->rdiff_fp3, fread($this->fp3, 36));

					fseek($this->fp, -$this->blocksize, SEEK_CUR);
					fseek($this->fp2, -20, SEEK_CUR);
					fseek($this->fp3, -36, SEEK_CUR);

					$data = pack("N", $this->rdiff_numblocks);
					fseek($this->rdiff_fp4, -4, SEEK_CUR);
					fwrite($this->rdiff_fp4, $data);
					$this->rdiff_numblocks++;
				}
			}

			// Before writing, make sure the last written timestamp is going to indicate an update.
			if (!$this->written)
			{
				while (time() === $this->lastwrite)
				{
					if (function_exists("usleep"))  usleep(rand(0, 100000));
					else  sleep(1);
				}

				$this->ts = time();

				$this->written = true;
			}

			// Write the block, the most recent class timestamp, and the block hashes.
			$data = EFSS::ConvertToUTCDateTime($this->ts);
			fwrite($this->fp, $block);
			fwrite($this->fp2, $data);
			$data = $block . $data;
			fwrite($this->fp3, md5($data, true) . sha1($data, true));

			// Perform debug logging if enabled.
			if ($this->debugfp !== false)
			{
				$typemap = array(
					1 => "FIRST",
					2 => "DIR",
					3 => "FILE",
					4 => "UNUSED_LIST",
					5 => "UNUSED",
				);

				$e = new Exception;
				$trace = str_replace("\n", "\n[Block " . $blocknum . "] ", str_replace("\r", "\n", str_replace("\r\n", "\n", $e->getTraceAsString())));
				fwrite($this->debugfp, "[Block " . $blocknum . "] Type " . (isset($typemap[ord($type)]) ? $typemap[ord($type)] : "Unknown!") . " (" . $this->basefile . ")\n[Block " . $blocknum . "] " . $trace . "\n\n");
			}

			return array("success" => true);
		}

		private function RawReadBlock($blocknum, $type)
		{
			$blocknum = (int)$blocknum;
			if ($blocknum < 0)  return array("success" => false, "error" => EFSS::Translate("Invalid block number."), "errorcode" => "invalid_block_number");

			if (!$this->mounted)  return array("success" => false, "error" => EFSS::Translate("Not mounted."), "errorcode" => "no_mount");
			if (strlen($type) != 1)  return array("success" => false, "error" => EFSS::Translate("Invalid block type specified."), "errorcode" => "invalid_block_type");

			// Move to the target location.
			$found = false;
			for ($x = 0; $x < count($this->incrementals) && !$found; $x++)
			{
				$fp = $this->incrementals[$x][0];

				if (is_resource($this->incrementals[$x][1]))
				{
					// Find the block using bsearch.
					$fp2 = $this->incrementals[$x][1];
					$min = 0;
					$max = $this->incrementals[$x][2];
					while ($min < $max)
					{
						$mid = $min + (int)(($max - $min) / 2);
						fseek($fp2, $mid * 4);
						$blocknum2 = EFSS::UnpackInt(fread($fp2, 4));

						if ($blocknum2 < $blocknum)  $min = $mid + 1;
						else  $max = $mid;
					}

					if ($min === $max && $blocknum2 === $blocknum)
					{
						EFSS::RawSeek($fp, $min * $this->blocksize);
						$found = true;
					}
				}
				else if (is_string($this->incrementals[$x][1]))
				{
					// Find the block using raw string search.
					$blocknum2 = pack("N", $blocknum);
					$pos = strpos($this->incrementals[$x][1], $blocknum2);
					while ($pos !== false && $pos % 4 != 0)  $pos = strpos($this->incrementals[$x][1], $blocknum2, $pos + 1);
					if ($pos !== false)
					{
						EFSS::RawSeek($fp, ($pos / 4) * $this->blocksize);
						$found = true;
					}
				}
			}

			if (!$found)
			{
				$fp = $this->fp;
				$this->RawSeekBlock($blocknum);
			}

			// Read the data.
			$block = fread($fp, $this->blocksize);

			if (strlen($block) !== $this->blocksize)  return array("success" => false, "error" => EFSS::Translate("Error reading data.  Possible corruption detected."), "errorcode" => "data_read");

			// Decrypt the block.
			$block = $this->cipher2->decrypt($block);

			// Alter block.
			$block = substr($block, 1) . substr($block, 0, 1);

			// Decrypt the block again.
			$block = $this->cipher1->decrypt($block);

			// Disassemble the block and verify it.
			$type2 = substr($block, 7, 1);
			if ($type2 === EFSS_BLOCKTYPE_ANY || ($type !== EFSS_BLOCKTYPE_ANY && $type !== $type2))  return array("success" => false, "error" => EFSS::Translate("Invalid block type detected.  Possible corruption or decryption failure."), "errorcode" => "invalid_block_type_verify");
			$size = EFSS::UnpackInt(substr($block, 8, 2));
			$data = (string)substr($block, 10, $size);
			if (strlen($data) !== $size)  return array("success" => false, "error" => EFSS::Translate("Data size corruption detected.  Possible decryption failure."), "errorcode" => "data_size_verify");
			$hash = (string)substr($block, 10 + $size, 20);
			if (strlen($hash) !== 20)  return array("success" => false, "error" => EFSS::Translate("Hash size corruption detected.  Possible decryption failure."), "errorcode" => "hash_size_verify");
			if ($hash !== sha1($data, true))  return array("success" => false, "error" => EFSS::Translate("Hash verification failure.  Data corruption or decryption failure."), "errorcode" => "hash_verify");

			return array("success" => true, "data" => $data, "type" => $type2);
		}

		public static function RawDecryptBlock($cipher1, $cipher2, $block, $type)
		{
			if (strlen($type) != 1)  return array("success" => false, "error" => EFSS::Translate("Invalid block type specified."), "errorcode" => "invalid_block_type");

			// Decrypt the block.
			$block = $cipher2->decrypt($block);

			// Alter block.
			$block = substr($block, 1) . substr($block, 0, 1);

			// Decrypt the block again.
			$block = $cipher1->decrypt($block);

			// Disassemble the block and verify it.
			$type2 = substr($block, 7, 1);
			if ($type2 === EFSS_BLOCKTYPE_ANY || ($type !== EFSS_BLOCKTYPE_ANY && $type !== $type2))  return array("success" => false, "error" => EFSS::Translate("Invalid block type detected.  Possible corruption or decryption failure."), "errorcode" => "invalid_block_type_verify");
			$size = EFSS::UnpackInt(substr($block, 8, 2));
			$data = (string)substr($block, 10, $size);
			if (strlen($data) !== $size)  return array("success" => false, "error" => EFSS::Translate("Data size corruption detected.  Possible decryption failure."), "errorcode" => "data_size_verify");
			$hash = (string)substr($block, 10 + $size, 20);
			if (strlen($hash) !== 20)  return array("success" => false, "error" => EFSS::Translate("Hash size corruption detected.  Possible decryption failure."), "errorcode" => "hash_size_verify");
			if ($hash !== sha1($data, true))  return array("success" => false, "error" => EFSS::Translate("Hash verification failure.  Data corruption or decryption failure."), "errorcode" => "hash_verify");

			return array("success" => true, "data" => $data, "type" => $type2);
		}

		private function RawSeekBlock($blocknum)
		{
			return EFSS::RawSeek($this->fp, $blocknum * $this->blocksize);
		}

		private function RawSeekUpdate($blocknum)
		{
			return EFSS::RawSeek($this->fp2, $blocknum * 20);
		}

		private function RawSeekHashes($blocknum)
		{
			return EFSS::RawSeek($this->fp3, $blocknum * 36);
		}

		public static function RawSeek($fp, $pos)
		{
			// 1GB ~= 1073741824 which divides evenly by 4096.
			if ($pos < 0)  return -1;
			if ($pos < 1073741824)  return fseek($fp, $pos);

			fseek($fp, 1073741824);
			$pos -= 1073741824;
			while ($pos > 1073741824)
			{
				fseek($fp, 1073741824, SEEK_CUR);
				$pos -= 1073741824;
			}

			return fseek($fp, $pos, SEEK_CUR);
		}

		public static function RawFileSize($fp)
		{
			$pos = 0;
			$size = 1073741824;
			fseek($fp, 0, SEEK_SET);
			while ($size > 1)
			{
				fseek($fp, $size, SEEK_CUR);

				if (fgetc($fp) === false)
				{
					fseek($fp, -$size, SEEK_CUR);
					$size = (int)($size / 2);
				}
				else
				{
					fseek($fp, -1, SEEK_CUR);
					$pos += $size;
				}
			}

			while (fgetc($fp) !== false)  $pos++;

			return $pos;
		}

		public static function ConvertFromUTCDateTime($ts)
		{
			$ts = explode("-", preg_replace('/\s+/', "-", trim(preg_replace('/[^0-9]/', " ", $ts))));
			$year = (int)(count($ts) > 0 ? $ts[0] : 0);
			$month = (int)(count($ts) > 1 ? $ts[1] : 0);
			$day = (int)(count($ts) > 2 ? $ts[2] : 0);
			$hour = (int)(count($ts) > 3 ? $ts[3] : 0);
			$min = (int)(count($ts) > 4 ? $ts[4] : 0);
			$sec = (int)(count($ts) > 5 ? $ts[5] : 0);

			return gmmktime($hour, $min, $sec, $month, $day, $year);
		}

		public static function ConvertFromLocalDateTime($ts)
		{
			$ts = explode("-", preg_replace('/\s+/', "-", trim(preg_replace('/[^0-9]/', " ", $ts))));
			$year = (int)(count($ts) > 0 ? $ts[0] : 0);
			$month = (int)(count($ts) > 1 ? $ts[1] : 0);
			$day = (int)(count($ts) > 2 ? $ts[2] : 0);
			$hour = (int)(count($ts) > 3 ? $ts[3] : 0);
			$min = (int)(count($ts) > 4 ? $ts[4] : 0);
			$sec = (int)(count($ts) > 5 ? $ts[5] : 0);

			return mktime($hour, $min, $sec, $month, $day, $year);
		}

		public static function ConvertToUTCDateTime($ts)
		{
			return str_pad(gmdate("Y-m-d H:i:s", $ts), 20);
		}

		public static function UnpackInt($data)
		{
			if ($data === false)  return false;

			if (strlen($data) == 2)  $result = unpack("n", $data);
			else if (strlen($data) == 4)  $result = unpack("N", $data);
			else if (strlen($data) == 8)
			{
				$result = 0;
				for ($x = 0; $x < 8; $x++)
				{
					$result = ($result * 256) + ord($data[$x]);
				}

				return $result;
			}
			else  return false;

			return $result[1];
		}

		public static function PackInt64($num)
		{
			$result = "";

			if (is_int(2147483648))  $floatlim = 9223372036854775808;
			else  $floatlim = 2147483648;

			if (is_float($num))
			{
				$num = floor($num);
				if ($num < (double)$floatlim)  $num = (int)$num;
			}

			while (is_float($num))
			{
				$byte = (int)fmod($num, 256);
				$result = chr($byte) . $result;

				$num = floor($num / 256);
				if (is_float($num) && $num < (double)$floatlim)  $num = (int)$num;
			}

			while ($num > 0)
			{
				$byte = $num & 0xFF;
				$result = chr($byte) . $result;
				$num = $num >> 8;
			}

			$result = str_pad($result, 8, "\x00", STR_PAD_LEFT);
			$result = substr($result, -8);

			return $result;
		}

		public static function TimeElapsedToString($time)
		{
			$secs = (int)($time % 60);
			$time = (int)($time / 60);
			$mins = (int)($time % 60);
			$hours = (int)($time / 60);

			return ($hours > 0 ? $hours . "h " : "") . ($hours > 0 || $mins > 0 ? $mins . "m " : "") . $secs . "s";
		}
	}

	// First block of EFSS:
	//   2 bytes:   File system version
	//   2 bytes:   Block size of all blocks (must be a multiple of 4096)
	//   1 byte:    Directory entry mode
	//   4 bytes:   Next block once all unused blocks have been used
	//   1 byte:    Date/time storage method (0 = string in YYYY-MM-DD HH:MM:SS format @ 20 bytes with trailing whitespace, 1 = UNIX timestamp integer @ 8 bytes)
	//   DateTime:  Created
	class EFSS_FirstBlock
	{
		public $version, $blocksize, $dirmode, $nextblock, $timestamp, $created;

		public function unserialize($data)
		{
			if (strlen($data) < 14)  return false;

			$this->version = EFSS::UnpackInt(substr($data, 0, 2));
			if ($this->version > EFSS_VERSION)  return false;
			$this->blocksize = EFSS::UnpackInt(substr($data, 2, 2));
			if ($this->blocksize % 4096 != 0)  return false;

			$this->dirmode = ord(substr($data, 4, 1));

			$this->nextblock = EFSS::UnpackInt(substr($data, 5, 4));

			$this->timestamp = (substr($data, 9, 1) === "\x01" ? EFSS_TIMESTAMP_UNIX : EFSS_TIMESTAMP_UTC);
			if ($this->timestamp == EFSS_TIMESTAMP_UNIX)  $this->created = EFSS::UnpackInt(substr($data, 10, 4));
			else
			{
				if (strlen($data) < 30)  return false;

				$this->created = EFSS::ConvertFromUTCDateTime(substr($data, 10, 20));
			}

			return true;
		}

		public function serialize()
		{
			$data = pack("n", (int)$this->version);
			$data .= pack("n", (int)$this->blocksize);
			$data .= chr($this->dirmode);
			$data .= pack("N", (int)$this->nextblock);
			$data .= chr($this->timestamp);
			if ($this->timestamp == EFSS_TIMESTAMP_UNIX)  $data .= pack("N", (int)$this->created);
			else  $data .= EFSS::ConvertToUTCDateTime($this->created);

			return $data;
		}
	}

	// Basic directory and file directory entry:
	//   Common information
	//     2 bytes:   Permissions & Flags (rwxrwxrwx + setuid + setguid + stickybit - aka rwsrwsrwt, files have compression and "inline" flags cirwsrwsrwt)
	//     2 bytes:   Length of owner name
	//     String:    Owner name
	//     2 bytes:   Length of group name
	//     String:    Group name
	//     For directories:
	//       4 bytes:   First block of file/child directory
	//     For normal files (not inline):
	//       8 bytes:   Size of file data (uncompressed)
	//       8 bytes:   Size of file data
	//       4 bytes:   First block of file data
	//     For inline files:
	//       4 bytes:   Size of file data (uncompressed)
	//       2 bytes:   Size of file data
	//       String:    File data
	//     For symlinks:
	//       2 bytes:   Length of symbolic link target
	//       String:    Symbolic link target
	class EFSS_DirEntry_DirFile
	{
		public $datalen, $type, $created, $name;
		public $permflags, $ownername, $groupname, $data, $fullsize, $disksize;
	}

	// Directory entries block:
	//   4 bytes:   Next block in this linked list
	//   One or more encapsulated directory entries:
	//     2 bytes:   Data length of this entry
	//     1 byte:    Entry type (0 = Directory, 1 = File, 2 = Symbolic link)
	//     DateTime:  Created
	//     2 bytes:   Length of name
	//     String:    Name
	//
	//     Followed by specific entry type info.
	class EFSS_DirEntries
	{
		public $timestamp, $nextblock, $entries, $names;

		function __clone()
		{
			foreach ($this->entries as $num => $entry)  $this->entries[$num] = clone $entry;
		}

		public function unserialize($data, $timestamp, $findname = false, $insensitive = true, $allnames = false)
		{
			if (strlen($data) < 4)  return false;

			$this->timestamp = $timestamp;
			$timestampsize = ($this->timestamp == EFSS_TIMESTAMP_UNIX ? 4 : 20);

			$this->nextblock = EFSS::UnpackInt(substr($data, 0, 4));
			$data = substr($data, 4);

			$this->entries = array();
			$this->names = array();
			while (strlen($data) > 1)
			{
				$datalen = EFSS::UnpackInt(substr($data, 0, 2));
				if ($datalen > strlen($data) - 2 || $datalen < 1 + $timestampsize + 2)  return false;
				$data2 = substr($data, 2, $datalen);
				$data = substr($data, 2 + $datalen);

				// Process the name first.
				$namelen = EFSS::UnpackInt(substr($data2, 1 + $timestampsize, 2));
				$name = substr($data2, 1 + $timestampsize + 2, $namelen);
				if ($name == "")  return false;

				if ($allnames)  $this->names[] = $name;

				if ($findname === false || ($insensitive ? strcasecmp($findname, $name) == 0 : $findname === $name))
				{
					$type = ord(substr($data2, 0, 1));
					if ($this->timestamp == EFSS_TIMESTAMP_UNIX)  $created = EFSS::UnpackInt(substr($data2, 1, 4));
					else  $created = EFSS::ConvertFromUTCDateTime(substr($data2, 1, 20));
					$data2 = substr($data2, 1 + $timestampsize + 2 + $namelen);

					$entry = new EFSS_DirEntry_DirFile;

					if (strlen($data2) < 4)  return false;
					$entry->permflags = EFSS::UnpackInt(substr($data2, 0, 2));

					$len = EFSS::UnpackInt(substr($data2, 2, 2));
					$entry->ownername = substr($data2, 4, $len);
					if (strlen($entry->ownername) < $len)  return false;
					$data2 = substr($data2, 4 + $len);

					if (strlen($data2) < 2)  return false;
					$len = EFSS::UnpackInt(substr($data2, 0, 2));
					$entry->groupname = substr($data2, 2, $len);
					if (strlen($entry->groupname) < $len)  return false;
					$data2 = substr($data2, 2 + $len);

					switch ($type)
					{
						case EFSS_DIRTYPE_DIR:
						{
							$entry->permflags = $entry->permflags & EFSS_PERM_ALLOWED_DIR;

							if (strlen($data2) < 4)  return false;
							$entry->data = EFSS::UnpackInt(substr($data2, 0, 4));
							if ($entry->data === false || $entry->data < 3)  return false;
							$entry->fullsize = 0;
							$entry->disksize = 0;

							break;
						}
						case EFSS_DIRTYPE_FILE:
						{
							$entry->permflags = $entry->permflags & EFSS_PERM_ALLOWED_FILE_INTERNAL;

							if ($entry->permflags & EFSS_FLAG_INLINE)
							{
								if (strlen($data2) < 6)  return false;
								$entry->fullsize = EFSS::UnpackInt(substr($data2, 0, 4));
								$entry->disksize = EFSS::UnpackInt(substr($data2, 4, 2));
								$entry->data = (string)substr($data2, 6, $entry->disksize);
								if (strlen($entry->data) < $entry->disksize)  return false;
							}
							else
							{
								if (strlen($data2) < 20)  return false;
								$entry->fullsize = EFSS::UnpackInt(substr($data2, 0, 8));
								$entry->disksize = EFSS::UnpackInt(substr($data2, 8, 8));
								$entry->data = EFSS::UnpackInt(substr($data2, 16, 4));
								if ($entry->data === false || $entry->data < 3)  return false;
							}

							break;
						}
						case EFSS_DIRTYPE_SYMLINK:
						{
							$entry->permflags = $entry->permflags & EFSS_PERM_ALLOWED_SYMLINK;

							if (strlen($data2) < 3)  return false;
							$len = EFSS::UnpackInt(substr($data2, 0, 2));
							$entry->data = substr($data2, 2, $len);
							if ($entry->data == "" || strlen($entry->data) < $len)  return false;
							$entry->fullsize = 0;
							$entry->disksize = 0;

							break;
						}
						default:  return false;
					}

					// Load common information.
					$entry->datalen = $datalen;
					$entry->type = $type;
					$entry->created = $created;
					$entry->name = $name;

					$this->entries[] = $entry;

					if (!$allnames && $findname !== false)  break;
				}
			}

			return true;
		}

		public function serialize()
		{
			$data = pack("N", (int)$this->nextblock);

			$timestampsize = ($this->timestamp == EFSS_TIMESTAMP_UNIX ? 4 : 20);

			foreach ($this->entries as $entry)
			{
				if ($entry->name == "")  return false;

				// Recalculate the data length in case it changed.
				switch ($entry->type)
				{
					case EFSS_DIRTYPE_DIR:
					{
						$entry->datalen = 1 + $timestampsize + 2 + strlen($entry->name) + 2 + 2 + strlen($entry->ownername) + 2 + strlen($entry->groupname) + 4;

						break;
					}
					case EFSS_DIRTYPE_FILE:
					{
						if (($entry->permflags & EFSS_FLAG_INLINE) && strlen($entry->data) > 65535)  continue 2;
						$entry->datalen = 1 + $timestampsize + 2 + strlen($entry->name) + 2 + 2 + strlen($entry->ownername) + 2 + strlen($entry->groupname) + ($entry->permflags & EFSS_FLAG_INLINE ? 4 + 2 + strlen($entry->data) : 20);

						break;
					}
					case EFSS_DIRTYPE_SYMLINK:
					{
						$entry->datalen = 1 + $timestampsize + 2 + strlen($entry->name) + 2 + 2 + strlen($entry->ownername) + 2 + strlen($entry->groupname) + 2 + strlen($entry->data);

						break;
					}
					default:  return false;
				}

				if ($entry->datalen > 65535)  return false;

				$data .= pack("n", (int)$entry->datalen);
				$data .= chr($entry->type);
				$data .= ($this->timestamp == EFSS_TIMESTAMP_UNIX ? pack("N", (int)$entry->created) : EFSS::ConvertToUTCDateTime($entry->created));
				$data .= pack("n", strlen($entry->name));
				$data .= $entry->name;

				switch ($entry->type)
				{
					case EFSS_DIRTYPE_DIR:
					{
						$data .= pack("n", $entry->permflags);
						$data .= pack("n", strlen($entry->ownername));
						$data .= $entry->ownername;
						$data .= pack("n", strlen($entry->groupname));
						$data .= $entry->groupname;
						$data .= pack("N", $entry->data);

						break;
					}
					case EFSS_DIRTYPE_FILE:
					{
						$data .= pack("n", $entry->permflags);
						$data .= pack("n", strlen($entry->ownername));
						$data .= $entry->ownername;
						$data .= pack("n", strlen($entry->groupname));
						$data .= $entry->groupname;

						if ($entry->permflags & EFSS_FLAG_INLINE)
						{
							$data .= pack("N", $entry->fullsize);
							$data .= pack("n", strlen($entry->data));
							$data .= $entry->data;
						}
						else
						{
							$data .= EFSS::PackInt64($entry->fullsize);
							$data .= EFSS::PackInt64($entry->disksize);
							$data .= pack("N", $entry->data);
						}

						break;
					}
					case EFSS_DIRTYPE_SYMLINK:
					{
						$data .= pack("n", $entry->permflags);
						$data .= pack("n", strlen($entry->ownername));
						$data .= $entry->ownername;
						$data .= pack("n", strlen($entry->groupname));
						$data .= $entry->groupname;
						$data .= pack("n", strlen($entry->data));
						$data .= $entry->data;

						break;
					}
				}
			}

			return $data;
		}
	}

	// File entry block:
	//   4 bytes:   Next block in this linked list
	//   DataBytes: A chunk of the file data
	class EFSS_File
	{
		public $nextblock, $data;

		public function unserialize($data)
		{
			if (strlen($data) < 4)  return false;

			$this->nextblock = EFSS::UnpackInt(substr($data, 0, 4));
			$this->data = (string)substr($data, 4);

			return true;
		}

		public function serialize()
		{
			$data = pack("N", (int)$this->nextblock);
			$data .= $this->data;

			return $data;
		}
	}

	// Unused entry block:
	//   4 bytes:   Next block in this linked list
	//   Zero or more of:
	//     4 bytes:   Unused block
	class EFSS_Unused
	{
		public $nextblock, $unusedblocks;

		public function unserialize($data)
		{
			$y = strlen($data);
			if ($y < 4 || $y % 4 != 0)  return false;

			$this->nextblock = EFSS::UnpackInt(substr($data, 0, 4));
			$this->unusedblocks = array();
			for ($x = 4; $x < $y; $x += 4)  $this->unusedblocks[] = EFSS::UnpackInt(substr($data, $x, 4));

			return true;
		}

		public function serialize()
		{
			$data = pack("N", (int)$this->nextblock);
			foreach ($this->unusedblocks as $num)  $data .= pack("N", (int)$num);

			return $data;
		}
	}

	abstract class EFSS_CopyHelper
	{
		protected $realmount, $name, $open, $link;

		public static $usernames = array();
		public static $groupnames = array();
		public static $userids = array();
		public static $groupids = array();

		public function __construct()
		{
			$this->open = false;
			$this->link = false;
		}

		public function __destruct()
		{
			$this->Close();
		}

		public function GetMount()
		{
			return $this->realmount;
		}

		public function GetName()
		{
			return $this->name;
		}

		public function GetStat()
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount === true)
			{
				$stat = ($this->link ? lstat($this->name) : stat($this->name));

				if (!isset(EFSS_CopyHelper::$usernames[$stat["uid"]]))
				{
					if (!function_exists("posix_getpwuid"))  EFSS_CopyHelper::$usernames[$stat["uid"]] = "";
					else
					{
						$result = posix_getpwuid($stat["uid"]);
						EFSS_CopyHelper::$usernames[$stat["uid"]] = $result["name"];
					}
				}
				$stat["uname"] = EFSS_CopyHelper::$usernames[$stat["uid"]];

				if (!isset(EFSS_CopyHelper::$groupnames[$stat["gid"]]))
				{
					if (!function_exists("posix_getgrgid"))  EFSS_CopyHelper::$groupnames[$stat["gid"]] = "";
					else
					{
						$result = posix_getgrgid($stat["gid"]);
						EFSS_CopyHelper::$groupnames[$stat["gid"]] = $result["name"];
					}
				}
				$stat["gname"] = EFSS_CopyHelper::$groupnames[$stat["gid"]];
			}
			else
			{
				$result = ($this->link ? $this->realmount->lstat($this->name) : $this->realmount->stat($this->name));
				if (!$result["success"])  return $result;
				$stat = $result["stat"];

				if (function_exists("posix_getpwnam"))
				{
					if (!isset(EFSS_CopyHelper::$userids[$stat["uname"]]))
					{
						$result = posix_getpwnam($stat["uname"]);
						EFSS_CopyHelper::$userids[$stat["uname"]] = $result["uid"];
					}
					$stat["uid"] = EFSS_CopyHelper::$userids[$stat["uname"]];
				}

				if (function_exists("posix_getgrnam"))
				{
					if (!isset(EFSS_CopyHelper::$groupids[$stat["gname"]]))
					{
						$result = posix_getgrnam($stat["gname"]);
						EFSS_CopyHelper::$groupids[$stat["gname"]] = $result["gid"];
					}
					$stat["gid"] = EFSS_CopyHelper::$groupids[$stat["gname"]];
				}
			}

			return array("success" => true, "stat" => $stat);
		}

		public function SetStat($newstat)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			$result = $this->GetStat();
			if (!$result["success"])  return $result;
			$oldstat = $result["stat"];

			$changed = false;
			if ($this->realmount === true)
			{
				if (!$this->link && ($oldstat["mode"] & 07777) != ($newstat["mode"] & 07777))
				{
					$result = chmod($this->name, $newstat["mode"] & 07777);
					if (!$result)  return array("success" => false, "error" => EFSS::Translate("Real 'chmod' failed on '%s'.", $this->name), "errorcode" => "setstat_chmod_error");
					$changed = true;
				}
				if (isset($newstat["uid"]) && (!isset($oldstat["uid"]) || $oldstat["uid"] != $newstat["uid"]))
				{
					if ($this->link)  $result = lchown($this->name, $newstat["uid"]);
					else  $result = chown($this->name, $newstat["uid"]);
					if (!$result)  return array("success" => false, "error" => EFSS::Translate("Real '" . ($this->link ? "lchown" : "chown") . "' failed on '%s'.", $this->name), "errorcode" => ($this->link ? "setstat_lchown_error" : "setstat_chown_error"));
					$changed = true;
				}
				if (isset($newstat["gid"]) && (!isset($oldstat["gid"]) || $oldstat["gid"] != $newstat["gid"]))
				{
					if ($this->link)  $result = lchgrp($this->name, $newstat["gid"]);
					else  $result = chgrp($this->name, $newstat["gid"]);
					if (!$result)  return array("success" => false, "error" => EFSS::Translate("Real '" . ($this->link ? "lchgrp" : "chgrp") . "' failed on '%s'.", $this->name), "errorcode" => ($this->link ? "setstat_lchgrp_error" : "setstat_chgrp_error"));
					$changed = true;
				}
				if (!$this->link && ($changed || $oldstat["mtime"] != $newstat["mtime"]))
				{
					$result = touch($this->name, $newstat["mtime"]);
					if (!$result)  return array("success" => false, "error" => EFSS::Translate("Real 'touch' failed on '%s'.", $this->name), "errorcode" => "setstat_touch_error");
					$changed = true;
				}
			}
			else
			{
				if (!$this->link && ($oldstat["mode"] & 07777) != ($newstat["mode"] & 07777))
				{
					$result = $this->realmount->chmod($this->name, $newstat["mode"] & 07777);
					if (!$result["success"])  return $result;
					$changed = true;
				}
				if (isset($newstat["uname"]) && (!isset($oldstat["uname"]) || $oldstat["uname"] != $newstat["uname"]))
				{
					if ($this->link)  $result = $this->realmount->lchown($this->name, $newstat["uname"]);
					else  $result = $this->realmount->chown($this->name, $newstat["uname"]);
					if (!$result["success"])  return $result;
					$changed = true;
				}
				if (isset($newstat["gname"]) && (!isset($oldstat["gname"]) || $oldstat["gname"] != $newstat["gname"]))
				{
					if ($this->link)  $result = $this->realmount->lchgrp($this->name, $newstat["gname"]);
					else  $result = $this->realmount->chgrp($this->name, $newstat["gname"]);
					if (!$result["success"])  return $result;
					$changed = true;
				}
				if ($changed || $oldstat["mtime"] != $newstat["mtime"])
				{
					$result = $this->realmount->touch($this->name, $newstat["mtime"]);
					if (!$result["success"])  return $result;
					$changed = true;
				}
			}

			return array("success" => true, "changed" => $changed);
		}
	}

	class EFSS_DirCopyHelper extends EFSS_CopyHelper
	{
		private $dir;

		public function Init($realmount, $path, $create = false)
		{
			$this->realmount = $realmount;
			if ($this->realmount === true)
			{
				if ($create)  @mkdir($path, 0777, true);

				$this->name = str_replace("\\", "/", realpath($path));
				if ($this->name == "")  return array("success" => false, "error" => EFSS::Translate("Unable to get the real path of '%s'.", $filename), "errorcode" => "unable_to_translate");

				$this->dir = opendir($this->name);
				if ($this->dir === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open '%s'.", $this->name), "errorcode" => "unable_to_open");
			}
			else
			{
				if ($create)  $this->realmount->mkdir($path, false, true);

				$result = $this->realmount->realpath($path);
				if (!$result["success"])  return $result;
				$this->name = $result["path"];

				$result = $this->realmount->opendir($this->name);
				if (!$result["success"])  return $result;
				$this->dir = $result["dir"];
			}

			$this->open = true;

			return array("success" => true);
		}

		public function Close()
		{
			if (!$this->open)  return;

			if ($this->realmount === true)  closedir($this->dir);
			else  $this->realmount->closedir($this->dir);

			$this->open = false;
		}

		public function rewinddir()
		{
			if (!$this->open)  return;

			if ($this->realmount === true)  rewinddir($this->dir);
			else  $this->realmount->rewinddir($this->dir);
		}

		public function readdir()
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount !== true)  return $this->realmount->readdir($this->dir);

			$name = readdir($this->dir);
			if ($name === false)  return array("success" => false, "error" => EFSS::Translate("Reached end of directory entries."), "errorcode" => "dir_end");

			return array("success" => true, "name" => $name);
		}

		public function is_dir($name)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount === true)  return array("success" => true, "result" => is_dir($name));
			else  return $this->realmount->is_dir($name);
		}

		public function is_file($name)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount === true)  return array("success" => true, "result" => is_file($name));
			else  return $this->realmount->is_file($name);
		}

		public function is_link($name)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount === true)  return array("success" => true, "result" => is_link($name));
			else  return $this->realmount->is_link($name);
		}

		public function filetype($name)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount !== true)  return $this->realmount->filetype($name);
			else if (is_link($name))  return array("success" => true, "type" => "link");
			else if (is_file($name))  return array("success" => true, "type" => "file");
			else if (is_dir($name))  return array("success" => true, "type" => "dir");

			return array("success" => false, "error" => EFSS::Translate("Does not exist."), "errorcode" => "does_not_exist");
		}

		public function mkdir($pathname, $mode = false, $recursive = false)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount === true)  return array("success" => true, "result" => @mkdir($pathname, ($mode === false ? 0777 : $mode), $recursive));
			else  return $this->realmount->mkdir($pathname, $mode, $recursive);
		}

		public function rmdir($pathname, $recursive = false)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if (substr($pathname, -1) == "/")  $pathname = substr($pathname, 0, -1);

			if ($this->realmount !== true)  return $this->realmount->rmdir($pathname, $recursive);
			else if (!$recursive)  return array("success" => true, "result" => rmdir($pathname));

			if (!is_dir($pathname))  return array("success" => false, "error" => EFSS::Translate("Not a directory."), "errorcode" => "not_directory");

			$dir = opendir($pathname);
			if ($dir)
			{
				while (($name = readdir($dir)) !== false)
				{
					if (is_link($pathname . "/" . $name) || is_file($pathname . "/" . $name))
					{
						if (!unlink($pathname . "/" . $name))  return array("success" => false, "error" => EFSS::Translate("Unable to unlink '%s'.", $pathname . "/" . $name), "errorcode" => "unlink_failed");
					}
					else if (is_dir($pathname . "/" . $name))
					{
						$result = $this->rmdir($pathname . "/" . $name, true);
						if (!$result["success"])  return $result;
					}
				}

				closedir($dir);

				if (!rmdir($pathname))  return array("success" => false, "error" => EFSS::Translate("Unable to remove directory '%s'.", $pathname), "errorcode" => "rmdir_failed");
			}

			return array("success" => true, "result" => true);
		}

		public function unlink($name, $recursive = false)
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount === true)  return array("success" => true, "result" => unlink($name));
			else  return $this->realmount->unlink($name);
		}
	}

	class EFSS_SymlinkCopyHelper extends EFSS_CopyHelper
	{
		public function Init($realmount, $name, $target = false)
		{
			$this->realmount = $realmount;
			$name = str_replace("\\", "/", $name);
			if ($this->realmount === true)
			{
				if (!is_link($name))
				{
					if ($target === false)  return array("success" => false, "error" => EFSS::Translate("The name '%s' is not a symlink.", $name), "errorcode" => "not_symlink");

					if (!symlink($target, $name))  return array("success" => false, "error" => EFSS::Translate("Unable to create '%s'.", $name), "errorcode" => "unable_to_create");
				}
			}
			else
			{
				$result = $this->realmount->is_link($name);
				if (!$result["success"] && $result["errorcode"] != "path_not_found")  return $result;
				if (!$result["success"])
				{
					if ($target === false)  return array("success" => false, "error" => EFSS::Translate("The name '%s' is not a symlink.", $name), "errorcode" => "not_symlink");

					$result = $this->realmount->symlink($target, $name);
					if (!$result["success"])  return $result;
				}
			}

			$this->name = $name;
			$this->link = true;
			$this->open = true;

			return array("success" => true);
		}

		public function Close()
		{
			$this->open = false;
		}

		public function readlink()
		{
			if (!$this->open)  return array("success" => false, "error" => EFSS::Translate("Not initialized."), "errorcode" => "no_init");

			if ($this->realmount === true)  return array("success" => true, "link" => readlink($this->name));
			else  return $this->realmount->readlink($this->name);
		}
	}

	class EFSS_FileCopyHelper extends EFSS_CopyHelper
	{
		private $fp;

		public function Init($realmount, $filename, $mode)
		{
			$this->realmount = $realmount;
			if ($this->realmount === true)
			{
				$this->fp = fopen($filename, $mode);
				if ($this->fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open '%s'.", $this->name), "errorcode" => "unable_to_open");

				$this->name = str_replace("\\", "/", realpath($filename));
				if ($this->name == "")  return array("success" => false, "error" => EFSS::Translate("Unable to get the real path of '%s'.", $filename), "errorcode" => "unable_to_translate");
			}
			else
			{
				$result = $this->realmount->fopen($filename, $mode);
				if (!$result["success"])  return $result;
				$this->fp = $result["fp"];

				$result = $this->realmount->realpath($filename);
				if (!$result["success"])  return $result;
				$this->name = $result["path"];
			}

			$this->open = true;

			return array("success" => true);
		}

		public function Close()
		{
			if (!$this->open)  return;

			if ($this->realmount === true)  fclose($this->fp);
			else  $this->realmount->fclose($this->fp);

			$this->open = false;
		}

		public function Reopen($mode)
		{
			$this->Close();

			return $this->Init($this->realmount, $this->name, $mode);
		}

		public function Read($len)
		{
			if (!$this->open)  return false;

			$data = "";
			do
			{
				if ($this->realmount === true)  $data2 = fread($this->fp, $len);
				else
				{
					$result = $this->realmount->fread($this->fp, $len);
					if ($result["success"])  $data2 = $result["data"];
					else
					{
						if ($result["errorcode"] != "eof")  return false;

						$data2 = "";
					}
				}
				if ($data2 === false)  $data2 = "";

				$data .= $data2;
				$len -= strlen($data2);
			} while (strlen($data2) > 0 && $len > 0);

			return $data;
		}

		public function Write($data)
		{
			if (!$this->open)  return false;

			if ($this->realmount === true)  fwrite($this->fp, $data);
			else
			{
				$result = $this->realmount->fwrite($this->fp, $data);
				if (!$result["success"])  return false;
			}

			return true;
		}
	}

	class EFSSIncremental
	{
		public static function ForceUnlock($filename)
		{
			@unlink($filename . ".lock");
			@unlink($filename . ".readers");
			@unlink($filename . ".writer.lock");
			@unlink($filename . ".writer");
		}

		public static function Delete($filename, $phpfile = false)
		{
			@unlink($filename);
			@unlink($filename . ".updates");
			@unlink($filename . ".serial");
			@unlink($filename . ".blocknums");
			@unlink($filename . ".hashes");
			@unlink($filename . ".rdiff");
			@unlink($filename . ".rdiff.updates");
			@unlink($filename . ".rdiff.hashes");
			@unlink($filename . ".rdiff.blockmap");
			@unlink($filename . ".rdiff.blockinfo");
			@unlink($filename . ".readonly");
			@unlink($filename . ".partial");
			if ($phpfile)  @unlink($filename . ".php");
		}

		public static function WritePHPFile($basefile, $key1, $iv1, $key2, $iv2, $blocksize, $lockfile)
		{
			$data = "<" . "?php\n";
			$data .= "\t\$key1 = pack(\"H*\", \"" . $key1 . "\");\n";
			$data .= "\t\$iv1 = pack(\"H*\", \"" . $iv1 . "\");\n";
			$data .= "\t\$key2 = pack(\"H*\", \"" . $key2 . "\");\n";
			$data .= "\t\$iv2 = pack(\"H*\", \"" . $iv2 . "\");\n";
			$data .= "\t\$blocksize = " . $blocksize . ";\n";
			$data .= "\t\$lockfile = " . var_export($lockfile, true) . ";\n";
			$data .= "?" . ">";
			file_put_contents($basefile . ".php", $data);
		}

		// Obtain and release locks separately from the rest of the functions.
		public static function GetLock($lockfile, $writelock, $waitforlock = true, $maxtime = 20)
		{
			if (is_int($waitforlock))
			{
				if ($waitforlock >= $maxtime)  $waitforlock = $maxtime - 1;
				if ($waitforlock < 0)  $waitforlock = 0;
			}
			$readwritelock = new ReadWriteLock($lockfile);
			if (!$readwritelock->Lock($writelock, $waitforlock))  return array("success" => false, "error" => EFSS::Translate("Unable to obtain lock."), "errorcode" => "lock_failed");

			return array("success" => true, "lock" => $readwritelock);
		}

		public static function Read($filename, $since, $startblock, $origlastwrite, $maxtime = 20, $blocksize = 4096, $len = 10485760)
		{
			if (!file_exists($filename) || !file_exists($filename . ".updates") || !file_exists($filename . ".serial") || file_exists($filename . ".blocknums") || file_exists($filename . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system does not exist."), "errorcode" => "does_not_exist");
			if ($blocksize == 0 || $blocksize % 4096 != 0 || $blocksize > 32768)  return array("success" => false, "error" => EFSS::Translate("The block size must be a multiple of 4096 and able to fit into an 'unsigned short'."), "errorcode" => "invalid_blocksize");

			if (is_int($maxtime))  $endts = time() + $maxtime;
			else  $endts = false;

			if (is_string($since))
			{
				if (trim($since) === "0000-00-00 00:00:00")  $since = 0;
				else  $since = EFSS::ConvertFromUTCDateTime($since);
			}

			$fp = fopen($filename, "rb");
			if ($fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open block file."), "errorcode" => "block_file_open");
			$fp2 = fopen($filename . ".updates", "rb");
			if ($fp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open updates file."), "errorcode" => "updates_file_open");
			if (!file_exists($filename . ".hashes"))  $fp3 = false;
			else
			{
				$fp3 = fopen($filename . ".hashes", "rb");
				if ($fp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open hashes file."), "errorcode" => "hashes_file_open");
			}

			// Read in the last written timestamp and compare it to any original.
			$lastwrite = EFSS::ConvertFromUTCDateTime(fread($fp2, 20));
			if ($startblock > 0 && $lastwrite !== $origlastwrite)  return array("success" => false, "error" => EFSS::Translate("Write between reads detected."), "errorcode" => "write_between_detected");

			// Move to the starting point for the data.
			EFSS::RawSeek($fp, $blocksize * $startblock);
			EFSS::RawSeek($fp2, 20 * $startblock);
			if ($fp3 !== false)  EFSS::RawSeek($fp3, 36 * $startblock);

			// Read in data up to $readblocks amount at a time.
			$result = array(
				"success" => true,
				"lastwrite" => $lastwrite,
				"blocksize" => $blocksize,
				"blocks" => "",
				"updates" => ""
			);
			if ($since > 0)  $result["blocknums"] = "";
			$blocknum = $startblock;
			$eof = false;
			$readblocks = (int)($len / $blocksize);
			if ($readblocks == 0)  return array("success" => false, "error" => EFSS::Translate("Invalid length specified."), "errorcode" => "invalid_length");
			$numblocks = 0;
			do
			{
				$data = fread($fp, $blocksize * $readblocks);
				if ($data === false || $data == "")
				{
					$eof = true;
					break;
				}
				$data2 = fread($fp2, 20 * $readblocks);
				if ($data2 === false || $data2 == "")
				{
					$eof = true;
					break;
				}
				if ($fp3 !== false)
				{
					$data3 = fread($fp3, 36 * $readblocks);
					if ($data3 === false || $data3 == "")
					{
						$eof = true;
						break;
					}
				}

				$y = (int)(strlen($data2) / 20);
				for ($x = 0; $x < $y && $numblocks < $readblocks; $x++)
				{
					if (EFSS::ConvertFromUTCDateTime(substr($data2, $x * 20, 20)) > $since)
					{
						if ($fp3 !== false)
						{
							$data4 = substr($data, $x * $blocksize, $blocksize) . substr($data2, $x * 20, 20);
							if (substr($data3, $x * 36, 16) !== md5($data4, true) || substr($data3, ($x * 36) + 16, 20) !== sha1($data4, true))  return array("success" => false, "error" => EFSS::Translate("Hash failure detected.  Possible data corruption in the original backup file."), "errorcode" => "invalid_hash");
						}

						$result["blocks"] .= substr($data, $x * $blocksize, $blocksize);
						$result["updates"] .= substr($data2, $x * 20, 20);
						if ($since > 0)  $result["blocknums"] .= pack("N", (int)$blocknum);
						$numblocks++;
					}

					$blocknum++;
				}
			} while ($numblocks < $readblocks && ($endts === false || $endts > time()));

			$result["numblocks"] = $numblocks;

			if (!$eof)  $result["nextblock"] = $blocknum;

			if ($startblock == 0 || $eof)  $result["serial"] = trim(file_get_contents($filename . ".serial"));

			$data = $result["blocks"] . $result["updates"] . (isset($result["blocknums"]) ? $result["blocknums"] : "") . (isset($result["serial"]) ? $result["serial"] : "");
			$result["md5"] = md5($data);
			$result["sha1"] = sha1($data);

			return $result;
		}

		public static function Write($filename, $startblock, $blockdata, $lastupdateddata, $md5, $sha1, $blocknumdata = false, $serial = false, $blocksize = 4096, $finalize = false)
		{
			if ($blocksize == 0 || $blocksize % 4096 != 0 || $blocksize > 32768)  return array("success" => false, "error" => EFSS::Translate("The block size must be a multiple of 4096 and able to fit into an 'unsigned short'."), "errorcode" => "invalid_blocksize");
			$data = $blockdata . $lastupdateddata . ($blocknumdata !== false ? $blocknumdata : "") . ($serial !== false ? $serial : "");
			if ($md5 !== md5($data) || $sha1 !== sha1($data))  return array("success" => false, "error" => EFSS::Translate("One of the hashes does not match."), "errorcode" => "invalid_hash");
			unset($data);

			$mode = "wb";
			if ($startblock < 0)  $startblock = 0;
			if ($startblock == 0)  EFSSIncremental::Delete($filename);
			else if (!file_exists($filename) || !file_exists($filename . ".updates") || !file_exists($filename . ".hashes") || !file_exists($filename . ".serial") || ($blocknumdata !== false && !file_exists($filename . ".blocknums")) || !file_exists($filename . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system incremental '%s' does not exist.", $filename), "errorcode" => "does_not_exist");
			else  $mode = "r+b";

			// Write the data.
			file_put_contents($filename . ".partial", "");

			$fp = fopen($filename, $mode);
			if ($fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open block file."), "errorcode" => "block_file_open");
			EFSS::RawSeek($fp, $blocksize * $startblock);
			fwrite($fp, $blockdata);
			fclose($fp);

			$fp = fopen($filename . ".updates", $mode);
			if ($fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open updates file."), "errorcode" => "updates_file_open");
			EFSS::RawSeek($fp, 20 * $startblock);
			fwrite($fp, $lastupdateddata);
			fclose($fp);

			$hashes = "";
			$y = (int)(strlen($blockdata) / $blocksize);
			for ($x = 0; $x < $y; $x++)
			{
				$data = substr($blockdata, $x * $blocksize, $blocksize);
				$data .= substr($lastupdateddata, $x * 20, 20);
				$hashes .= md5($data, true);
				$hashes .= sha1($data, true);
			}
			$fp = fopen($filename . ".hashes", $mode);
			if ($fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open hashes file."), "errorcode" => "hashes_file_open");
			EFSS::RawSeek($fp, 36 * $startblock);
			fwrite($fp, $hashes);
			fclose($fp);

			if ($blocknumdata !== false)
			{
				$fp = fopen($filename . ".blocknums", $mode);
				if ($fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open block numbers file."), "errorcode" => "block_nums_file_open");
				EFSS::RawSeek($fp, 4 * $startblock);
				fwrite($fp, $blocknumdata);
				fclose($fp);
			}

			if ($serial !== false)  file_put_contents($filename . ".serial", $serial);

			if ($finalize)  unlink($filename . ".partial");

			return array("success" => true, "nextblock" => $startblock + (strlen($blockdata) / $blocksize));
		}

		public static function WriteFinalize($filename)
		{
			if (!file_exists($filename) || !file_exists($filename . ".updates") || !file_exists($filename . ".hashes") || !file_exists($filename . ".serial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system does not exist."), "errorcode" => "does_not_exist");

			@unlink($filename . ".partial");

			return array("success" => true);
		}

		public static function MakeReadOnly($basefile, $readonly = true)
		{
			if (!file_exists($basefile) || !file_exists($basefile . ".updates") || !file_exists($basefile . ".serial") || file_exists($basefile . ".blocknums") || file_exists($basefile . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system does not exist."), "errorcode" => "does_not_exist");

			if ($readonly)  file_put_contents($basefile . ".readonly", "");
			else  @unlink($basefile . ".readonly");

			return array("success" => true);
		}

		public static function LastUpdated($filename)
		{
			if (!file_exists($filename . ".updates"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system or incremental update file does not exist."), "errorcode" => "does_not_exist");

			$fp = fopen($filename . ".updates", "rb");
			if ($fp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open updates file."), "errorcode" => "updates_file_open");

			$result = fread($fp, 20);

			fclose($fp);

			if ($result === false || strlen($result) < 20)  return array("success" => false, "error" => EFSS::Translate("Unable to determine last update."), "errorcode" => "updates_file_invalid");

			return array("success" => true, "lastupdate" => $result);
		}

		public static function Merge($basefile, $incrementalfile, $blocksize = 4096, $delete = true)
		{
			if (!file_exists($basefile) || !file_exists($basefile . ".updates") || !file_exists($basefile . ".hashes") || !file_exists($basefile . ".serial") || file_exists($basefile . ".blocknums") || file_exists($basefile . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system does not exist."), "errorcode" => "does_not_exist");
			if (!file_exists($incrementalfile) || !file_exists($incrementalfile . ".updates") || !file_exists($incrementalfile . ".hashes") || !file_exists($incrementalfile . ".serial") || !file_exists($incrementalfile . ".blocknums") || file_exists($incrementalfile . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system incremental '%s' does not exist.", $incrementalfile), "errorcode" => "does_not_exist");
			if ($blocksize == 0 || $blocksize % 4096 != 0 || $blocksize > 32768)  return array("success" => false, "error" => EFSS::Translate("The block size must be a multiple of 4096 and able to fit into an 'unsigned short'."), "errorcode" => "invalid_blocksize");

			if (trim(file_get_contents($basefile . ".serial")) !== trim(file_get_contents($incrementalfile . ".serial")))  return array("success" => false, "error" => EFSS::Translate("Incremental block file '%s' serial does not match base file serial.", $incrementalfile), "errorcode" => "increment_serial_mismatch");

			// Open file handles.
			$basefp = fopen($basefile, "r+b");
			if ($basefp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open block file."), "errorcode" => "block_file_open");
			$basefp2 = fopen($basefile . ".updates", "r+b");
			if ($basefp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open updates file."), "errorcode" => "updates_file_open");
			$basefp3 = fopen($basefile . ".hashes", "r+b");
			if ($basefp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open hashes file."), "errorcode" => "hashes_file_open");

			$incfp = fopen($incrementalfile, "rb");
			if ($incfp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental block file."), "errorcode" => "block_file_open");
			$incfp2 = fopen($incrementalfile . ".updates", "rb");
			if ($incfp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental updates file."), "errorcode" => "updates_file_open");
			$incfp3 = fopen($incrementalfile . ".hashes", "rb");
			if ($incfp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental hashes file."), "errorcode" => "hashes_file_open");
			$incfp4 = fopen($incrementalfile . ".blocknums", "rb");
			if ($incfp4 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental block numbers file."), "errorcode" => "block_nums_file_open");

			// Temporarily declare the base file as a partial file.
			file_put_contents($basefile . ".partial", "");

			// Read in incremental updates 1MB at a time.
			$numblocks = (int)(1048576 / $blocksize);
			$incblockdata = fread($incfp, 1048576);
			$incupdates = fread($incfp2, 20 * $numblocks);
			$inchashes = fread($incfp3, 36 * $numblocks);
			$incblocknums = fread($incfp4, 4 * $numblocks);
			while ($incblockdata !== false && $incblockdata != "")
			{
				$y = (int)(strlen($incblocknums) / 4);
				for ($x = 0; $x < $y; $x++)
				{
					$blocknum = EFSS::UnpackInt(substr($incblocknums, $x * 4, 4));

					EFSS::RawSeek($basefp, $blocksize * $blocknum);
					fwrite($basefp, substr($incblockdata, $x * $blocksize, $blocksize));

					EFSS::RawSeek($basefp2, 20 * $blocknum);
					fwrite($basefp2, substr($incupdates, $x * 20, 20));

					EFSS::RawSeek($basefp3, 36 * $blocknum);
					fwrite($basefp3, substr($inchashes, $x * 36, 36));
				}

				$incblockdata = fread($incfp, 1048576);
				$incupdates = fread($incfp2, 20 * $numblocks);
				$inchashes = fread($incfp3, 36 * $numblocks);
				$incblocknums = fread($incfp4, 4 * $numblocks);
			}

			fclose($incfp4);
			fclose($incfp3);
			fclose($incfp2);
			fclose($incfp);
			fclose($basefp3);
			fclose($basefp2);
			fclose($basefp);

			if ($delete)
			{
				EFSSIncremental::Delete($incrementalfile);
				file_put_contents($incrementalfile . ".partial", "");
			}

			unlink($basefile . ".partial");

			return array("success" => true);
		}

		public static function Verify($key1, $iv1, $key2, $iv2, $incrementalfile, $blocksize = 4096, $callbackfunc = false)
		{
			if (!file_exists($incrementalfile) || !file_exists($incrementalfile . ".updates") || !file_exists($incrementalfile . ".serial") || file_exists($incrementalfile . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system incremental does not exist."), "errorcode" => "does_not_exist");

			$incfp = fopen($incrementalfile, "rb");
			if ($incfp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental block file."), "errorcode" => "block_file_open");

			$startts = microtime(true);
			$blocksprocessed = 0;
			$totalblocks = (int)(EFSS::RawFileSize($incfp) / $blocksize);
			fseek($incfp, 0, SEEK_SET);

			if ($key1 == "" || $iv1 == "" || $key2 == "" || $iv2 == "")
			{
				if (!file_exists($incrementalfile . ".hashes"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system incremental hashes file does not exist."), "errorcode" => "does_not_exist");

				$incfp2 = fopen($incrementalfile . ".updates", "rb");
				if ($incfp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental updates file."), "errorcode" => "updates_file_open");
				$incfp3 = fopen($incrementalfile . ".hashes", "rb");
				if ($incfp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental hashes file."), "errorcode" => "hashes_file_open");

				$numblocks = (int)(1048576 / $blocksize);
				$incblockdata = fread($incfp, 1048576);
				$incupdates = fread($incfp2, 20 * $numblocks);
				$inchashes = fread($incfp3, 36 * $numblocks);
				while ($incblockdata !== false && $incblockdata != "")
				{
					$y = (int)(strlen($incupdates) / 20);
					for ($x = 0; $x < $y; $x++)
					{
						$data = substr($incblockdata, $x * $blocksize, $blocksize);
						$data .= substr($incupdates, $x * 20, 20);

						if (substr($inchashes, $x * 36, 16) !== md5($data, true) || substr($inchashes, ($x * 36) + 16, 20) !== sha1($data, true))  return array("success" => false, "error" => EFSS::Translate("Hash failure detected.  Possible data corruption."), "errorcode" => "invalid_hash");
					}

					$blocksprocessed += $y;
					if ($callbackfunc !== false)  $callbackfunc($blocksprocessed, $totalblocks, $startts);

					$incblockdata = fread($incfp, 1048576);
					$incupdates = fread($incfp2, 20 * $numblocks);
					$inchashes = fread($incfp3, 36 * $numblocks);
				}

				fclose($incfp3);
				fclose($incfp2);
			}
			else
			{
				// Set up ciphers.
				$cipher1 = new Crypt_AES();
				$cipher1->setKey($key1);
				$cipher1->setIV($iv1);
				$cipher1->disablePadding();

				$cipher2 = new Crypt_AES();
				$cipher2->setKey($key2);
				$cipher2->setIV($iv2);
				$cipher2->disablePadding();

				// Read in incremental updates 1MB at a time.
				$firstblock = true;
				$incblockdata = fread($incfp, 1048576);
				while ($incblockdata !== false && $incblockdata != "")
				{
					$y = strlen($incblockdata);
					for ($x = 0; $x < $y; $x += $blocksize)
					{
						$block = substr($incblockdata, $x, $blocksize);
						$result = EFSS::RawDecryptBlock($cipher1, $cipher2, $block, EFSS_BLOCKTYPE_ANY);
						if (!$result["success"])  return $result;

						if ($firstblock && $result["type"] !== EFSS_BLOCKTYPE_FIRST)  return array("success" => false, "error" => EFSS::Translate("First block is not of the correct type."), "errorcode" => "block_type");
						else if (!$firstblock && $result["type"] !== EFSS_BLOCKTYPE_DIR && $result["type"] !== EFSS_BLOCKTYPE_FILE && $result["type"] !== EFSS_BLOCKTYPE_UNUSED_LIST && $result["type"] !== EFSS_BLOCKTYPE_UNUSED)  return array("success" => false, "error" => EFSS::Translate("A block in the file does not have a valid type."), "errorcode" => "block_type");

						$firstblock = false;
					}

					$blocksprocessed += (int)($y / $blocksize);
					if ($callbackfunc !== false)  $callbackfunc($blocksprocessed, $totalblocks, $startts);

					$incblockdata = fread($incfp, 1048576);
				}
			}

			fclose($incfp);

			return array("success" => true, "blocks" => $totalblocks, "startts" => $startts, "endts" => microtime(true));
		}

		public static function MakeReverseDiffIncremental($basefile, $incrementalfile, $blocksize = 4096)
		{
			if (!file_exists($basefile . ".rdiff") || !file_exists($basefile . ".rdiff.updates") || !file_exists($basefile . ".rdiff.hashes") || !file_exists($basefile . ".rdiff.blockmap") || !file_exists($basefile . ".rdiff.blockinfo") || !file_exists($basefile . ".serial") || file_exists($incrementalfile . ".partial"))  return array("success" => false, "error" => EFSS::Translate("Encrypted file storage system incremental does not exist."), "errorcode" => "does_not_exist");

			// Temporarily declare the incremental file as a partial file.
			file_put_contents($incrementalfile . ".partial", "");

			// Open file handles.
			$basefp = fopen($basefile . ".rdiff", "r+b");
			if ($basefp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff block file."), "errorcode" => "rdiff_block_file_open");
			$basefp2 = fopen($basefile . ".rdiff.updates", "r+b");
			if ($basefp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff updates file."), "errorcode" => "rdiff_updates_file_open");
			$basefp3 = fopen($basefile . ".rdiff.hashes", "r+b");
			if ($basefp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff hashes file."), "errorcode" => "rdiff_hashes_file_open");
			$basefp4 = fopen($basefile . ".rdiff.blockmap", "r+b");
			if ($basefp4 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open reverse diff block mapping file."), "errorcode" => "rdiff_block_map_file_open");

			$incfp = fopen($incrementalfile, "wb");
			if ($incfp === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental block file."), "errorcode" => "block_file_open");
			$incfp2 = fopen($incrementalfile . ".updates", "wb");
			if ($incfp2 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental updates file."), "errorcode" => "updates_file_open");
			$incfp3 = fopen($incrementalfile . ".hashes", "wb");
			if ($incfp3 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental hashes file."), "errorcode" => "hashes_file_open");
			$incfp4 = fopen($incrementalfile . ".blocknums", "wb");
			if ($incfp4 === false)  return array("success" => false, "error" => EFSS::Translate("Unable to open incremental block numbers file."), "errorcode" => "block_nums_file_open");

			file_put_contents($incrementalfile . ".serial", file_get_contents($basefile . ".serial"));

			$blocknum = 0;
			$diffmap = fread($basefp4, 1048576);
			while ($diffmap !== false && $diffmap != "")
			{
				$y = strlen($diffmap);
				for ($x = 0; $x + 3 < $y; $x += 4)
				{
					$data = substr($diffmap, $x, 4);
					if ($data !== "\xFF\xFF\xFF\xFF")
					{
						$pos = EFSS::UnpackInt($data);

						EFSS::RawSeek($basefp, $blocksize * $pos);
						fwrite($incfp, fread($basefp, $blocksize));

						EFSS::RawSeek($basefp2, 20 * $pos);
						fwrite($incfp2, fread($basefp2, 20));

						EFSS::RawSeek($basefp3, 36 * $pos);
						fwrite($incfp3, fread($basefp3, 36));

						fwrite($incfp4, pack("N", $blocknum));
					}

					$blocknum++;
				}

				$diffmap = fread($basefp4, 1048576);
			}

			fclose($incfp4);
			fclose($incfp3);
			fclose($incfp2);
			fclose($incfp);
			fclose($basefp4);
			fclose($basefp3);
			fclose($basefp2);
			fclose($basefp);

			// Reset reverse diff.
			file_put_contents($basefile . ".rdiff", "");
			file_put_contents($basefile . ".rdiff.updates", "");
			unlink($basefile . ".rdiff.hashes");
			unlink($basefile . ".rdiff.blockmap");
			unlink($basefile . ".rdiff.blockinfo");

			unlink($incrementalfile . ".partial");

			return array("success" => true);
		}
	}
?>