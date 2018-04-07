<?php
	// Encrypted File Storage System backup API
	// (C) 2013 CubicleSoft.  All Rights Reserved.

	require_once "config.php";

	require_once "../support/efss.php";

	function OutputResponse($result)
	{
		echo base64_encode(json_encode($result)) . "\n";

		exit();
	}

	function DisplayError($msg, $code)
	{
		$result = array(
			"success" => false,
			"error" => $msg,
			"errorcode" => $code
		);

		OutputResponse($result);
	}

	// Swiped from Barebones CMS.
	function BB_IsSSLRequest()
	{
		return ((isset($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on" || $_SERVER["HTTPS"] == "1")) || (isset($_SERVER["SERVER_PORT"]) && $_SERVER["SERVER_PORT"] == "443") || (str_replace("\\", "/", strtolower(substr($_SERVER["REQUEST_URI"], 0, 8))) == "https://"));
	}

	if ($sslonly && !BB_IsSSLRequest())  DisplayError("This API only allows HTTPS.", "ssl_only");

	if (!isset($_REQUEST["token"]) || !isset($_REQUEST["ver"]) || !isset($_REQUEST["dir"]))  DisplayError("Required information not specified.", "missing_info");
	if ($_REQUEST["token"] !== $token)  DisplayError("Invalid token.", "invalid_token");
	if ((int)$_REQUEST["ver"] !== EFSS_VERSION)  DisplayError("EFSS version mismatch.", "invalid_version");
	if ($_REQUEST["dir"] === "pull")
	{
		if ($dirtype !== "both" && $dirtype !== "pull")  DisplayError("Pull is not allowed.", "no_pull");
		if (!isset($_REQUEST["wait"]) || !isset($_REQUEST["since"]) || !isset($_REQUEST["startblock"]) || !isset($_REQUEST["lastwrite"]))  DisplayError("Required pull information not specified.", "missing_info");
		if (strlen($_REQUEST["since"]) != 20 || !preg_match('/\d{4,}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s?/', $_REQUEST["since"]))  DisplayError("'since' must be a 20 character timestamp.", "invalid_since_timestamp");
		if ((int)$_REQUEST["startblock"] < 0)  DisplayError("'startblock' must be a non-negative integer.", "invalid_startblock");
		if ((int)$_REQUEST["lastwrite"] < 0)  DisplayError("'lastwrite' must be a non-negative integer.", "invalid_lastwrite");

		$result = EFSSIncremental::GetLock(($lockfile === false ? $filename : $lockfile), false, (int)$_REQUEST["wait"], $maxtime);
		if (!$result["success"])  OutputResponse($result);
		$readlock = $result["lock"];
		unset($result);

		// Retrieve incremental data.
		$result = EFSSIncremental::Read($filename, $_REQUEST["since"], (int)$_REQUEST["startblock"], (int)$_REQUEST["lastwrite"], $maxtime, $blocksize);
		if (!$result["success"])  OutputResponse($result);

		// Build the first line of the response in a backup.php valid format.
		$response = array(
			"success" => true,
			"lastwrite" => $result["lastwrite"],
			"blocksize" => $result["blocksize"],
			"numblocks" => $result["numblocks"],
			"md5" => $result["md5"],
			"sha1" => $result["sha1"]
		);
		if (isset($result["nextblock"]))  $response["nextblock"] = $result["nextblock"];
		if (isset($result["serial"]))  $response["serial"] = $result["serial"];

		echo base64_encode(json_encode($response)) . "\n";

		// Output binary data.
		echo $result["blocks"];
		echo $result["updates"];
		if (isset($result["blocknums"]))  echo $result["blocknums"];
	}
	else if ($_REQUEST["dir"] === "push")
	{
		if ($dirtype !== "both" && $dirtype !== "push")  DisplayError("Push is not allowed.", "no_push");
		if (!isset($_REQUEST["mode"]))  DisplayError("Push mode not specified.", "no_push_mode");
		if (!isset($_REQUEST["wait"]))  DisplayError("Required push information not specified.", "missing_info");

		$result = EFSSIncremental::GetLock(($lockfile === false ? $filename : $lockfile), true, (int)$_REQUEST["wait"], $maxtime);
		if (!$result["success"])  OutputResponse($result);
		$writelock = $result["lock"];
		unset($result);

		// Check for a merge in progress.
		if (file_exists($filename) && file_exists($filename . ".partial"))  DisplayError("Merge in progress.  Try again later.", "active_push_merge");

		if ($_REQUEST["mode"] === "init")
		{
			// Retrieve 'since'.
			$since = "0000-00-00 00:00:00 ";
			$basesince = $since;
			$newfilename = $filename;
			if (file_exists($newfilename) && file_exists($newfilename . ".updates") && file_exists($newfilename . ".serial"))
			{
				$result = EFSSIncremental::LastUpdated($newfilename);
				if (!$result["success"])  OutputResponse($result);
				$since = $result["lastupdate"];
				$basesince = $since;

				$incnum = 0;
				$newfilename = $filename . "." . $incnum;
				while (file_exists($newfilename) && file_exists($newfilename . ".updates") && file_exists($newfilename . ".serial") && file_exists($newfilename . ".blocknums"))
				{
					$result = EFSSIncremental::LastUpdated($newfilename);
					if (!$result["success"])  OutputResponse($result);
					$since = $result["lastupdate"];

					$incnum++;
					$newfilename = $filename . "." . $incnum;
				}
			}

			// Find an open filename.
			$id = 1;
			while (file_exists($filename . ".temp" . $id . ".init"))  $id++;
			file_put_contents($filename . ".temp" . $id . ".init", $basesince);
			if (!file_exists($filename . ".serial"))  $serial = "";
			else
			{
				$serial = file_get_contents($filename . ".serial");
				file_put_contents($filename . ".temp" . $id . ".serial", $serial);
			}

			// Send response.
			$response = array(
				"success" => true,
				"blocksize" => $blocksize,
				"serial" => $serial,
				"id" => $id,
				"basesince" => $basesince,
				"since" => $since,
				"incnum" => $incnum
			);

			OutputResponse($response);
		}
		else if ($_REQUEST["mode"] === "data")
		{
			if (!isset($_REQUEST["id"]) || !isset($_REQUEST["basesince"]) || !isset($_REQUEST["startblock"]) || !isset($_REQUEST["numblocks"]) || !isset($_REQUEST["md5"]) || !isset($_REQUEST["sha1"]))  DisplayError("Required push information not specified.", "missing_info");
			$id = (int)$_REQUEST["id"];
			if (!file_exists($filename . ".temp" . $id . ".init"))  DisplayError("The specified ID is not valid.", "invalid_id");
			if ($_REQUEST["basesince"] === "0000-00-00 00:00:00 " && file_exists($filename) && file_exists($filename . ".updates") && file_exists($filename . ".serial"))  DisplayError("Merge between pushes detected.", "merge_detected");
			if ($_REQUEST["basesince"] !== "0000-00-00 00:00:00 ")
			{
				if (!file_exists($filename) || !file_exists($filename . ".updates") || !file_exists($filename . ".serial"))  DisplayError("Merge between pushes detected.", "merge_detected");

				$result = EFSSIncremental::LastUpdated($filename);
				if (!$result["success"])  OutputResponse($result);

				if ($result["lastupdate"] !== file_get_contents($filename . ".temp" . $id . ".init") || $result["lastupdate"] !== $_REQUEST["basesince"])  DisplayError("Merge between pushes detected.", "merge_detected");
			}

			// Extract the data.
			$data = @file_get_contents("php://input");
			if ($data === false)  DisplayError("The body of the request is not able to be retrieved.", "no_input");
			$pos = strpos($data, "\n");
			if ($pos === false)  DisplayError("The body of the request is not valid.", "invalid_input");
			$serial = trim(substr($data, 0, $pos));
			$data = substr($data, $pos + 1);

			// Verify that the math for the rest of the data works out.
			$numblocks = (int)$_REQUEST["numblocks"];
			$size = $blocksize * $numblocks + 20 * $numblocks;
			if ($_REQUEST["basesince"] !== "0000-00-00 00:00:00 ")  $size += 4 * $numblocks;
			if ($size != strlen($data))  DisplayError("The body of the request is not the correct length.  Expected " . $size . " but received " . strlen($data) . ".", "invalid_input_size");

			// Write the data.
			$result = EFSSIncremental::Write($filename . ".temp" . $id, (int)$_REQUEST["startblock"], substr($data, 0, $blocksize * $numblocks), substr($data, $blocksize * $numblocks, 20 * $numblocks), $_REQUEST["md5"], $_REQUEST["sha1"], ($_REQUEST["basesince"] !== "0000-00-00 00:00:00 " ? substr($data, $blocksize * $numblocks + 20 * $numblocks) : false), ($serial !== "" ? $serial : false), $blocksize);

			OutputResponse($result);
		}
		else if ($_REQUEST["mode"] === "finalize")
		{
			if (!isset($_REQUEST["id"]) || !isset($_REQUEST["basesince"]) || !isset($_REQUEST["incnum"]))  DisplayError("Required push information not specified.", "missing_info");
			$id = (int)$_REQUEST["id"];
			if (!file_exists($filename . ".temp" . $id . ".init"))  DisplayError("The specified ID is not valid.", "invalid_id");
			if (!file_exists($filename . ".temp" . $id . ".serial"))  DisplayError("The serial number was not sent.", "no_serial");
			if ($_REQUEST["basesince"] === "0000-00-00 00:00:00 " && file_exists($filename) && file_exists($filename . ".updates") && file_exists($filename . ".serial"))  DisplayError("Merge between pushes detected.", "merge_detected");
			if ($_REQUEST["basesince"] !== "0000-00-00 00:00:00 ")
			{
				if (!file_exists($filename) || !file_exists($filename . ".updates") || !file_exists($filename . ".serial"))  DisplayError("Merge between pushes detected.", "merge_detected");

				$result = EFSSIncremental::LastUpdated($filename);
				if (!$result["success"])  OutputResponse($result);

				if ($result["lastupdate"] !== file_get_contents($filename . ".temp" . $id . ".init"))  DisplayError("Merge between pushes detected.", "merge_detected");
			}

			// Finalize the incremental.
			$result = EFSSIncremental::WriteFinalize($filename . ".temp" . $id);
			if (!$result["success"])  OutputResponse($result);

			// Compare the serials (except for the base file).
			$newfilename = $filename . ((int)$_REQUEST["incnum"] > 0 ? "." . (int)$_REQUEST["incnum"] : "");
			$serial = file_get_contents($filename . ".temp" . $id . ".serial");
			if (file_exists($filename) && file_exists($filename . ".updates") && file_exists($filename . ".serial"))
			{
				if ($serial !== file_get_contents($filename . ".serial"))  DisplayError("The serial numbers do not match.", "serial_mismatch");
			}

			// Move the incremental to the final location.
			@rename($filename . ".temp" . $id, $newfilename);
			@rename($filename . ".temp" . $id . ".updates", $newfilename . ".updates");
			@rename($filename . ".temp" . $id . ".serial", $newfilename . ".serial");
			@rename($filename . ".temp" . $id . ".blocknums", $newfilename . ".blocknums");

			// Remove any leftover garbage.
			EFSSIncremental::Delete($filename . ".temp" . $id);
			@unlink($filename . ".temp" . $id . ".init");

			// Send response.
			$response = array("success" => true);

			OutputResponse($response);
		}
		else if ($_REQUEST["mode"] === "cleanup")
		{
			if (!isset($_REQUEST["id"]))  DisplayError("Required push information not specified.", "missing_info");
			$id = (int)$_REQUEST["id"];
			if (!file_exists($filename . ".temp" . $id . ".init"))  DisplayError("The specified ID is not valid.", "invalid_id");

			// Remove the leftovers from init.
			EFSSIncremental::Delete($filename . ".temp" . $id);
			@unlink($filename . ".temp" . $id . ".init");

			// Send response.
			$response = array("success" => true);

			OutputResponse($response);
		}
		else
		{
			DisplayError("Unknown push 'mode' specified.", "invalid_mode");
		}
	}
	else
	{
		DisplayError("Unknown 'dir' type specified.", "invalid_dir");
	}
?>