<?php
	// Encrypted File Storage System command-line creator
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
			"b" => "blocksize",
			"d" => "debug",
			"l" => "lockfile",
			"t" => "timestamp",
			"m" => "dirmode",
			"r" => "rootseed",
			"?" => "help"
		),
		"rules" => array(
			"blocksize" => array("arg" => true),
			"debug" => array("arg" => false),
			"lockfile" => array("arg" => true),
			"timestamp" => array("arg" => true),
			"dirmode" => array("arg" => true),
			"rootseed" => array("arg" => true),
			"help" => array("arg" => false)
		)
	);
	$args = ParseCommandLine($options);

	if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System command-line creation tool\n";
		echo "Purpose:  Create new EFSS data stores.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] BaseFilename\n";
		echo "Options:\n";
		echo "\t-b=blocksize       Block size of new file system.\n";
		echo "\t-d                 Log writes to a file.\n";
		echo "\t-l=lockfile        Lock file of the new file system.\n";
		echo "\t-t=timestampmode   Timestamp mode of the new file system.\n";
		echo "\t-d=dirmode         Directory mode of the new file system.\n";
		echo "\t-?                 This help documentation.\n";

		exit();
	}

	// Make sure the files don't exist already.
	$basefile = $args["params"][0];
	if (file_exists($basefile . ".php") || file_exists($basefile) || file_exists($basefile . ".updates") || file_exists($basefile . ".serial") || file_exists($basefile . ".blocknums") || file_exists($basefile . ".readonly"))
	{
		echo "One or more of the base files already exist.\n";
		echo "If you wish to replace an existing system, delete the old files first.\n";

		exit();
	}

	// Enable EFSS debugging, if specified.
	if (isset($args["opts"]["debug"]))  define("EFSS_DEBUG_LOG", $basefile . ".log.txt");

	// Get basic options.
	$blocksize = (int)(isset($args["opts"]["blocksize"]) ? $args["opts"]["blocksize"] : 4096);
	if ($blocksize < 4096)  $blocksize = 4096;
	if ($blocksize > 32768)  $blocksize = 32768;
	if ($blocksize % 4096 != 0)  $blocksize -= ($blocksize % 4096);

	$lockfile = (isset($args["opts"]["lockfile"]) ? $args["opts"]["lockfile"] : false);

	$timestamp = (isset($args["opts"]["timestamp"]) && strtolower($args["opts"]["timestamp"]) == "unix" ? EFSS_TIMESTAMP_UNIX : EFSS_TIMESTAMP_UTC);

	$dirmode = (int)(isset($args["opts"]["dirmode"]) ? $args["opts"]["dirmode"] : EFSS_DIRMODE_DEFAULT);
	$dirmode = $dirmode & 0x03;

	// Generate a seed, keys, and IVs.
	echo "Generating base encryption keys (this will take a few seconds)...\n";
	$rng = new CSPRNG(true);
	$key1base = bin2hex($rng->GetBytes(32));
	$iv1base = bin2hex($rng->GetBytes(16));
	$key2base = bin2hex($rng->GetBytes(32));
	$iv2base = bin2hex($rng->GetBytes(16));

	// Generate the PHP file to store the information.
	EFSSIncremental::WritePHPFile($basefile, $key1base, $iv1base, $key2base, $iv2base, $blocksize, $lockfile);

	// Create the file system.
	require_once $basefile . ".php";

	$efss = new EFSS;
	$result = $efss->Create($key1, $iv1, $key2, $iv2, $basefile, $lockfile, $blocksize, $timestamp, $dirmode);
	if (!$result["success"])
	{
		echo "Error:  Unable to create the file system.\n";
		echo $result["error"] . " (" . $result["errorcode"] . ")\n";
		if (isset($result["info"]))  var_dump($result["info"]);

		exit();
	}

	echo "Successfully created the file system.\n";
?>