<?php
	// Encrypted File Storage System command-line reverse diff manager.
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
			"?" => "help"
		),
		"rules" => array(
			"help" => array("arg" => false)
		)
	);
	$args = ParseCommandLine($options);

	if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System command-line reverse diff manager\n";
		echo "Purpose:  Manages reverse diff incrementals of an EFSS data store.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] NumIncrementals BaseFilename\n";
		echo "Options:\n";
		echo "\t-?       This help documentation.\n";
		echo "\n";
		echo "Example:\n";
		echo "\tphp " . $args["file"] . " 7 backup.dat\n";

		exit();
	}

	$num = abs((int)$args["params"][0]);
	$basefile = $args["params"][1];
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

	if ($lockfile === false)  $lockfile = $basefile;

	$result = EFSSIncremental::GetLock($lockfile, true);
	if (!$result["success"])  DisplayError("Error:  Unable to obtain lock.", $result);
	$writelock = $result["lock"];
	unset($result);

	if ($num > 0)
	{
		if (!file_exists($basefile . ".rdiff") || !file_exists($basefile . ".rdiff.updates"))
		{
			// Initialize.
			file_put_contents($basefile . ".rdiff", "");
			file_put_contents($basefile . ".rdiff.updates", "");
			@unlink($basefile . ".rdiff.hashes");
			@unlink($basefile . ".rdiff.blockmap");
			@unlink($basefile . ".rdiff.blockinfo");
		}
		else
		{
			$x = $num + 1;
			do
			{
				if (!file_exists($basefile . ".r" . $x))  break;

				EFSSIncremental::Delete($basefile . ".r" . $x);

				$x++;
			} while (1);

			if (file_exists($basefile . ".rdiff.blockinfo"))
			{
				$data = explode("|", trim(file_get_contents($basefile . ".rdiff.blockinfo")));
				if ((int)$data[0] > 0)
				{
					EFSSIncremental::Delete($basefile . ".r" . $num);

					while ($num > 1)
					{
						@rename($basefile . ".r" . ($num - 1), $basefile . ".r" . $num);
						@rename($basefile . ".r" . ($num - 1) . ".updates", $basefile . ".r" . $num . ".updates");
						@rename($basefile . ".r" . ($num - 1) . ".hashes", $basefile . ".r" . $num . ".hashes");
						@rename($basefile . ".r" . ($num - 1) . ".blocknums", $basefile . ".r" . $num . ".blocknums");
						@rename($basefile . ".r" . ($num - 1) . ".serial", $basefile . ".r" . $num . ".serial");

						$num--;
					}

					$result = EFSSIncremental::MakeReverseDiffIncremental($basefile, $basefile . ".r1", $blocksize);
					if (!$result["success"])  DisplayError("Error:  Unable to make reverse diff incremental.", $result);
				}
			}
		}
	}
	else
	{
		// Remove all reverse diffs.
		@unlink($basefile . ".rdiff");
		@unlink($basefile . ".rdiff.updates");
		@unlink($basefile . ".rdiff.hashes");
		@unlink($basefile . ".rdiff.blockmap");
		@unlink($basefile . ".rdiff.blockinfo");

		$x = 1;
		do
		{
			if (!file_exists($basefile . ".r" . $x))  break;

			EFSSIncremental::Delete($basefile . ".r" . $x);

			$x++;
		} while (1);
	}

	unset($writelock);
?>