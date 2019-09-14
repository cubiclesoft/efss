<?php
	// Encrypted File Storage System command-line backup manager
	// (C) 2013 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/efss.php";
	require_once $rootpath . "/support/http.php";
	require_once $rootpath . "/support/web_browser.php";
	if (file_exists($rootpath . "/backup_hook.php"))  require_once $rootpath . "/backup_hook.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"a" => "attempts",
			"c" => "checkall",
			"d" => "debug",
			"k" => "keeprebuilds",
			"l" => "lockfile",
			"i" => "incrementals",
			"p" => "progress",
			"r" => "reset",
			"v" => "verify",
			"w" => "waittime",
			"?" => "help"
		),
		"rules" => array(
			"attempts" => array("arg" => true),
			"checkall" => array("arg" => false),
			"debug" => array("arg" => false),
			"keeprebuilds" => array("arg" => false),
			"lockfile" => array("arg" => true),
			"incrementals" => array("arg" => true),
			"progress" => array("arg" => true),
			"reset" => array("arg" => false),
			"verify" => array("arg" => false),
			"waittime" => array("arg" => true),
			"help" => array("arg" => false)
		)
	);
	$args = CLI::ParseCommandLine($options);

	if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System command-line backup manager\n";
		echo "Purpose:  Incremental backup management of remote EFSS data stores.\n";
		echo "\n";
		echo "Pull syntax:  " . $args["file"] . " [options] URL BaseFilename\n";
		echo "Push syntax:  " . $args["file"] . " [options] BaseFilename URL\n";
		echo "\n";
		echo "Pull options:\n";
		echo "\t-a=num    Maximum number of attempts.  Default is 3.\n";
		echo "\t-c        Check downloaded file system (-v plus file check).\n";
		echo "\t-d        Log writes to a file.\n";
		echo "\t-k        Keep rebuilds.\n";
		echo "\t-l=file   Lock file.  Default is BaseFilename.\n";
		echo "\t-i=num    Number of incrementals before rollup.\n";
		echo "\t-p=num    Progress display frequency.  Default is 10.\n";
		echo "\t-r        Reset/delete backup and exit.\n";
		echo "\t-v        Verify downloaded incremental data.\n";
		echo "\t-w=num    Time to wait for host to obtain lock.  Default is 5.\n";
		echo "\t-?        This help documentation.\n";
		echo "\n";
		echo "Push options:\n";
		echo "\t-a=num   Maximum number of attempts.  Default is 3.\n";
		echo "\t-d       Log writes to a file.\n";
		echo "\t-w=num   Time to wait for host to obtain lock.  Default is 5.\n";
		echo "\t-?       This help documentation.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . " -i=10 https://website.com/backup/?token=xyz backup.dat\n";
		echo "\tphp " . $args["file"] . " local_backup.dat https://website.com/backup/?token=xyz\n";

		exit();
	}

	function DeleteCurrentIncremental()
	{
		global $writerstartblock, $filename;

		$writerstartblock = 0;

		EFSSIncremental::Delete($filename);
	}

	function DisplayError($msg, $result = false)
	{
		global $pullbackup, $backupid, $maxattempts, $baseurl, $url, $web, $waittime;

		echo $msg . "\n";

		if ($result !== false)
		{
			if (isset($result["error"]))  echo $result["error"] . " (" . $result["errorcode"] . ")\n";
			if (isset($result["info"]))  var_dump($result["info"]);
		}

		if ($pullbackup)  DeleteCurrentIncremental();
		else if ($backupid !== false)
		{
			// Delete the incremental on the server.
			$retries = $maxattempts;
			do
			{
				$result2 = false;
				$pos = strpos($baseurl, "?");
				$url = $baseurl . ($pos === false ? "?" : "&") . "ver=" . EFSS_VERSION . "&dir=push&wait=" . urlencode($waittime) . "&mode=cleanup&id=" . $backupid;
				$web = new WebBrowser();
				if (function_exists("BackupHook_ProcessPushURL"))  $result2 = BackupHook_ProcessPushURL("cleanup");
				else  $result2 = $web->Process($url);

				if (!is_array($result2))  exit();

				$retries--;
			} while ($retries && !$result2["success"]);
		}

		exit();
	}

	$startts = microtime(true);

	$pullbackup = (strpos($args["params"][0], "://") !== false);
	$backupid = false;

	$waittime = (isset($args["opts"]["waittime"]) && (float)$args["opts"]["waittime"] > 0 ? (float)$args["opts"]["waittime"] : 5);
	$maxattempts = (isset($args["opts"]["attempts"]) && (int)$args["opts"]["attempts"] > 0 ? (int)$args["opts"]["attempts"] : 3);
	$restarts = $maxattempts;
	$startblock = 0;
	$lastwrite = 0;
	$rawsendsize = 0;
	$rawrecvsize = 0;

	if ($pullbackup)
	{
		$baseurl = $args["params"][0];
		$basefile = $args["params"][1];

		// Enable EFSS debugging, if specified.
		if (isset($args["opts"]["debug"]))  define("EFSS_DEBUG_LOG", $rootpath . "/" . $basefile . ".log.txt");

		// Obtain lock.
		$lockfile = (isset($args["opts"]["lockfile"]) ? $args["opts"]["lockfile"] : $basefile);
		$checkall = isset($args["opts"]["checkall"]);
		$verify = isset($args["opts"]["verify"]) || $checkall;
		if ($verify)
		{
			if (!file_exists($basefile . ".php"))  DisplayError("Error:  The file '" . $basefile . ".php' does not exist.  Unable to verify downloads.");
			require_once $basefile . ".php";
			if ($lockfile === false)  $lockfile = $basefile;
			$lastblocksize = $blocksize;
		}
		echo "Obtaining lock...";
		$result = EFSSIncremental::GetLock($lockfile, true);
		if (!$result["success"])  DisplayError("Unable to obtain lock.", $result);
		echo "done.\n";
		$writelock = $result["lock"];

		// Calculate next incremental and get the 'since' timestamp.
		$filename = $basefile;
		$serial = false;
		$incnum = 0;
		$since = "0000-00-00 00:00:00 ";
		if (file_exists($basefile) && file_exists($basefile . ".updates") && file_exists($basefile . ".serial") && !file_exists($basefile . ".blocknums") && !file_exists($basefile . ".partial"))
		{
			$serial = trim(file_get_contents($basefile . ".serial"));
			$result = EFSSIncremental::LastUpdated($filename);
			if (!$result["success"])  DisplayError("Unable to get last updated timestamp.", $result);
			$since = $result["lastupdate"];

			$incnum++;
			$filename = $basefile . "." . $incnum;
			while (file_exists($filename) && file_exists($filename . ".updates") && file_exists($filename . ".serial") && file_exists($filename . ".blocknums") && trim(file_get_contents($filename . ".serial")) === $serial && !file_exists($basefile . ".partial"))
			{
				$result = EFSSIncremental::LastUpdated($filename);
				if (!$result["success"])  DisplayError("Unable to get last updated timestamp.", $result);
				$since = $result["lastupdate"];

				$incnum++;
				$filename = $basefile . "." . $incnum;
			};
		}

		function ResetBackup()
		{
			global $args, $basefile, $incnum, $filename;

			$keeprebuilds = isset($args["opts"]["keeprebuilds"]);
			if ($keeprebuilds)
			{
				$path = $basefile . "-" . date("Ymd-His");
				if (!@mkdir($path, 0777))  DisplayError("Error:  Unable to create backup directory for the existing backup before the rebuild.");

				if ($incnum > 0)
				{
					@copy($basefile, $path . "/" . $basefile);
					@copy($basefile . ".updates", $path . "/" . $basefile . ".updates");
					@copy($basefile . ".serial", $path . "/" . $basefile . ".serial");
					@copy($basefile . ".hashes", $path . "/" . $basefile . ".hashes");

					for ($x = 1; $x < $incnum; $x++)
					{
						@copy($basefile . "." . $x, $path . "/" . $basefile . "." . $x);
						@copy($basefile . "." . $x . ".updates", $path . "/" . $basefile . "." . $x . ".updates");
						@copy($basefile . "." . $x . ".serial", $path . "/" . $basefile . "." . $x . ".serial");
						@copy($basefile . "." . $x . ".blocknums", $path . "/" . $basefile . "." . $x . ".blocknums");
						@copy($basefile . "." . $x . ".hashes", $path . "/" . $basefile . "." . $x . ".hashes");
					}
				}
			}

			// Wipe existing files.
			DeleteCurrentIncremental();
			$filename = $basefile;
			DeleteCurrentIncremental();
			for ($x = 1; $x < $incnum; $x++)
			{
				$filename = $basefile . "." . $x;
				DeleteCurrentIncremental();
			}

			echo "Backup reset.\n";
		}

		if (isset($args["opts"]["reset"]))
		{
			ResetBackup();

			exit();
		}

		$lastblocksize = false;
		$writerstartblock = 0;

		do
		{
			// Download a chunk of data.
			$startts2 = microtime(true);
			$retries = $maxattempts;
			do
			{
				$result = false;
				$pos = strpos($baseurl, "?");
				$url = $baseurl . ($pos === false ? "?" : "&") . "ver=" . EFSS_VERSION . "&dir=pull&wait=" . urlencode($waittime) . "&since=" . urlencode($since) . "&startblock=" . $startblock . "&lastwrite=" . urlencode($lastwrite);
				$web = new WebBrowser();
				if (function_exists("BackupHook_ProcessPullURL"))  $result = BackupHook_ProcessPullURL();
				else  $result = $web->Process($url);

				if (!is_array($result) || !isset($result["success"]))  DisplayError("Error:  Invalid result from web request '" . $url . "'.");

				$retries--;
			} while ($retries && !$result["success"]);

			if (!$result["success"])  DisplayError("Error retrieving URL.", $result);
			if ($result["response"]["code"] != 200)  DisplayError("Error retrieving URL.  Server returned:  " . $result["response"]["code"] . " " . $result["response"]["meaning"], $result);
			if (strlen($result["body"]) > 15485760)  DisplayError("Error:  Response too large.  Limit is roughly 15MB.");

			$rawsendsize += $result["rawsendsize"];
			$rawrecvsize += $result["rawrecvsize"];

			// Read the first line and extract it.
			$pos = strpos($result["body"], "\n");
			if ($pos === false)  DisplayError("Error:  Unable to find response line.");
			$info = @json_decode(@base64_decode(trim(substr($result["body"], 0, $pos))), true);
			if (!is_array($info))  DisplayError("Error:  Response line from server is not valid.  Must be a base64 encoded JSON string.  Received:  " . $result["body"]);
			if (!isset($info["success"]))  DisplayError("Error:  Response line from server is not valid.  Must contain 'success' status.");
			if (!$info["success"])  DisplayError("Error:  Server returned a failure response.", $info);
			if (!isset($info["lastwrite"]) || !isset($info["blocksize"]) || !isset($info["numblocks"]))  DisplayError("Error:  Server returned an invalid response.  Expecting 'lastwrite', 'blocksize', and 'numblocks'.");
			$blocksize = (int)$info["blocksize"];
			if ($blocksize == 0 || $blocksize % 4096 != 0 || $blocksize > 32768)  DisplayError("Error:  Server returned an invalid 'blocksize'.  The block size must be a multiple of 4096 and able to fit into an 'unsigned short'.");

			// Verify that the math for the rest of the data works out.
			$numblocks = (int)$info["numblocks"];
			$data = substr($result["body"], $pos + 1);
			if (strlen($data) != ($numblocks * $blocksize + $numblocks * 20 + ($incnum > 0 ? $numblocks * 4 : 0)))  DisplayError("Error:  Server returned an invalid response.  Expected " . ($numblocks * $blocksize + $numblocks * 20 + ($incnum > 0 ? $numblocks * 4 : 0)) . " bytes.  Got " . strlen($data) . " bytes.");

			echo "Retrieved " . number_format($numblocks, 0) . " blocks in " . number_format(microtime(true) - $startts2, 2) . " sec.\n";

			// Check for a completely new backup.
			if ($serial !== false && isset($info["serial"]) && $serial !== trim($info["serial"]))
			{
				ResetBackup();

				// Reset state.
				$filename = $basefile;
				$serial = false;
				$incnum = 0;
				$since = "0000-00-00 00:00:00 ";
				$startblock = 0;
				$lastblocksize = false;
				$lastwrite = 0;

				// Have to restart to avoid potential data timing issues (very unlikely though).
				$restarts--;
				if ($restarts < 1)  DisplayError("Error:  Maximum number of restarts reached.");
			}
			else
			{
				if ($lastblocksize !== false && $blocksize !== $lastblocksize)  DisplayError("Error:  Server returned an invalid 'blocksize'.  The block size must remain the same between calls.");

				// Store the block size so that 'check.php' can verify the backup later.
				if ($lastblocksize === false && !file_exists($basefile . ".php"))  EFSSIncremental::WritePHPFile($basefile, "", "", "", "", "", $blocksize, false);

				$lastblocksize = $blocksize;
				if (isset($info["serial"]))  $serial = trim($info["serial"]);

				if ($numblocks > 0)
				{
					// Write the block data to disk.
					if ($writerstartblock == 0)  DeleteCurrentIncremental();
					$result = EFSSIncremental::Write($filename, $writerstartblock, substr($data, 0, $numblocks * $blocksize), substr($data, $numblocks * $blocksize, $numblocks * 20), $info["md5"], $info["sha1"], ($incnum > 0 ? (string)substr($data, $numblocks * $blocksize + $numblocks * 20) : false), (isset($info["serial"]) ? $info["serial"] : false), $blocksize, !isset($info["nextblock"]));
					if (!$result["success"])  DisplayError("Error:  Unable to write incremental data.", $result);
					$writerstartblock = $result["nextblock"];
				}

				// Check for last block.
				if (!isset($info["nextblock"]))
				{
					EFSSIncremental::WriteFinalize($filename);

					break;
				}

				// Move to next block.
				$startblock = $info["nextblock"];
				$lastwrite = $info["lastwrite"];
			}
		} while (1);

		// Verify the incremental (if it exists and verification is wanted).
		if ($writerstartblock == 0)  $incnum--;
		else if ($verify)
		{
			function DisplayProgress($blocksprocessed, $totalblocks, $startts)
			{
				global $blocksize;

				echo "\t" . number_format($blocksprocessed, 0) . " blocks (";
				echo ($totalblocks > 0 ? (int)(100 * $blocksprocessed / $totalblocks) : "100");
				echo "%)";
				$diffts = microtime(true) - $startts;
				if ($blocksprocessed > 0 && $diffts > 1.0)
				{
					$rate = ($blocksprocessed / $diffts);
					echo ", " . number_format($rate, 0) . " blocks/s";
					if ($blocksprocessed < $totalblocks)
					{
						$timeleft = ($totalblocks - $blocksprocessed) / $rate;
						$secs = (int)($timeleft % 60);
						$timeleft = (int)($timeleft / 60);
						$mins = (int)($timeleft % 60);
						$hours = (int)($timeleft / 60);
						echo ", " . ($hours > 0 ? $hours . "h " : "") . ($hours > 0 || $mins > 0 ? $mins . "m " : "") . $secs . "s left";
					}
				}
				echo "\n";
			}

			function BlocksProcessedCallback($blocksprocessed, $totalblocks, $startts)
			{
				global $basets, $progressfrequecy;

				if ($basets === false)  $basets = $startts;
				if (microtime(true) - $basets > $progressfrequecy)
				{
					DisplayProgress($blocksprocessed, $totalblocks, $startts);

					$basets = microtime(true);
				}
			}

			function FileProcessedCallback($name)
			{
				global $basets, $progressfrequecy;

				if ($basets === false)  $basets = $startts;
				if (microtime(true) - $basets > $progressfrequecy)
				{
					echo "\t" . $name . "\n";

					$basets = microtime(true);
				}
			}

			$progressfrequecy = (double)(isset($args["opts"]["progress"]) ? $args["opts"]["progress"][0] : 10);

			echo "Verifying '" . $filename . "'...\n";
			$basets = false;
			$result = EFSSIncremental::Verify($key1, $iv1, $key2, $iv2, $filename, $lastblocksize, "BlocksProcessedCallback");
			if (!$result["success"])  DisplayError("Error:  Unable to verify incremental data.", $result);
			DisplayProgress($result["blocks"], $result["blocks"], $result["startts"]);
			echo "\tTime taken:  " . number_format($result["endts"] - $result["startts"], 2) . " sec\n";

			// Verify the incremental in the context of the whole file system.
			if ($checkall)
			{
				$incrementals = array();
				for ($x = 1; $x <= $incnum; $x++)  $incrementals[] = $basefile . "." . $x;

				// Release the lock so EFSS can mount.
				unset($writelock);

				$efss = new EFSS;
				$mode = (count($incrementals) || file_exists($basefile . ".readonly") ? EFSS_MODE_READ : EFSS_MODE_EXCL);
				$result = $efss->Mount($key1, $iv1, $key2, $iv2, $basefile, $mode, $lockfile, $lastblocksize, $incrementals);
				if (!$result["success"])  DisplayError("Error:  Unable to mount the file system.", $result);

				echo "\nChecking '" . $basefile . "' plus " . count($incrementals) . " incrementals...\n";
				$basets = false;
				$result = $efss->CheckFS("BlocksProcessedCallback", "FileProcessedCallback");
				if (!$result["success"])  DisplayError("Error:  Unable to verify the file system.", $result);
				echo "\tTime taken:  " . number_format($result["endts"] - $result["startts"], 2) . " sec\n";

				$result = $efss->Unmount();
				if (!$result["success"])  DisplayError("Error:  Unable to unmount the file system.", $result);

				unset($efss);

				$result = EFSSIncremental::GetLock($lockfile, true);
				if (!$result["success"])  DisplayError("Unable to obtain lock.", $result);
				$writelock = $result["lock"];
				unset($result);
			}
		}

		if ($incnum > 0)
		{
			// Roll up to maximum number of incrementals.
			$maxincrementals = (isset($args["opts"]["incrementals"]) && (int)$args["opts"]["incrementals"] > 0 ? (int)$args["opts"]["incrementals"] : 0);
			if ($incnum > $maxincrementals)
			{
				echo "Rolling up incrementals...";

				$diff = $incnum - $maxincrementals;
				for ($x = 1; $x <= $diff; $x++)
				{
					$result = EFSSIncremental::Merge($basefile, $basefile . "." . $x, $lastblocksize, true);
					if (!$result["success"])  DisplayError("Error:  Unable to merge incremental data into base file.", $result);
				}

				for (; $x <= $incnum; $x++)
				{
					@rename($basefile . "." . $x, $basefile . "." . ($x - $diff));
					@rename($basefile . "." . $x . ".updates", $basefile . "." . ($x - $diff) . ".updates");
					@rename($basefile . "." . $x . ".serial", $basefile . "." . ($x - $diff) . ".serial");
					@rename($basefile . "." . $x . ".blocknums", $basefile . "." . ($x - $diff) . ".blocknums");
					@rename($basefile . "." . $x . ".hashes", $basefile . "." . ($x - $diff) . ".hashes");
				}

				for ($x = 1; $x <= $diff; $x++)  @unlink($basefile . "." . $x . ".partial");

				echo "done.\n";
			}
		}
	}
	else
	{
		// Push local backup to a remote host incremental.
		$basefile = $args["params"][0];
		$baseurl = $args["params"][1];

		// Enable EFSS debugging, if specified.
		if (isset($args["opts"]["debug"]))  define("EFSS_DEBUG_LOG", $basefile . ".log.txt");

		if (!file_exists($basefile . ".php"))  DisplayError("Error:  The file '" . $basefile . ".php' does not exist.");
		require_once $basefile . ".php";

		if ($lockfile === false)  $lockfile = $basefile;
		$result = EFSSIncremental::GetLock($lockfile, false);
		if (!$result["success"])  DisplayError("Unable to obtain lock.", $result);
		$readlock = $result["lock"];

		// Initialize incremental on the server.
		echo "Initializing incremental...";
		$retries = $maxattempts;
		do
		{
			$result = false;
			$pos = strpos($baseurl, "?");
			$url = $baseurl . ($pos === false ? "?" : "&") . "ver=" . EFSS_VERSION . "&dir=push&wait=" . urlencode($waittime) . "&mode=init";
			$web = new WebBrowser();
			if (function_exists("BackupHook_ProcessPushURL"))  $result = BackupHook_ProcessPushURL("init");
			else  $result = $web->Process($url);

			if (!is_array($result) || !isset($result["success"]))  DisplayError("[INIT] Error:  Invalid result from web request '" . $url . "'.");

			$retries--;
		} while ($retries && !$result["success"]);

		if (!$result["success"])  DisplayError("[INIT] Error retrieving URL.", $result);
		if ($result["response"]["code"] != 200)  DisplayError("[INIT] Error retrieving URL.  Server returned:  " . $result["response"]["code"] . " " . $result["response"]["meaning"], $result);

		$rawsendsize += $result["rawsendsize"];
		$rawrecvsize += $result["rawrecvsize"];

		$initinfo = @json_decode(@base64_decode(trim($result["body"])), true);
		if (!is_array($initinfo))  DisplayError("[INIT] Error:  Response line from server is not valid.  Must be a base64 encoded JSON string.  Received:  " . $result["body"]);
		if (!isset($initinfo["success"]))  DisplayError("[INIT] Error:  Response line from server is not valid.  Must contain 'success' status.");
		if (!$initinfo["success"])  DisplayError("[INIT] Error:  Server returned a failure response.", $initinfo);
		if ($initinfo["blocksize"] !== $blocksize)  DisplayError("[INIT] Error:  Server 'blocksize' is different from backup file 'blocksize'.  Expected " . $blocksize . " but received " . $initinfo["blocksize"] . ".");
		$initinfo["serial"] = trim($initinfo["serial"]);
		if ($initinfo["serial"] !== "" && $initinfo["serial"] !== trim(file_get_contents($basefile . ".serial")))  DisplayError("[INIT] Error:  Server serial is different from the local backup file serial.");

		echo "done.\n";

		// Process incremental data.
		$sentblocks = false;
		$backupid = (int)$initinfo["id"];
		do
		{
			$nextdata = EFSSIncremental::Read($basefile, $initinfo["since"], $startblock, $lastwrite, false, $blocksize);
			if (!$nextdata["success"])  DisplayError("Error:  Unable to read backup data.", $nextdata);

			if ($nextdata["numblocks"] > 0)
			{
				echo "Uploading " . number_format($nextdata["numblocks"], 0) . " blocks...";

				// Upload incremental data to the server.  Uses non-Standard POST 'Content-Type'.
				$retries = $maxattempts;
				do
				{
					$result = false;
					$pos = strpos($baseurl, "?");
					$url = $baseurl . ($pos === false ? "?" : "&") . "ver=" . EFSS_VERSION . "&dir=push&wait=" . urlencode($waittime) . "&mode=data&id=" . $backupid . "&basesince=" . urlencode($initinfo["basesince"]) . "&startblock=" . $startblock . "&numblocks=" . $nextdata["numblocks"] . "&md5=" . $nextdata["md5"] . "&sha1=" . $nextdata["sha1"];
					$web = new WebBrowser();
					$options = array(
						"method" => "POST",
						"headers" => array(
							"Content-Type" => "application/x-efss-backup"
						),
						"body" => ($initinfo["serial"] === "" && $startblock == 0 ? trim(file_get_contents($basefile . ".serial")) : "") . "\n" . $nextdata["blocks"] . $nextdata["updates"] . (isset($nextdata["blocknums"]) ? $nextdata["blocknums"] : "")
					);
					if (function_exists("BackupHook_ProcessPushURL"))  $result = BackupHook_ProcessPushURL("data");
					else  $result = $web->Process($url, $options);

					if (!is_array($result) || !isset($result["success"]))  DisplayError("[DATA] Error:  Invalid result from web request '" . $url . "'.");

					$retries--;
				} while ($retries && !$result["success"]);

				if (!$result["success"])  DisplayError("[DATA] Error retrieving URL.", $result);
				if ($result["response"]["code"] != 200)  DisplayError("[DATA] Error retrieving URL.  Server returned:  " . $result["response"]["code"] . " " . $result["response"]["meaning"], $result);

				$rawsendsize += $result["rawsendsize"];
				$rawrecvsize += $result["rawrecvsize"];

				$info = @json_decode(@base64_decode(trim($result["body"])), true);
				if (!is_array($info))  DisplayError("[DATA] Error:  Response line from server is not valid.  Must be a base64 encoded JSON string.  Received:  " . $result["body"]);
				if (!isset($info["success"]))  DisplayError("[DATA] Error:  Response line from server is not valid.  Must contain 'success' status.");
				if (!$info["success"])  DisplayError("[DATA] Error:  Server returned a failure response.", $info);
				if (isset($nextdata["nextblock"]) && $nextdata["nextblock"] !== $info["nextblock"])  DisplayError("[DATA] Error:  Server returned a next block value (" . $info["nextblock"] . ") that doesn't match the expected local value (" . $nextdata["nextblock"] . ").", $info);

				echo "done.\n";

				$sentblocks = true;
			}

			// Move to next block.
			if (isset($nextdata["nextblock"]))  $startblock = $nextdata["nextblock"];
			$lastwrite = $nextdata["lastwrite"];
		} while (isset($nextdata["nextblock"]));

		// If nothing was sent to the server, cancel the backup request.
		if (!$sentblocks)  DisplayError("Nothing to update.");

		// Finalize the backup.
		echo "Finalizing backup...";
		$retries = $maxattempts;
		do
		{
			$result = false;
			$pos = strpos($baseurl, "?");
			$url = $baseurl . ($pos === false ? "?" : "&") . "ver=" . EFSS_VERSION . "&dir=push&wait=" . urlencode($waittime) . "&mode=finalize&id=" . $backupid . "&basesince=" . urlencode($initinfo["basesince"]) . "&incnum=" . urlencode($initinfo["incnum"]);
			$web = new WebBrowser();
			if (function_exists("BackupHook_ProcessPushURL"))  $result = BackupHook_ProcessPushURL("init");
			else  $result = $web->Process($url);

			if (!is_array($result) || !isset($result["success"]))  DisplayError("[FINAL] Error:  Invalid result from web request '" . $url . "'.");

			$retries--;
		} while ($retries && !$result["success"]);

		if (!$result["success"])  DisplayError("[FINAL] Error retrieving URL.", $result);
		if ($result["response"]["code"] != 200)  DisplayError("[FINAL] Error retrieving URL.  Server returned:  " . $result["response"]["code"] . " " . $result["response"]["meaning"], $result);

		$rawsendsize += $result["rawsendsize"];
		$rawrecvsize += $result["rawrecvsize"];

		$initinfo = @json_decode(@base64_decode(trim($result["body"])), true);
		if (!is_array($initinfo))  DisplayError("[FINAL] Error:  Response line from server is not valid.  Must be a base64 encoded JSON string.  Received:  " . $result["body"]);
		if (!isset($initinfo["success"]))  DisplayError("[FINAL] Error:  Response line from server is not valid.  Must contain 'success' status.");
		if (!$initinfo["success"])  DisplayError("[FINAL] Error:  Server returned a failure response.", $initinfo);

		echo "done.\n";
	}

	echo "\n";
	echo "Bytes sent:  " . number_format($rawsendsize, 0) . "\n";
	echo "Bytes received:  " . number_format($rawrecvsize, 0) . "\n";
	echo "Time taken:  " . number_format(microtime(true) - $startts, 2) . " sec\n";
	if (function_exists("memory_get_peak_usage"))  echo "Maximum RAM used:  " . number_format(memory_get_peak_usage(), 0) . "\n";

	echo "Done.\n";
?>