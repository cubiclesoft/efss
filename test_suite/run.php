<?php
	// Encrypted File Storage System test suite
	// (C) 2013 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/../support/cli.php";
	require_once $rootpath . "/../support/efss.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"c" => "cleanonly",
			"o" => "optional",
			"p" => "php",
			"?" => "help"
		),
		"rules" => array(
			"cleanonly" => array("arg" => false),
			"optional" => array("arg" => true),
			"php" => array("arg" => true),
			"help" => array("arg" => false)
		)
	);
	$args = ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Encrypted File Storage System test suite\n";
		echo "Purpose:  Runs the EFSS test suite.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options]\n";
		echo "Options:\n";
		echo "\t-c       Cleans up the test data files and exits.\n";
		echo "\t-o=url   Run optional tests.  URL is the example backup API.\n";
		echo "\t-p=php   PHP executable to use to run optional tests.\n";
		echo "\t-?       This help documentation.\n";

		exit();
	}

	ini_set("error_reporting", E_ALL);

	// DO NOT use the keys and IVs here for your storage system.  Running 'create.php' will generate the necessary information.
	$key1 = pack("H*", "13c21eb0d95f5bcb49c1aef598efddc8eac7295092ffbd99c6c1f28d0b0dc9e3");
	$iv1 = pack("H*", "3d38ae9c7cf6bcb449cbf9c368103ec6");
	$key2 = pack("H*", "2e444c2eceafe40cbdf564ad48f6ca99dbb23d43f7b630d89646a48ef0d67a02");
	$iv2 = pack("H*", "95dfd6e8b753c594c52d95fef5cd4511");

	// Make sure that all write operations are logged.
	define("EFSS_DEBUG_LOG", $rootpath . "/test_log.txt");

	// First, clean up any previous output.
	EFSSIncremental::ForceUnlock($rootpath . "/testing.dat");
	EFSSIncremental::Delete($rootpath . "/testing.dat");

	EFSSIncremental::ForceUnlock($rootpath . "/testing.dat.r1");
	EFSSIncremental::Delete($rootpath . "/testing.dat.r1");

	EFSSIncremental::ForceUnlock($rootpath . "/testing.bak");
	EFSSIncremental::Delete($rootpath . "/testing.bak");

	EFSSIncremental::ForceUnlock($rootpath . "/testing.bak.1");
	EFSSIncremental::Delete($rootpath . "/testing.bak.1");
	@unlink($rootpath . "/testing.bak.1.partial");

	EFSSIncremental::ForceUnlock($rootpath . "/testing2.dat");
	EFSSIncremental::Delete($rootpath . "/testing2.dat");

	EFSSIncremental::ForceUnlock($rootpath . "/testing3.dat");
	EFSSIncremental::Delete($rootpath . "/testing3.dat");

	EFSSIncremental::ForceUnlock($rootpath . "/testing4.dat");
	EFSSIncremental::Delete($rootpath . "/testing4.dat");

	@unlink($rootpath . "/donate.txt");
	@unlink($rootpath . "/test_log.txt");

	if (isset($args["opts"]["cleanonly"]))
	{
		echo "Removed the test data files.\n";

		exit();
	}

	$passed = 0;
	$failed = 0;
	$skipped = 0;

	function ProcessResult($test, $result, $bail_on_error = true)
	{
		global $passed, $failed;

		if (is_bool($result))  $str = ($result ? "[PASS]" : "[FAIL]") . " " . $test;
		else
		{
			$str = ($result["success"] ? "[PASS]" : "[FAIL - " . $result["error"] . " (" . $result["errorcode"] . ")]");
			$str .= " " . $test;
			if (!$result["success"])  $str .= "\n" . var_export($result, true) . "\n";
		}

		if (substr($str, 0, 2) == "[P")  $passed++;
		else
		{
			if ($bail_on_error)  echo "\n";
			$failed++;
		}
		echo $str . "\n";

		if ($bail_on_error && substr($str, 0, 2) == "[F")
		{
			echo "\n[FATAL] Unable to complete test suite.  Copy the failure data above when opening an issue.\n";
			exit();
		}
	}

	// Create the file system.
	$efss = new EFSS;
	$result = $efss->Create($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat");
	ProcessResult("Create 'testing.dat'", $result);

	// Mount the file system for reading.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat", EFSS_MODE_READ);
	ProcessResult("Mount 'testing.dat' for reading", $result);

	// Unmount the file system.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.dat'", $result);

	// Mount the file system for writing.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat", EFSS_MODE_EXCL);
	ProcessResult("Mount 'testing.dat' for writing", $result);

	// Test CheckFS().
	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	$efss->SetDefaultOwner("root");
	$efss->SetDefaultGroup("admin");

	// Get the current working directory.
	$result = $efss->getcwd();
	ProcessResult("getcwd()", $result);
	ProcessResult("getcwd() is '/'", $result["cwd"] == "/", false);

	// Open the root directory for reading.
	$result = $efss->opendir("/");
	ProcessResult("opendir(\"/\")", $result);
	$dir = $result["dir"];

	// Attempt to read a directory entry.
	$result = $efss->readdir($dir);
	ProcessResult("readdir()", (!$result["success"] && $result["errorcode"] == "dir_end"));

	// Close the directory entry.
	$efss->closedir($dir);
	ProcessResult("closedir()", true);

	// Load test file and test compression.
	$testdata = file_get_contents($rootpath . "/test_1.txt");
	if (!DeflateStream::IsSupported())
	{
		echo "[SKIPPED] Deflate compression/decompression is not available or not functioning correctly.\n";
		$skipped += 4;
	}
	else
	{
		$data = DeflateStream::Compress($testdata);
		ProcessResult("DeflateStream::Compress() - Error test", ($data !== false));
		ProcessResult("DeflateStream::Compress() - Data test", ($data !== ""));
		$data = DeflateStream::Uncompress($data);
		ProcessResult("DeflateStream::Uncompress()", ($data !== false));
		ProcessResult("Test file and decompressed data equality test", array("success" => ($data === $testdata), "error" => "Test data does not match uncompressed data.", "errorcode" => "data_mismatch", "info" => "'" . $data . "' found, expected:  '" . $testdata . "'."));
	}

	// Copy the test file to the root.
	$result = $efss->fopen("test.txt", "wb");
	ProcessResult("fopen(\"test.txt\")", $result);
	$fp = $result["fp"];

	// Test fwrite().
	$result = $efss->fwrite($fp, $testdata);
	ProcessResult("fwrite() - Contents of 'test_1.txt'", $result);

	// Test fclose().
	$result = $efss->fclose($fp);
	ProcessResult("fclose()", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Open the root directory for reading.
	$result = $efss->opendir("/");
	ProcessResult("opendir(\"/\")", $result);
	$dir = $result["dir"];

	// Read a directory entry.
	$result = $efss->readdir($dir);
	ProcessResult("readdir()", $result);
	ProcessResult("readdir() should be 'test.txt'", $result["name"] == "test.txt");

	// Test rewinddir().
	$result = $efss->rewinddir($dir);
	ProcessResult("rewinddir()", $result);

	$result = $efss->readdir($dir);
	ProcessResult("readdir()", $result);
	ProcessResult("readdir() should be 'test.txt'", $result["name"] == "test.txt");

	// Close the directory entry.
	$efss->closedir($dir);
	ProcessResult("closedir()", true);

	// Compare file size of test file to original data.
	$result = $efss->filesize("test.txt");
	ProcessResult("filesize(\"test.txt\")", $result);
	ProcessResult("Test file size and data size equality test", $result["fullsize"] === strlen($testdata));

	// Read the test file and compare it to the original data.
	$result = $efss->fopen("test.txt", "rb");
	ProcessResult("fopen(\"test.txt\")", $result);
	$fp = $result["fp"];

	// Test fread().
	$result = $efss->fread($fp, 1024);
	ProcessResult("fread(1024)", $result);
	ProcessResult("Test file and data read equality test", $result["data"] === $testdata);

	// Test rewind().
	$result = $efss->rewind($fp);
	ProcessResult("rewind()", $result);

	$result = $efss->fread($fp, 1024);
	ProcessResult("fread(1024)", $result);
	ProcessResult("Test file and data read equality test", $result["data"] === $testdata);

	// Test ftell().
	$result = $efss->ftell($fp);
	ProcessResult("ftell()", $result);
	$pos = $result["pos"];

	// Test fseek().  In general, this function has poor performance.
	$result = $efss->fseek($fp, $pos - 6, SEEK_SET);
	ProcessResult("fseek(" . $pos . " - 6, SEEK_SET)", $result);

	$result = $efss->fread($fp, 1024);
	ProcessResult("fread(1024)", $result);
	ProcessResult("Test file and data read equality test", substr($result["data"], -6) === substr($testdata, -6));

	$result = $efss->fseek($fp, -6, SEEK_CUR);
	ProcessResult("fseek(-6, SEEK_CUR)", $result);

	$result = $efss->fread($fp, 1024);
	ProcessResult("fread(1024)", $result);
	ProcessResult("Test file and data read equality test", substr($result["data"], -6) === substr($testdata, -6));

	$result = $efss->fseek($fp, 6, SEEK_END);
	ProcessResult("fseek(-6, SEEK_END)", $result);

	$result = $efss->fread($fp, 1024);
	ProcessResult("fread(1024)", $result);
	ProcessResult("Test file and data read equality test", substr($result["data"], -6) === substr($testdata, -6));

	// Test feof().
	$result = $efss->feof($fp);
	ProcessResult("feof()", $result);
	ProcessResult("feof() - Not EOF", !$result["eof"]);
	$result = $efss->fread($fp, 1024);
	ProcessResult("fread(1024)", $result);
	$result = $efss->feof($fp);
	ProcessResult("feof() - Is EOF", $result["eof"]);
	$result = $efss->rewind($fp);
	ProcessResult("rewind()", $result);
	$result = $efss->feof($fp);
	ProcessResult("feof()", $result);
	ProcessResult("feof() - Not EOF", !$result["eof"]);

	// Test fgetc().
	$result = $efss->fgetc($fp);
	ProcessResult("fgetc()", $result);
	ProcessResult("Test file and data read equality test", $result["data"] === substr($testdata, 0, 1));

	$result = $efss->fgetc($fp);
	ProcessResult("fgetc()", $result);
	ProcessResult("Test file and data read equality test", $result["data"] === substr($testdata, 1, 1));

	// Test fgets().
	$result = $efss->rewind($fp);
	ProcessResult("rewind()", $result);

	$result = $efss->fgets($fp);
	ProcessResult("fgets()", $result);
	ProcessResult("Test file and data read equality test", (strlen($result["data"]) > 0 && $result["data"] === substr($testdata, 0, strlen($result["data"]))));

	$result = $efss->fclose($fp);
	ProcessResult("fclose()", $result);

	// Unmount.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.dat'", $result);

	// Mount the file system for writing with reverse-diff support.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat", EFSS_MODE_EXCL, false, 4096, array(), true);
	ProcessResult("Mount 'testing.dat' for writing with reverse-diff support", $result);

	$efss->SetDefaultOwner("root");
	$efss->SetDefaultGroup("admin");

	// Test file_get_contents().
	$result = $efss->file_get_contents("test.txt");
	ProcessResult("file_get_contents(\"test.txt\")", $result);
	ProcessResult("Test file and data read equality test", $result["data"] === $testdata);

	// Test file_put_contents().
	$testdata = file_get_contents($rootpath . "/test_2.txt");
	$result = $efss->file_put_contents("csv test.txt", $testdata);
	ProcessResult("file_put_contents(\"csv test.txt\")", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	$result = $efss->file_get_contents("csv test.txt");
	ProcessResult("file_get_contents(\"csv test.txt\")", $result);
	ProcessResult("Test file and data read equality test", $result["data"] === $testdata);

	// Test fgetcsv().
	$result = $efss->fopen("csv test.txt", "rb");
	ProcessResult("fopen(\"csv test.txt\")", $result);
	$fp = $result["fp"];

	// Test rewind() on inline file.
	$result = $efss->rewind($fp);
	ProcessResult("rewind()", $result);

	$result = $efss->fgetcsv($fp);
	ProcessResult("fgetcsv()", $result);
	ProcessResult("Test data read size", count($result["line"]) == 8);
	ProcessResult("Data read equality test", ($result["line"][0] === "1" && $result["line"][1] === "2" && $result["line"][2] === "3" && $result["line"][3] === "4" && $result["line"][4] === "5,\",6" && $result["line"][5] === "7" && $result["line"][6] === "8" && $result["line"][7] === "9"));
	$line = $result["line"];

	$result = $efss->fclose($fp);
	ProcessResult("fclose()", $result);

	// Test fputcsv().
	$result = $efss->fopen("csv test 2.txt", "wb");
	ProcessResult("fopen(\"csv test 2.txt\")", $result);
	$fp = $result["fp"];

	$result = $efss->fputcsv($fp, $line);
	ProcessResult("fputcsv()", $result);
	$pos = $result["len"];

	// Test fflush().  Not really necessary to ever call this.
	$result = $efss->fflush($fp);
	ProcessResult("fflush()", $result);

	$result = $efss->fclose($fp);
	ProcessResult("fclose()", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Verify fputcsv() data.
	$result = $efss->fopen("csv test 2.txt", "rb");
	ProcessResult("fopen(\"csv test 2.txt\")", $result);
	$fp = $result["fp"];

	$result = $efss->fgetcsv($fp);
	ProcessResult("fgetcsv()", $result);
	ProcessResult("Test data read size", count($result["line"]) == 8);
	ProcessResult("Data read equality test", ($result["line"][0] === "1" && $result["line"][1] === "2" && $result["line"][2] === "3" && $result["line"][3] === "4" && $result["line"][4] === "5,\",6" && $result["line"][5] === "7" && $result["line"][6] === "8" && $result["line"][7] === "9"));

	// Test fpassthru().
	$result = $efss->rewind($fp);
	ProcessResult("rewind()", $result);

	ob_start();
	$result = $efss->fpassthru($fp);
	$data = ob_get_contents();
	ob_end_clean();
	ProcessResult("fpassthru()", $result);
	ProcessResult("Test file and data read size equality test", strlen($data) == $pos);

	// Test fstat().
	$result = $efss->fstat($fp);
	ProcessResult("fstat()", $result);
	ProcessResult("Verify owner/user (root)", $result["stat"]["uname"] === "root");
	ProcessResult("Verify group (admin)", $result["stat"]["gname"] === "admin");

	$result = $efss->fclose($fp);
	ProcessResult("fclose()", $result);

	// Test file_exists().
	$result = $efss->file_exists("/");
	ProcessResult("file_exists(\"/\")", $result);

	$result = $efss->file_exists("/csv test 2.txt");
	ProcessResult("file_exists(\"/csv test 2.txt\")", $result);

	$result = $efss->file_exists("csv test 2.txt");
	ProcessResult("file_exists(\"csv test 2.txt\")", $result);

	$result = $efss->file_exists("fail.txt");
	ProcessResult("file_exists(\"fail.txt\") - Not exist", !$result["success"]);

	// Test file().
	$result = $efss->file("csv test 2.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	ProcessResult("file(\"csv test 2.txt\")", $result);
	ProcessResult("Data read size test", count($result["lines"]) == 1);

	// Test filectime().
	$result = $efss->filectime("csv test 2.txt");
	ProcessResult("filectime(\"csv test 2.txt\")", $result);
	ProcessResult("File time is less than 5 seconds old", $result["created"] > time() - 5);

	// Test filegroupname().
	$result = $efss->filegroupname("csv test 2.txt");
	ProcessResult("filegroupname(\"csv test 2.txt\")", $result);
	ProcessResult("Verify group (admin)", $result["groupname"] === "admin");

	// Test fileownername().
	$result = $efss->fileownername("csv test 2.txt");
	ProcessResult("fileownername(\"csv test 2.txt\")", $result);
	ProcessResult("Verify owner/user (root)", $result["ownername"] === "root");

	// Test fileinode().
	$result = $efss->fileinode("csv test 2.txt");
	ProcessResult("fileinode(\"csv test 2.txt\")", $result);
	ProcessResult("Data location test", $result["inode"] == 4);

	// Test fileperms().
	$result = $efss->fileperms("csv test 2.txt");
	ProcessResult("fileperms(\"csv test 2.txt\")", $result);
	ProcessResult("Data permissions test", ($result["perms"] & EFSS_PERM_ALLOWED_FILE) == 0664);

	// Test filetype().
	$result = $efss->filetype("csv test 2.txt");
	ProcessResult("filetype(\"csv test 2.txt\")", $result);
	ProcessResult("Entry type test", $result["type"] === "file");

	// Test GetFileInfo().
	$result = $efss->GetFileInfo("csv test 2.txt");
	ProcessResult("GetFileInfo(\"csv test 2.txt\")", $result);

	// Test is_dir().
	$result = $efss->is_dir("csv test 2.txt");
	ProcessResult("is_dir(\"csv test 2.txt\")", $result);
	ProcessResult("is_dir(\"csv test 2.txt\") - Not directory", !$result["result"]);

	// Test is_file().
	$result = $efss->is_file("csv test 2.txt");
	ProcessResult("is_file(\"csv test 2.txt\")", $result);
	ProcessResult("is_file(\"csv test 2.txt\") - Is file", $result["result"]);

	// Test is_link().
	$result = $efss->is_link("csv test 2.txt");
	ProcessResult("is_link(\"csv test 2.txt\")", $result);
	ProcessResult("is_link(\"csv test 2.txt\") - Not symlink", !$result["result"]);

	// Test tempnam().
	$result = $efss->tempnam("/", "test_", ".txt");
	ProcessResult("tempnam(\"/\", \"test_\", \".txt\")", $result);

	// Test glob().
	$result = $efss->glob("", "/csv test 2/");
	ProcessResult("glob(\"\", \"/csv test 2/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 1 && $result["matches"][0] === "csv test 2.txt");

	// Test stat().
	$result = $efss->stat("csv test.txt");
	ProcessResult("stat(\"csv test.txt\")", $result);
	ProcessResult("Data inline test", ($result["stat"]["mode"] & EFSS_FLAG_INLINE) == EFSS_FLAG_INLINE);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Test unlink().
	$result = $efss->unlink("csv test 2.txt");
	ProcessResult("unlink(\"csv test 2.txt\")", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	$result = $efss->glob("", "/csv test 2/");
	ProcessResult("glob(\"\", \"/csv test 2/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 0);

	// Test mkdir().
	$result = $efss->mkdir("test/test2");
	ProcessResult("mkdir(\"test/test2\") - Not found", (!$result["success"] && $result["errorcode"] == "path_not_found"));

	$result = $efss->mkdir("test");
	ProcessResult("mkdir(\"test\")", $result);

	$result = $efss->mkdir("test/test2/test3", false, true);
	ProcessResult("mkdir(\"test/test2/test3\", false, true)", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Test chdir().
	$result = $efss->chdir("test/test2");
	ProcessResult("chdir(\"test/test2\")", $result);

	$result = $efss->glob("", "/.*/");
	ProcessResult("glob(\"\", \"/.*/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 1 && $result["matches"][0] === "test3");

	$result = $efss->getcwd();
	ProcessResult("getcwd() is '/test/test2'", $result["cwd"] == "/test/test2", false);

	$result = $efss->chdir("../blah");
	ProcessResult("chdir(\"../blah\") - Not found", (!$result["success"] && $result["errorcode"] == "path_not_found"));

	$result = $efss->getcwd();
	ProcessResult("getcwd() is '/test/test2'", $result["cwd"] == "/test/test2", false);

	// Test rename().
	$result = $efss->rename("/csv test.txt", "csv test.txt");
	ProcessResult("rename(\"/csv test.txt\", \"csv test.txt\")", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	$result = $efss->glob("/", "/csv test/");
	ProcessResult("glob(\"/\", \"/csv test/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 0);

	$result = $efss->glob("", "/csv test/");
	ProcessResult("glob(\"\", \"/csv test/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 1);

	$result = $efss->stat("csv test.txt");
	ProcessResult("stat(\"csv test.txt\")", $result);
	ProcessResult("Data inline test", ($result["stat"]["mode"] & EFSS_FLAG_INLINE) == EFSS_FLAG_INLINE);

	// Test copy().
	$result = $efss->copy("csv test.txt", "/csv test.txt");
	ProcessResult("copy(\"csv test.txt\", \"/csv test.txt\")", $result);

	$result = $efss->glob("/", "/csv test/");
	ProcessResult("glob(\"/\", \"/csv test/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 1);

	$result = $efss->glob("", "/csv test/");
	ProcessResult("glob(\"\", \"/csv test/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 1);

	$result = $efss->stat("/csv test.txt");
	ProcessResult("stat(\"/csv test.txt\")", $result);
	ProcessResult("Data inline test", ($result["stat"]["mode"] & EFSS_FLAG_INLINE) == EFSS_FLAG_INLINE);

	// Test rmdir().
	$result = $efss->rmdir("");
	ProcessResult("rmdir(\"\") - Directory not empty", (!$result["success"] && $result["errorcode"] == "dir_not_empty"));

	$result = $efss->rmdir("", true);
	ProcessResult("rmdir(\"\", true)", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	$result = $efss->getcwd();
	ProcessResult("getcwd() is '/test' (rmdir() in current working directory result)", $result["cwd"] === "/test", false);

	$result = $efss->glob("", "/.*/");
	ProcessResult("glob(\"\", \"/.*/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 0);

	// Test symlink().
	$result = $efss->symlink("/csv test.txt", "test.txt");
	ProcessResult("symlink(\"/csv test.txt\", \"/test.txt/\")", $result);

	$result = $efss->glob("", "/.*/");
	ProcessResult("glob(\"\", \"/.*/\")", $result);
	ProcessResult("Data read size test", count($result["matches"]) == 1 && $result["matches"][0] === "test.txt");

	// Test lstat().
	$result = $efss->lstat("test.txt");
	ProcessResult("lstat(\"test.txt\")", $result);

	// Test readlink().
	$result = $efss->readlink("test.txt");
	ProcessResult("readlink(\"test.txt\")", $result);
	ProcessResult("readlink(\"test.txt\") - Target is '/csv test.txt'", $result["link"] === "/csv test.txt");

	$result = $efss->file_get_contents("test.txt");
	ProcessResult("file_get_contents(\"test.txt\")", $result);
	ProcessResult("Test file and data read equality test", $result["data"] === $testdata);

	// Test chown().
	$result = $efss->chown("test.txt", "test");
	ProcessResult("chown(\"test.txt\", \"test\")", $result);

	$result = $efss->stat("/csv test.txt");
	ProcessResult("stat(\"/csv test.txt\")", $result);
	ProcessResult("Verify owner/user (test)", $result["stat"]["uname"] === "test");

	$result = $efss->stat("test.txt");
	ProcessResult("stat(\"test.txt\")", $result);
	ProcessResult("Verify owner/user (test)", $result["stat"]["uname"] === "test");

	$result = $efss->lstat("test.txt");
	ProcessResult("lstat(\"test.txt\")", $result);
	ProcessResult("Verify owner/user (root)", $result["stat"]["uname"] === "root");

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Test chgrp.
	$result = $efss->chgrp("test.txt", "test");
	ProcessResult("chgrp(\"test.txt\", \"test\")", $result);

	$result = $efss->stat("/csv test.txt");
	ProcessResult("stat(\"/csv test.txt\")", $result);
	ProcessResult("Verify group (test)", $result["stat"]["gname"] === "test");

	$result = $efss->stat("test.txt");
	ProcessResult("stat(\"test.txt\")", $result);
	ProcessResult("Verify group (test)", $result["stat"]["gname"] === "test");

	$result = $efss->lstat("test.txt");
	ProcessResult("lstat(\"test.txt\")", $result);
	ProcessResult("Verify group (admin)", $result["stat"]["gname"] === "admin");

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Test lchown().
	$result = $efss->lchown("test.txt", "test2");
	ProcessResult("lchown(\"test.txt\", \"test2\")", $result);

	$result = $efss->stat("/csv test.txt");
	ProcessResult("stat(\"/csv test.txt\")", $result);
	ProcessResult("Verify owner/user (test)", $result["stat"]["uname"] === "test");

	$result = $efss->stat("test.txt");
	ProcessResult("stat(\"test.txt\")", $result);
	ProcessResult("Verify owner/user (test)", $result["stat"]["uname"] === "test");

	$result = $efss->lstat("test.txt");
	ProcessResult("lstat(\"test.txt\")", $result);
	ProcessResult("Verify owner/user (test2)", $result["stat"]["uname"] === "test2");

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Test lchgrp.
	$result = $efss->lchgrp("test.txt", "test2");
	ProcessResult("lchgrp(\"test.txt\", \"test2\")", $result);

	$result = $efss->stat("/csv test.txt");
	ProcessResult("stat(\"/csv test.txt\")", $result);
	ProcessResult("Verify group (test)", $result["stat"]["gname"] === "test");

	$result = $efss->stat("test.txt");
	ProcessResult("stat(\"test.txt\")", $result);
	ProcessResult("Verify group (test)", $result["stat"]["gname"] === "test");
	ProcessResult("Verify mode is 0664", ($result["stat"]["mode"] & EFSS_PERM_ALLOWED_FILE) === 0664);

	$result = $efss->lstat("test.txt");
	ProcessResult("lstat(\"test.txt\")", $result);
	ProcessResult("Verify group (test2)", $result["stat"]["gname"] === "test2");

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Test chmod().
	$result = $efss->chmod("test.txt", 0777);
	ProcessResult("chmod(\"test.txt\", 0777)", $result);

	$result = $efss->stat("test.txt");
	ProcessResult("stat(\"test.txt\")", $result);
	ProcessResult("Verify mode is 0777", ($result["stat"]["mode"] & EFSS_PERM_ALLOWED_FILE) === 0777);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Test touch().
	$result = $efss->touch("/csv test.txt", 1000000);
	ProcessResult("touch(\"/csv test.txt\")", $result);

	$result = $efss->stat("test.txt");
	ProcessResult("stat(\"test.txt\")", $result);
	ProcessResult("Verify timestamp is 1000000", $result["stat"]["mtime"] === 1000000);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Verify that the file is exactly 7 blocks in size.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.dat'", $result);
	ProcessResult("Checking 'testing.dat' file size (7 blocks)", filesize($rootpath . "/testing.dat") === 4096 * 7);

	// Test incremental GetLock().
	$result = EFSSIncremental::GetLock($rootpath . "/testing.dat", false);
	ProcessResult("EFSSIncremental::GetLock(\"testing.dat\", false)", $result);
	$readlock = $result["lock"];

	// Test incremental export base file clone.
	$result = EFSSIncremental::Read($rootpath . "/testing.dat", 0, 0, 0);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\", 0, 0, 0)", $result);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Not incremental", !isset($result["blocknums"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Has serial", isset($result["serial"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - No next block", !isset($result["nextblock"]));
	unset($readlock);

	$result2 = EFSSIncremental::GetLock($rootpath . "/testing.bak", true);
	ProcessResult("EFSSIncremental::GetLock(\"testing.bak\", true)", $result2);
	$writelock = $result2["lock"];
	unset($result2);

	$result = EFSSIncremental::Write($rootpath . "/testing.bak", 0, $result["blocks"], $result["updates"], $result["md5"], $result["sha1"], false, $result["serial"], 4096, true);
	ProcessResult("EFSSIncremental::Write(\"testing.bak\", \"testing.bak\", 0, [blocks], [updates], [md5], [sha1], false, [serial], 4096, true)", $result);

	$result = EFSSIncremental::MakeReadOnly($rootpath . "/testing.bak");
	ProcessResult("EFSSIncremental::MakeReadOnly(\"testing.bak\")", $result);

	ProcessResult("Checking 'testing.bak' file size (7 blocks)", filesize($rootpath . "/testing.bak") === 4096 * 7);
	ProcessResult("Comparing 'testing.bak' to 'testing.dat'", file_get_contents($rootpath . "/testing.bak") === file_get_contents($rootpath . "/testing.dat"));
	ProcessResult("Comparing 'testing.bak.updates' to 'testing.dat.updates'", file_get_contents($rootpath . "/testing.bak.updates") === file_get_contents($rootpath . "/testing.dat.updates"));
	ProcessResult("Comparing 'testing.bak.serial' to 'testing.dat.serial'", file_get_contents($rootpath . "/testing.bak.serial") === file_get_contents($rootpath . "/testing.dat.serial"));
	unset($writelock);

	// Mount the file system for writing.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat", EFSS_MODE_EXCL);
	ProcessResult("Mount 'testing.dat' for writing", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Unmount the file system.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.dat'", $result);

	// Verify that it hasn't changed using the incremental export.
	$result = EFSSIncremental::LastUpdated($rootpath . "/testing.bak");
	ProcessResult("EFSSIncremental::LastUpdated(\"testing.bak\")", $result);
	$lastupdate = $result["lastupdate"];
	$serial = file_get_contents($rootpath . "/testing.bak.serial");

	$result = EFSSIncremental::Read($rootpath . "/testing.dat", $lastupdate, 0, 0);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\", \"" . $lastupdate . "\", 0, 0)", $result);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Is incremental", isset($result["blocknums"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Has serial", isset($result["serial"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Has matching serial", $result["serial"] === $serial);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - No next block", !isset($result["nextblock"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - No block data", $result["blocks"] === "");

	// Mount the file system for writing.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat", EFSS_MODE_EXCL);
	ProcessResult("Mount 'testing.dat' for writing", $result);

	// Write the third test suite file.
	$result = $efss->file_put_contents("/test/donate.txt", file_get_contents($rootpath . "/test_3.txt"));
	ProcessResult("file_put_contents(\"/test/donate.txt\")", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Unmount the file system.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.dat'", $result);

	// Test generating a reverse diff incremental.
	$result = EFSSIncremental::GetLock($rootpath . "/testing.dat", true);
	ProcessResult("EFSSIncremental::GetLock(\"testing.dat\", true)", $result);
	$writelock = $result["lock"];

	$result = EFSSIncremental::MakeReverseDiffIncremental($rootpath . "/testing.dat", $rootpath . "/testing.dat.r1");
	ProcessResult("EFSSIncremental::MakeReverseDiffIncremental(\"testing.dat\", \"testing.dat.r1\")", $result);
	unset($writelock);

	// Mount the reverse diff incremental file system for reading.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat", EFSS_MODE_READ, false, 4096, array($rootpath . "/testing.dat.r1"));
	ProcessResult("Mount 'testing.dat' + 'testing.dat.r1' for reading", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Make sure the donation poetry isn't viewable.
	$result = $efss->file_get_contents("/test/donate.txt");
	ProcessResult("file_get_contents(\"/test/donate.txt\") - Does not exist", ($result["success"] === false && $result["errorcode"] == "path_not_found"));

	// Unmount the file system.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.dat' + 'testing.dat.r1'", $result);

	// Generate the incremental file.
	$result = EFSSIncremental::GetLock($rootpath . "/testing.dat", false);
	ProcessResult("EFSSIncremental::GetLock(\"testing.dat\", false)", $result);
	$readlock = $result["lock"];

	$result = EFSSIncremental::Read($rootpath . "/testing.dat", $lastupdate, 0, 0);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\", \"" . $lastupdate . "\", 0, 0)", $result);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Is incremental", isset($result["blocknums"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Has serial", isset($result["serial"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Has matching serial", $result["serial"] === $serial);
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - No next block", !isset($result["nextblock"]));
	ProcessResult("EFSSIncremental::Read(\"testing.dat\") - Has block data", $result["blocks"] !== "");

	$result2 = EFSSIncremental::GetLock($rootpath . "/testing.bak", true);
	ProcessResult("EFSSIncremental::GetLock(\"testing.bak\", true)", $result2);
	$writelock = $result2["lock"];
	unset($result2);

	$result = EFSSIncremental::Write($rootpath . "/testing.bak.1", 0, $result["blocks"], $result["updates"], $result["md5"], $result["sha1"], $result["blocknums"], $serial, 4096, true);
	ProcessResult("EFSSIncremental::Write(\"testing.bak.1\", 0, [blocks], [updates], [md5], [sha1], [blocknums], [serial], 4096, true)", $result);

	// Verify the base file and incremental.
	$result = EFSSIncremental::Verify($key1, $iv1, $key2, $iv2, $rootpath . "/testing.dat");
	ProcessResult("EFSSIncremental::Verify(\"testing.dat\")", $result);

	$result = EFSSIncremental::Verify($key1, $iv1, $key2, $iv2, $rootpath . "/testing.bak");
	ProcessResult("EFSSIncremental::Verify(\"testing.bak\")", $result);

	$result = EFSSIncremental::Verify($key1, $iv1, $key2, $iv2, $rootpath . "/testing.bak.1");
	ProcessResult("EFSSIncremental::Verify(\"testing.bak.1\")", $result);

	$result = EFSSIncremental::Verify("", "", "", "", $rootpath . "/testing.bak");
	ProcessResult("EFSSIncremental::Verify(\"testing.bak\"), hashes only", $result);

	$result = EFSSIncremental::Verify("", "", "", "", $rootpath . "/testing.bak.1");
	ProcessResult("EFSSIncremental::Verify(\"testing.bak.1\"), hashes only", $result);
	unset($writelock);
	unset($readlock);

	// Mount the incremental file system for reading.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.bak", EFSS_MODE_READ, false, 4096, array($rootpath . "/testing.bak.1"));
	ProcessResult("Mount 'testing.bak' + 'testing.bak.1' for reading", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Unmount the file system.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.bak' + 'testing.bak.1'", $result);

	// Test merging.
	$result2 = EFSSIncremental::GetLock($rootpath . "/testing.bak", true);
	ProcessResult("EFSSIncremental::GetLock(\"testing.bak\", true)", $result2);
	$writelock = $result2["lock"];
	unset($result2);
	$result = EFSSIncremental::Merge($rootpath . "/testing.bak", $rootpath . "/testing.bak.1");
	ProcessResult("EFSSIncremental::Merge(\"testing.bak\", \"testing.bak.1\")", $result);
	@unlink($rootpath . "/testing.bak.1.partial");
	unset($writelock);

	// Verify the merged data.
	$result = EFSSIncremental::Verify($key1, $iv1, $key2, $iv2, $rootpath . "/testing.bak");
	ProcessResult("EFSSIncremental::Verify(\"testing.bak\")", $result);

	// Verify the merged hashes.
	$result = EFSSIncremental::Verify("", "", "", "", $rootpath . "/testing.bak");
	ProcessResult("EFSSIncremental::Verify(\"testing.bak\"), hashes only", $result);

	// Mount the incremental file system for reading.
	$result = $efss->Mount($key1, $iv1, $key2, $iv2, $rootpath . "/testing.bak", EFSS_MODE_READ);
	ProcessResult("Mount 'testing.bak' for reading", $result);

	$result = $efss->CheckFS();
	ProcessResult("CheckFS()", $result);

	// Read in donation poetry.
	$result = $efss->file_get_contents("/test/donate.txt");
	ProcessResult("file_get_contents(\"/test/donate.txt\")", $result);
	$donate = $result["data"];

	// Unmount the file system.
	$result = $efss->Unmount();
	ProcessResult("Unmount 'testing.bak'", $result);

	// Find floating-point data warble point.
	$str = "\x7F\xFF\xFF\xFF\xFF\xFF\xFF\xFF";
	$maxfail = $str;
	$maxsuccess = "";

	while ($str !== "\x00\x00\x00\x00\x00\x00\x00\x00")
	{
		$num = EFSS::UnpackInt($str);
		$str2 = EFSS::PackInt64($num);

		if ($str !== $str2)  $maxfail = $str;
		else if ($maxsuccess === "")  $maxsuccess = $str;

		for ($x = 0; $x < 8 && $str{$x} == "\x00"; $x++);
		$str = substr($str, 0, $x) . chr(ord($str{$x}) - 1) . substr($str, $x + 1);
	}

	echo "Numeric accuracy will tend to always fail around:\n\t0x" . bin2hex($maxfail) . " (" . number_format(EFSS::UnpackInt($maxfail), 0) . ")\n\n";
	echo "The maximum successfully tested numeric value was:\n\t0x" . bin2hex($maxsuccess) . " (" . number_format(EFSS::UnpackInt($maxsuccess), 0) . ")\n\n";
	echo "EFSS theoretical limit (standard 4096 byte block size):\n\t8,796,093,022,208 bytes (2^31 * 4096).\n\n";
	echo "EFSS theoretical hard limit is (32768 byte block size):\n\t70,368,744,177,664 bytes (2^31 * 32768).\n\n";
	ProcessResult("Theoretical limit smaller than floating point limit", 8796093022208 < EFSS::UnpackInt($maxsuccess), false);
	ProcessResult("Theoretical hard limit smaller than floating point limit", 70368744177664 < EFSS::UnpackInt($maxsuccess), false);

	// Run optional tests.
	if (isset($args["opts"]["optional"]))
	{
		echo "\n-----\n";
		echo "Running optional tests (the test script doesn't monitor the output)...\n";
		$php = (isset($args["opts"]["php"]) ? $args["opts"]["php"] : "php");

		// Test 'create.php'.
		echo "\n\n=====\n";
		$cmd = escapeshellcmd($php) . " " . escapeshellarg($rootpath . "/../create.php") . " " . escapeshellarg($rootpath . "/testing2.dat");
		echo $cmd . "\n";
		system($cmd);
		sleep(2);

		// Test 'sync.php'.
		echo "\n\n=====\n";
		$cmd = escapeshellcmd($php) . " " . escapeshellarg($rootpath . "/../sync.php") . " -v -e=/cache/ " . escapeshellarg($rootpath . "/../support") . " " . escapeshellarg("efss://" . $rootpath . "/testing2.dat//support");
		echo $cmd . "\n";
		system($cmd);
		sleep(2);

		// Test 'shell.php'.
		echo "\n\n=====\n";
		$currdir = getcwd();
		chdir($rootpath);
		echo "Changed to:  " . $rootpath . "\n";
		$cmd = escapeshellcmd($php) . " " . escapeshellarg($rootpath . "/../shell.php") . " -s=test_4.txt " . escapeshellarg($rootpath . "/testing2.dat");
		echo $cmd . "\n";
		system($cmd);
		chdir($currdir);
		echo "Changed to:  " . $currdir . "\n";
		sleep(2);

		// Test 'check.php'.
		echo "\n\n=====\n";
		$cmd = escapeshellcmd($php) . " " . escapeshellarg($rootpath . "/../check.php") . " " . escapeshellarg($rootpath . "/testing2.dat");
		echo $cmd . "\n";
		system($cmd);
		sleep(2);

		// Test 'backup.php' push.
		$baseurl = $args["opts"]["optional"];
		echo "\n\n=====\n";
		$cmd = escapeshellcmd($php) . " " . escapeshellarg($rootpath . "/../backup.php") . " " . escapeshellarg($rootpath . "/testing2.dat") . " " . escapeshellarg($baseurl);
		echo $cmd . "\n";
		system($cmd);
		sleep(2);

		// Test 'backup.php' pull.
		echo "\n\n=====\n";
		$cmd = escapeshellcmd($php) . " " . escapeshellarg($rootpath . "/../backup.php") . " " . escapeshellarg($baseurl) . " " . escapeshellarg($rootpath . "/testing4.dat");
		echo $cmd . "\n";
		system($cmd);
		sleep(2);

		echo "\n\n=====\n";
		echo "Optional tests finished.\n";
	}

	// Output results.
	echo "\n-----\n";
	if (!$failed && !$skipped)  echo "All tests were successful.\n";
	else  echo "Results:  " . $passed . " passed, " . $failed . " failed, " . $skipped . " skipped.\n";

	// Output donation poetry.
	echo "\nAnd now for some poetry that was stored in the test file system:\n\n";
	echo $donate;
?>