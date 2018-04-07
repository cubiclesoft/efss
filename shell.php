<?php
	// Encrypted File Storage System command-line shell
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
			"a" => "auto",
			"d" => "debug",
			"i" => "incrementals",
			"s" => "script",
			"?" => "help"
		),
		"rules" => array(
			"auto" => array("arg" => false),
			"debug" => array("arg" => false),
			"incrementals" => array("arg" => true),
			"script" => array("arg" => true),
			"help" => array("arg" => false)
		)
	);
	$args = ParseCommandLine($options);

	if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System command-line shell\n";
		echo "Purpose:  Manipulate EFSS data stores via a minimal shell.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] BaseFilename\n";
		echo "Options:\n";
		echo "\t-a        Input is automated (no prompts).\n";
		echo "\t-d        Log writes to a file.\n";
		echo "\t-i=num    Number of incrementals to mount.\n";
		echo "\t-s=file   The script file to execute.\n";
		echo "\t-?        This help documentation.\n";
		echo "\n";
		echo "Example:  php " . $args["file"] . " -i=10 /var/local/backup.dat\n";

		exit();
	}

	$basefile = $args["params"][0];
	if (!file_exists($basefile . ".php") || !file_exists($basefile) || !file_exists($basefile . ".updates") || !file_exists($basefile . ".serial"))
	{
		echo "One or more of the base files do not exist.\n";

		exit();
	}

	// Enable EFSS debugging, if specified.
	if (isset($args["opts"]["debug"]))  define("EFSS_DEBUG_LOG", $basefile . ".log.txt");

	function DisplayError($msg, $result = false, $exit = false)
	{
		echo $msg . "\n";

		if ($result !== false)
		{
			echo $result["error"] . " (" . $result["errorcode"] . ")\n";
			if (isset($result["info"]))  var_dump($result["info"]);
		}

		if ($exit)  exit();
	}

	require_once $basefile . ".php";

	$incrementals = array();
	$num = (int)(isset($args["opts"]["incrementals"]) ? $args["opts"]["incrementals"] : 0);
	$prefix = ($num < 0 ? ".r" : ".");
	$num = abs($num);
	for ($x = 1; $x <= $num; $x++)  $incrementals[] = $basefile . $prefix . $x;

	$efss = new EFSS;
	$mode = (count($incrementals) || file_exists($basefile . ".readonly") ? EFSS_MODE_READ : EFSS_MODE_EXCL);
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $basefile, $mode, $lockfile, $blocksize, $incrementals);
	if (!$result["success"])  DisplayError("Error:  Unable to mount the file system.", $result, true);

	$auto = isset($args["opts"]["auto"]);
	$script = isset($args["opts"]["script"]);
	if ($script)  $fp = fopen($args["opts"]["script"], "rb");
	else  $fp = STDIN;

	// Load all shell functions.
	$dir = opendir($rootpath . "/shell_exts");
	if ($dir !== false)
	{
		while (($file = readdir($dir)) !== false)
		{
			if (substr($file, -4) === ".php")  require_once $rootpath . "/shell_exts/" . $file;
		}

		closedir($dir);
	}

	// Enter the main loop.
	if (!$auto)
	{
		echo "Welcome to the Encrypted File Storage System command-line shell.\n";
		echo "File system mounted in " . ($mode === EFSS_MODE_EXCL ? "exclusive (read/write)" : "read only") . " mode.\n\n";
		echo "efss://" . $basefile . "//" . (count($incrementals) ? " [" . count($incrementals) . "]" : "") . ">";
	}
	while (($line = fgets($fp)) !== false)
	{
		$line = trim($line);
		if ($script)  echo $line . "\n";

		if ($line == "quit" || $line == "exit" || $line == "logout")  break;

		// Parse the command.
		$pos = strpos($line, " ");
		if ($pos === false)  $pos = strlen($line);
		$cmd = substr($line, 0, $pos);

		if ($cmd != "")
		{
			if (!function_exists("shell_cmd_" . $cmd))  echo "The shell command '" . $cmd . "' does not exist.\n";
			else
			{
				$cmd = "shell_cmd_" . $cmd;
				$cmd($line);
			}
		}

		if (!$auto)
		{
			$result = $efss->getcwd();
			$cwd = ($result["success"] ? $result["cwd"] : "/");
			echo "efss://" . $basefile . "/" . $cwd . (count($incrementals) ? " [" . count($incrementals) . "]" : "") . ">";
		}
	}

	if ($script)  fclose($fp);
?>