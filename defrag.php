<?php
	// Encrypted File Storage System command-line defragmenter
	// (C) 2014 CubicleSoft.  All Rights Reserved.

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
			"p" => "progress",
			"r" => "recursive",
			"?" => "help"
		),
		"rules" => array(
			"progress" => array("arg" => true),
			"recursive" => array("arg" => false),
			"help" => array("arg" => false)
		)
	);
	$args = CLI::ParseCommandLine($options);

	if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System command-line defragmenter\n";
		echo "Purpose:  Defragments one or more directories.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] BaseFilename Path\n";
		echo "Options:\n";
		echo "\t-p=num   Progress display frequency.  Default is 10.\n";
		echo "\t-r       Recursively defragment the directory tree.\n";
		echo "\t-?       This help documentation.\n";
		echo "\n";
		echo "Example:\n";
		echo "\tphp " . $args["file"] . " -r backup.dat /\n";

		exit();
	}

	$progressfrequecy = (double)(isset($args["opts"]["progress"]) ? $args["opts"]["progress"][0] : 10);

	$basefile = $args["params"][0];
	if (!file_exists($basefile . ".php") || !file_exists($basefile) || !file_exists($basefile . ".updates") || !file_exists($basefile . ".serial"))
	{
		echo "One or more of the base files do not exist.\n";

		exit();
	}

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

	require_once $basefile . ".php";

	if ($lockfile === false)  $lockfile = $basefile;

	$efss = new EFSS;
	$mode = EFSS_MODE_EXCL;
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $basefile, $mode, $lockfile, $blocksize, $incrementals);
	if (!$result["success"])  DisplayError("Error:  Unable to mount the file system.", $result, true);

	$path = $args["params"][1];
	$result = $efss->realpath($path);
	if (!$result["success"])
	{
		DisplayError("Unable to locate '" . $path . "'.", $result);

		return;
	}
	$path = $result["path"];

	function PathProcessedCallback($name, $fragments, $startts)
	{
		global $basets, $progressfrequecy;

		if ($basets === false)  $basets = $startts;
		if (microtime(true) - $basets > $progressfrequecy)
		{
			echo "\t" . $name . "\n";
			echo "\tFragments so far:  " . $fragments . "\n";

			$basets = microtime(true);
		}
	}

	$basets = false;
	$result = $efss->Defrag($path, isset($args["opts"]["recursive"]), "PathProcessedCallback");
	if (!$result["success"])
	{
		DisplayError("An error occurred while defragmenting '" . $path . "'.", $result);

		return;
	}

	echo "Total time:  " . EFSS::TimeElapsedToString($result["endts"] - $result["startts"]) . "\n";
	echo "Total fragments:  " . $result["fragments"] . "\n";

	echo "Done.\n";
?>