<?php
	// Encrypted File Storage System command-line health check
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

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"h" => "hashesonly",
			"i" => "incrementals",
			"m" => "minimize",
			"p" => "progress",
			"s" => "stoponerror",
			"v" => "verifyonly",
			"?" => "help"
		),
		"rules" => array(
			"hashesonly" => array("arg" => false),
			"incrementals" => array("arg" => true),
			"minimize" => array("arg" => false),
			"progress" => array("arg" => true),
			"stoponerror" => array("arg" => false),
			"verifyonly" => array("arg" => false),
			"help" => array("arg" => false)
		)
	);
	$args = CLI::ParseCommandLine($options);

	if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System command-line health check\n";
		echo "Purpose:  Checks the health of an EFSS data store.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] BaseFilename\n";
		echo "Options:\n";
		echo "\t-h       Check hashes only, don't decrypt, don't mount.\n";
		echo "\t-i=num   Number of incrementals to test and mount.\n";
		echo "\t-m       Minimize processing.  Skip intermediate checks.\n";
		echo "\t-p=num   Progress display frequency.  Default is 10.\n";
		echo "\t-s       Stop processing on the first error.\n";
		echo "\t-v       Verify only, don't mount.\n";
		echo "\t-?       This help documentation.\n";
		echo "\n";
		echo "Example:\n";
		echo "\tphp " . $args["file"] . " backup.dat\n";

		exit();
	}

	$minimize = isset($args["opts"]["minimize"]);
	$stoponerror = isset($args["opts"]["stoponerror"]);
	$verifyonly = isset($args["opts"]["verifyonly"]);
	$progressfrequecy = (double)(isset($args["opts"]["progress"]) ? $args["opts"]["progress"][0] : 10);

	$basefile = $args["params"][0];
	if (!file_exists($basefile . ".php") || !file_exists($basefile) || !file_exists($basefile . ".updates") || !file_exists($basefile . ".serial"))
	{
		echo "One or more of the base files do not exist.\n";

		exit();
	}

	function DisplayError($msg, $result = false)
	{
		global $stoponerror;

		echo $msg . "\n";

		if ($result !== false)
		{
			echo $result["error"] . " (" . $result["errorcode"] . ")\n";
			if (isset($result["info"]))  var_dump($result["info"]);
		}

		if ($stoponerror)  exit();
	}

	require_once $basefile . ".php";

	if (isset($args["opts"]["hashesonly"]))  $key1 = "";

	if ($lockfile === false)  $lockfile = $basefile;

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

	function FileProcessedCallback($name, $startts)
	{
		global $basets, $progressfrequecy;

		if ($basets === false)  $basets = $startts;
		if (microtime(true) - $basets > $progressfrequecy)
		{
			echo "\t" . $name . "\n";

			$basets = microtime(true);
		}
	}

	function VerifyAndCheck($filename, $x, $num)
	{
		global $verbose, $key1, $iv1, $key2, $iv2, $blocksize, $lockfile, $basefile, $incrementals, $verifyonly, $basets, $minimize;

		// Lock the file.
		echo "Locking '" . $filename . "'...";
		$result = EFSSIncremental::GetLock($lockfile, false);
		if (!$result["success"])  DisplayError("Error:  Unable to obtain lock.", $result);
		else  echo "done.\n\n";
		$readlock = $result["lock"];

		// Verify the file.
		echo "Verifying '" . $filename . "'...\n";
		$basets = false;
		$result = EFSSIncremental::Verify(($minimize ? "" : $key1), $iv1, $key2, $iv2, $filename, $blocksize, "BlocksProcessedCallback");
		if (!$result["success"])  DisplayError("Error:  Unable to verify incremental data.", $result);
		else
		{
			DisplayProgress($result["blocks"], $result["blocks"], $result["startts"]);
			echo "\tTime taken:  " . number_format($result["endts"] - $result["startts"], 2) . " sec\n\n";
		}
		unset($readlock);

		// Mount and check the file.
		if ($key1 != "" && !$verifyonly && (!$minimize || $x == $num))
		{
			$efss = new EFSS;
			$mode = (count($incrementals) || file_exists($basefile . ".readonly") ? EFSS_MODE_READ : EFSS_MODE_EXCL);
			$result = $efss->Mount($key1, $iv1, $key2, $iv2, $basefile, $mode, $lockfile, $blocksize, $incrementals);
			if (!$result["success"])  DisplayError("Error:  Unable to mount the file system.", $result);
			else
			{
				echo "Checking '" . $basefile . "' plus " . count($incrementals) . " incrementals...\n";
				$basets = false;
				$result = $efss->CheckFS("BlocksProcessedCallback", "FileProcessedCallback");
				if (!$result["success"])  DisplayError("Error:  Unable to verify the file system.", $result);
				else  echo "\tTime taken:  " . number_format($result["endts"] - $result["startts"], 2) . " sec\n\n";

				$result = $efss->Unmount();
				if (!$result["success"])  DisplayError("Error:  Unable to unmount the file system.", $result);
			}

			unset($efss);
		}
	}

	$startts = microtime(true);

	$num = (int)(isset($args["opts"]["incrementals"]) ? $args["opts"]["incrementals"] : 0);
	$prefix = ($num < 0 ? ".r" : ".");
	$num = abs($num);

	$incrementals = array();
	VerifyAndCheck($basefile, 0, $num);

	for ($x = 1; $x <= $num; $x++)
	{
		$incrementals[] = $basefile . $prefix . $x;
		VerifyAndCheck($basefile . $prefix . $x, $x, $num);
	}

	echo "Time taken:  " . number_format(microtime(true) - $startts, 2) . " sec\n";
	if (function_exists("memory_get_peak_usage"))  echo "Maximum RAM used:  " . number_format(memory_get_peak_usage(), 0) . "\n";

	echo "Done.\n";
?>