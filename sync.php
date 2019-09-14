<?php
	// Encrypted File Storage System command-line sync
	// (C) 2013 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	$basets = microtime(true);

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/efss.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"d" => "debug",
			"e" => "exclude",
			"f" => "full",
			"h" => "hook",
			"i" => "incrementals",
			"k" => "keep",
			"v" => "verbose",
			"?" => "help"
		),
		"rules" => array(
			"debug" => array("arg" => false),
			"exclude" => array("arg" => true, "multiple" => true),
			"full" => array("arg" => false),
			"hook" => array("arg" => true),
			"incrementals" => array("arg" => true),
			"keep" => array("arg" => true, "multiple" => true),
			"verbose" => array("arg" => false),
			"help" => array("arg" => false)
		)
	);
	$args = CLI::ParseCommandLine($options);

	if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System command-line sync tool\n";
		echo "Purpose:  Synchronize data to/from EFSS data stores.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] Source Target\n";
		echo "         For EFSS, use 'efss://{EFSS data store}/{abspath}'\n";
		echo "\n";
		echo "Options:\n";
		echo "\t-d           Log writes to a file.\n";
		echo "\t-e=pattern   Exclude Source paths matching the pattern.\n";
		echo "\t-f           Perform full binary comparison of files.\n";
		echo "\t-h=file      PHP file to include to hook some aspects of sync.\n";
		echo "\t-i=num       Number of incrementals to mount for Source.\n";
		echo "\t-k=pattern   Keep Target paths matching the pattern.\n";
		echo "\t-v           Verbose mode.  Displays every change made.\n";
		echo "\t-?           This help documentation.\n";
		echo "\n";
		echo "Example:  php " . $args["file"] . " -e=/cache/ /etc efss:///var/www/backup.dat//etc\n";

		exit();
	}

	$fullcompare = isset($args["opts"]["full"]);
	$verbose = isset($args["opts"]["verbose"]);

	if (isset($args["opts"]["hook"]) && file_exists($args["opts"]["hook"]))  require_once $args["opts"]["hook"];

	function DisplayError($msg, $result = false)
	{
		echo $msg . "\n";

		if ($result !== false)
		{
			echo $result["error"] . " (" . $result["errorcode"] . ")\n";
			if (isset($result["info"]))  var_dump($result["info"]);
		}

		exit();
	}

	$srctype = true;
	$srcpath = $args["params"][0];
	if (strtolower(substr($srcpath, 0, 7)) === "efss://")
	{
		$srcpath = (string)substr($srcpath, 7);
		$pos = strpos($srcpath, "//");
		if ($pos === false)
		{
			echo "Error:  Unable to open EFSS file set.\n";
			echo "        Expected to find '//' in '" . $srcpath . "'.\n";

			exit();
		}

		$efsspath = substr($srcpath, 0, $pos);
		$srcpath = substr($srcpath, $pos + 1);
		require_once $efsspath . ".php";

		$incrementals = array();
		$num = (int)(isset($args["opts"]["incrementals"]) ? $args["opts"]["incrementals"] : 0);
		$prefix = ($num < 0 ? ".r" : ".");
		$num = abs($num);
		for ($x = 1; $x <= $num; $x++)  $incrementals[] = $efsspath . $prefix . $x;

		$srctype = new EFSS;
		$result = $srctype->Mount($key1, $iv1, $key2, $iv2, $efsspath, EFSS_MODE_READ, $lockfile, $blocksize, $incrementals);
		if (!$result["success"])  DisplayError("Error:  Unable to mount the file system for reading.", $result);

		$realsrcpath = dirname(realpath($efsspath));
	}

	$srchelper = new EFSS_DirCopyHelper;
	$result = $srchelper->Init($srctype, $srcpath);
	if (!$result["success"])  DisplayError("Unable to open the source directory.", $result);
	if ($srctype === true)  $realsrcpath = realpath($srcpath);
	$realsrcpath = str_replace("\\", "/", $realsrcpath);
	if ($realsrcpath != "/" && substr($realsrcpath, -1) == "/")  $realsrcpath = substr($realsrcpath, 0, -1);

	$desttype = true;
	$destpath = $args["params"][1];
	if (strtolower(substr($destpath, 0, 7)) === "efss://")
	{
		$destpath = (string)substr($destpath, 7);
		$pos = strpos($destpath, "//");
		if ($pos === false)
		{
			echo "Error:  Unable to open EFSS file set.\n";
			echo "        Expected to find '//' in '" . $destpath . "'.\n";

			exit();
		}

		$efsspath = substr($destpath, 0, $pos);
		$destpath = substr($destpath, $pos + 1);
		require_once $efsspath . ".php";

		// Enable EFSS debugging, if specified.
		if (isset($args["opts"]["debug"]))  define("EFSS_DEBUG_LOG", $efsspath . ".log.txt");

		$desttype = new EFSS;
		$result = $desttype->Mount($key1, $iv1, $key2, $iv2, $efsspath, EFSS_MODE_EXCL, $lockfile, $blocksize);
		if (!$result["success"])  DisplayError("Error:  Unable to mount the file system for writing.", $result);

		$realdestpath = dirname(realpath($efsspath));
	}

	$desthelper = new EFSS_DirCopyHelper;
	$result = $desthelper->Init($desttype, $destpath, true);
	if (!$result["success"])  DisplayError("Unable to open the destination directory.", $result);
	if ($desttype === true)  $realdestpath = realpath($destpath);
	$realdestpath = str_replace("\\", "/", $realdestpath);
	if ($realdestpath != "/" && substr($realdestpath, -1) == "/")  $realdestpath = substr($realdestpath, 0, -1);

	// Determine real file system exclusions.  Avoid infinite loops and dumb scenarios.
	$excluderealsrc = false;
	$excluderealdest = false;

	// Detect /etc to /etc as well as /etc to /etc/efss_backup.dat.
	// It is easier to just exclude the directory an EFSS data store is in.
	if ($realsrcpath === $realdestpath)  DisplayError("Error:  Unable to exclude destination '" . $realdestpath . "'.");

	if (substr($realsrcpath, 0, strlen($realdestpath)) === $realdestpath)
	{
		// Detected /etc/dir/dir2 to /etc.  Make /etc/dir/dir2/dir/dir2 source and /etc/dir/dir2 destination off limits.
		$path = substr($realsrcpath, strlen($realdestpath));
		if ($srctype === true)  $excluderealsrc = $realsrcpath . $path;
		if ($desttype === true)  $excluderealdest = $realsrcpath;
	}
	else if (substr($realdestpath, 0, strlen($realsrcpath)) === $realsrcpath)
	{
		// Detected /etc to /etc/dir/dir2.  Make /etc/dir/dir2 source off limits.
		// Destination protection of /etc/dir/dir2/dir/dir2 doesn't matter.
		if ($srctype === true)  $excluderealsrc = $realdestpath;
	}

	if (!isset($args["opts"]["exclude"]))  $args["opts"]["exclude"] = array();
	$excludesrc = $args["opts"]["exclude"];

	if (!isset($args["opts"]["keep"]))  $args["opts"]["keep"] = array();
	$keepdest = $args["opts"]["keep"];

	function ProcessDirHelpers($src, $dest)
	{
		global $fullcompare, $verbose, $excluderealsrc, $excluderealdest, $excludesrc, $keepdest;

		// Read all source directory items.
		$items = "";
		$srcpath = $src->GetName();
		$result = $src->readdir();
		while ($result["success"])
		{
			$name = $result["name"];

			if ($name !== "." && $name !== ".." && ($excluderealsrc === false || $excluderealsrc !== $srcpath . "/" . $name))
			{
				$match = false;
				$pathname = $srcpath . "/" . $name;
				foreach ($excludesrc as $pattern)
				{
					if (preg_match($pattern, $pathname))  $match = true;
				}

				if (!$match)  $items .= "/" . $name;
			}

			$result = $src->readdir();
		}

		if ($result["errorcode"] != "dir_end")  DisplayError("Error:  Unknown source directory entry read error.", $result);

		$items .= "/";

		// Remove items in destination not in source, not the same type as the source, or symlinks that have changed.
		$items2 = "";
		$destpath = $dest->GetName();
		$result = $dest->readdir();
		while ($result["success"])
		{
			$name = $result["name"];

			if ($name !== "." && $name !== ".." && ($excluderealdest === false || $excluderealdest !== $destpath . "/" . $name))
			{
				$match = false;
				$pathname = $destpath . "/" . $name;
				foreach ($keepdest as $pattern)
				{
					if (preg_match($pattern, $pathname))  $match = true;
				}

				if (!$match)
				{
					$result = $dest->filetype($destpath . "/" . $name);
					if (!$result["success"])  DisplayError("Error:  Impossible destination read error.", $result);

					$remove = false;
					if (strpos($items, "/" . $name . "/") === false)
					{
						$remove = true;
						$reason = "removed_from_source";
					}

					if (!$remove)
					{
						$result2 = $src->filetype($srcpath . "/" . $name);
						if (!$result2["success"])  DisplayError("Error:  Unknown source read error.", $result2);

						if ($result["type"] != $result2["type"])
						{
							$remove = true;
							$reason = "different_types";
						}
						else if ($result["type"] == "link")
						{
							$srchelper = new EFSS_SymlinkCopyHelper;
							$result2 = $srchelper->Init($src->GetMount(), $srcpath . "/" . $name);
							if (!$result2["success"])  DisplayError("Unable to access the source symlink '" . $srcpath . "/" . $name . "'.", $result2);

							$result2 = $srchelper->readlink();
							if (!$result2["success"])  DisplayError("Unable to read the target of the source symlink '" . $srcpath . "/" . $name . "'.", $result2);
							$srctarget = $result2["link"];

							$desthelper = new EFSS_SymlinkCopyHelper;
							$result2 = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name);
							if (!$result2["success"])  DisplayError("Unable to access the destination symlink '" . $destpath . "/" . $name . "'.", $result2);

							$result2 = $desthelper->readlink();
							if (!$result2["success"])  DisplayError("Unable to read the target of the destination symlink '" . $destpath . "/" . $name . "'.", $result2);
							$desttarget = $result2["link"];

							if ($srctarget !== $desttarget)
							{
								$remove = true;
								$reason = "symlink_changed";
							}
						}
					}

					if (!$remove)  $items2 .= "/" . $name;
					else
					{
						if ($result["type"] == "dir")
						{
							$result = $dest->rmdir($destpath . "/" . $name, true);
							if (!$result["success"])  DisplayError("Error:  Unable to delete directory '" . $destpath . "/" . $name . "'.", $result);
							if ($verbose)  echo $destpath . "/" . $name . " [deleted_directory] [" . $reason . "]\n";
						}
						else if ($result["type"] == "link")
						{
							$result = $dest->unlink($destpath . "/" . $name);
							if (!$result["success"])  DisplayError("Error:  Unable to delete symlink '" . $destpath . "/" . $name . "'.", $result);
							if ($verbose)  echo $destpath . "/" . $name . " [deleted_symlink] [" . $reason . "]\n";
						}
						else if ($result["type"] == "file")
						{
							$result = $dest->unlink($destpath . "/" . $name);
							if (!$result["success"])  DisplayError("Error:  Unable to delete file '" . $destpath . "/" . $name . "'.", $result);
							if ($verbose)  echo $destpath . "/" . $name . " [deleted_file] [" . $reason . "]\n";
						}
						else
						{
							DisplayError("Error:  Unknown type '" . $result["type"] . "' found in destination '" . $destpath . "'.");
						}
					}
				}
			}

			$result = $dest->readdir();
		}

		if ($result["errorcode"] != "dir_end")  DisplayError("Error:  Unknown destination directory entry read error.", $result);

		$items = $items2 . "/";
		unset($items2);

		// Sync items from source to the destination.
		$src->rewinddir();
		$result = $src->readdir();
		while ($result["success"])
		{
			$name = $result["name"];

			if ($name !== "." && $name !== ".." && ($excluderealsrc === false || $excluderealsrc !== $srcpath . "/" . $name))
			{
				$match = false;
				$pathname = $srcpath . "/" . $name;
				foreach ($excludesrc as $pattern)
				{
					if (preg_match($pattern, $pathname))  $match = true;
				}

				if (!$match)
				{
					$copy = false;
					if (strpos($items, "/" . $name . "/") === false)  $copy = true;

					$result = $src->filetype($srcpath . "/" . $name);
					if (!$result["success"] && $result["errorcode"] != "does_not_exist")  DisplayError("Error:  Unknown source read error.", $result);
					$srcfiletype = $result["type"];

					if ($srcfiletype == "dir")
					{
						$srchelper = new EFSS_DirCopyHelper;
						$result = $srchelper->Init($src->GetMount(), $srcpath . "/" . $name);
						if (!$result["success"])  DisplayError("Unable to open the source directory '" . $srcpath . "/" . $name . "'.", $result);

						$copy = true;
					}
					else if ($srcfiletype == "link")
					{
						$srchelper = new EFSS_SymlinkCopyHelper;
						$result = $srchelper->Init($src->GetMount(), $srcpath . "/" . $name);
						if (!$result["success"])  DisplayError("Unable to access the source symlink '" . $srcpath . "/" . $name . "'.", $result);
					}
					else if ($srcfiletype == "file")
					{
						$srchelper = new EFSS_FileCopyHelper;
						$result = $srchelper->Init($src->GetMount(), $srcpath . "/" . $name, "rb");
						if (!$result["success"])  DisplayError("Unable to open the source file '" . $srcpath . "/" . $name . "'.", $result);
					}
					else
					{
						DisplayError("Error:  Unknown type '" . $srcfiletype . "' found in source '" . $srcpath . "'.");
					}

					// Get source stat.
					$result = $srchelper->GetStat();
					if (!$result["success"])  DisplayError("Unable to get stats for the source '" . $srcpath . "/" . $name . "'.", $result);
					$srcstat = $result["stat"];

					// Allow a hook function to alter the source file's stats.
					if (function_exists("hook_ProcessDirHelpers_srcinfo"))  hook_ProcessDirHelpers_srcinfo($srcpath, $name, $srcfiletype, $srcstat, $destpath, $copy);

					// Determine copy status for file data.
					// If the file already exists in the destination, don't copy it.
					if (!$copy && $srcfiletype == "file")
					{
						$desthelper = new EFSS_FileCopyHelper;
						$result = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name, "rb");
						if ($result["success"])
						{
							$result = $desthelper->GetStat();
							if (!$result["success"])  DisplayError("Unable to get stats for the destination file '" . $destpath . "/" . $name . "'.", $result);

							if ($result["stat"]["mtime"] !== $srcstat["mtime"])  $copy = true;
							else if ($fullcompare)
							{
								do
								{
									// Compare up to 1MB chunks.
									$data = $srchelper->Read(1048576);
									$data2 = $desthelper->Read(1048576);
								} while ($data !== false && $data2 !== false && strlen($data) > 0 && strlen($data2) > 0 && $data === $data2);

								$copy = ($data !== $data2);

								// Reopen the source file if it didn't match the destination.
								if ($copy)
								{
									$result = $srchelper->Reopen("rb");
									if (!$result["success"])  DisplayError("Unable to reopen source file '" . $srcpath . "/" . $name . "'.", $result);
								}
							}

							unset($desthelper);
						}
					}

					if ($copy)
					{
						if ($srcfiletype == "dir")
						{
							$result = $dest->mkdir($destpath . "/" . $name);
							if (!$result["success"] && $result["errorcode"] != "already_exists")  DisplayError("Error:  Unable to create directory '" . $destpath . "/" . $name . "'.", $result);
							if ($result["success"] && $verbose)  echo $destpath . "/" . $name . " [created_directory]\n";

							// Copy the contents of the subdirectory.
							$desthelper = new EFSS_DirCopyHelper;
							$result = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name);
							if (!$result["success"])  DisplayError("Unable to open the destination directory '" . $destpath . "/" . $name . "'.", $result);

							ProcessDirHelpers($srchelper, $desthelper);
						}
						else if ($srcfiletype == "link")
						{
							$result = $srchelper->readlink();
							if (!$result["success"])  DisplayError("Unable to read the target of the source symlink '" . $srcpath . "/" . $name . "'.", $result);
							$target = $result["link"];

							$desthelper = new EFSS_SymlinkCopyHelper;
							$result = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name, $target);
							if (!$result["success"])  DisplayError("Unable to create the destination symlink '" . $destpath . "/" . $name . "'.", $result);

							if ($verbose)  echo $destpath . "/" . $name . " => " . $target . " [created_symlink]\n";
						}
						else if ($srcfiletype == "file")
						{
							// Read in 1MB.
							$data = $srchelper->Read(1048576);
							if ($data === false)  DisplayError("Unable to read the source file '" . $srcpath . "/" . $name . "'.");

							// Use file_put_contents() to write the data more efficiently if less than 1MB.
							if (strlen($data) < 1048576)
							{
								if ($dest->GetMount() === true)
								{
									if (file_put_contents($destpath . "/" . $name, $data) === false)  DisplayError("Error writing to '" . $destpath . "/" . $name . "'.");
								}
								else
								{
									$result = $dest->GetMount()->file_put_contents($destpath . "/" . $name, $data);
									if (!$result["success"])  DisplayError("Error writing to '" . $destpath . "/" . $name . "'.", $result);
								}

								unset($srchelper);
							}
							else
							{
								$desthelper = new EFSS_FileCopyHelper;
								$result = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name, "wb");
								if (!$result["success"])  DisplayError("Unable to open the destination file '" . $destpath . "/" . $name . "'.", $result);

								while (strlen($data) > 0)
								{
									if ($desthelper->Write($data) === false)  DisplayError("Error writing to '" . $destpath . "/" . $name . "'.");

									// Copy 1MB chunks.
									$data = $srchelper->Read(1048576);
									if ($data === false)  DisplayError("Unable to read the source file '" . $srcpath . "/" . $name . "'.");
								}

								unset($desthelper);
								unset($srchelper);
							}

							if ($verbose)  echo $destpath . "/" . $name . " [synced_file_data]\n";
						}
					}

					// Clone stats.
					if ($srcfiletype == "dir")
					{
						$desthelper = new EFSS_DirCopyHelper;
						$result = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name);
						if (!$result["success"])  DisplayError("Unable to open the destination directory '" . $destpath . "/" . $name . "'.", $result);
					}
					else if ($srcfiletype == "link")
					{
						$desthelper = new EFSS_SymlinkCopyHelper;
						$result = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name);
						if (!$result["success"])  DisplayError("Unable to access the destination symlink '" . $destpath . "/" . $name . "'.", $result);
					}
					else if ($srcfiletype == "file")
					{
						$desthelper = new EFSS_FileCopyHelper;
						$result = $desthelper->Init($dest->GetMount(), $destpath . "/" . $name, "rb");
						if (!$result["success"])  DisplayError("Unable to open the destination file '" . $destpath . "/" . $name . "'.", $result);
					}

					$result = $desthelper->SetStat($srcstat);
					if (!$result["success"])  DisplayError("Unable to set stats for the destination '" . $destpath . "/" . $name . "'.", $result);
					if ($verbose && $result["changed"])  echo $destpath . "/" . $name . " [changed_stats]\n";
				}
			}

			$result = $src->readdir();
		}

		if ($result["errorcode"] != "dir_end")  DisplayError("Error:  Unknown source directory entry read error.", $result);
	}

	if (function_exists("hook_pre_ProcessDirHelpers"))  hook_pre_ProcessDirHelpers();

	ProcessDirHelpers($srchelper, $desthelper);

	if (function_exists("hook_post_ProcessDirHelpers"))  hook_post_ProcessDirHelpers();

	if ($verbose)  echo "Time taken:  " . number_format(microtime(true) - $basets, 2) . " sec [seconds_total]\n";
	if ($verbose && function_exists("memory_get_peak_usage"))  echo "Maximum RAM used:  " . number_format(memory_get_peak_usage(), 0) . " [max_ram_used]\n";
	if ($verbose)  echo "\n";
?>