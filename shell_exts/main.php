<?php
	// Encrypted File Storage System command-line basic shell functions
	// (C) 2013 CubicleSoft.  All Rights Reserved.

	function shell_cmd_ls($line)
	{
		global $efss;

		if (is_array($line))  $args = $line;
		else
		{
			$options = array(
				"shortmap" => array(
					"a" => "all",
					"b" => "blocks",
					"l" => "long",
					"r" => "regex",
					"R" => "recursive",
					"?" => "help"
				),
				"rules" => array(
					"all" => array("arg" => false),
					"blocks" => array("arg" => false),
					"long" => array("arg" => false),
					"regex" => array("arg" => true, "multiple" => true),
					"recursive" => array("arg" => false),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, $line);

			$args["origopts"] = $args["opts"];
		}

		if (count($args["params"]) > 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - List directory command\n";
			echo "Purpose:  Display a directory listing.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] [path]\n";
			echo "Options:\n";
			echo "\t-a         All files and directories.\n";
			echo "\t-b         Dump physical block storage format.\n";
			echo "\t-l         Long listing format.\n";
			echo "\t-r=regex   Regular expression match.\n";
			echo "\t-R         Recursive scan.\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " -laR -r=/[.]php\$/ /\n";

			return;
		}

		$path = (count($args["params"]) ? $args["params"][0] : "");
		$result = $efss->opendir($path);
		if (!$result["success"])
		{
			DisplayError("Unable to open directory '" . $path . "'.", $result);

			return;
		}
		$dir = $result["dir"];
		$path = $result["path"];

		if (!isset($args["opts"]["regex"]))  $args["opts"]["regex"] = array('/.*/');
		if ($efss->GetDirMode() & EFSS_DIRMODE_CASE_INSENSITIVE)
		{
			foreach ($args["opts"]["regex"] as $num => $pattern)  $args["opts"]["regex"][$num] = $pattern . "i";
		}

		if (isset($args["opts"]["long"]))
		{
			$maxowner = 0;
			$maxgroup = 0;
			$maxfullsize = 0;
			$result = $efss->readdir($dir);
			while ($result["success"])
			{
				$name = $result["name"];

				if (isset($args["opts"]["all"]) || substr($name, 0, 1) != ".")
				{
					foreach ($args["opts"]["regex"] as $pattern)
					{
						if (preg_match($pattern, $name))
						{
							if (strlen($result["info"]->ownername) > $maxowner)  $maxowner = strlen($result["info"]->ownername);
							if (strlen($result["info"]->groupname) > $maxgroup)  $maxgroup = strlen($result["info"]->groupname);

							$fullsize = number_format($result["info"]->fullsize, 0);
							if (strlen($fullsize) > $maxfullsize)  $maxfullsize = strlen($fullsize);

							break;
						}
					}
				}

				$result = $efss->readdir($dir);
			}

			if ($result["errorcode"] != "dir_end")
			{
				DisplayError("Unable to read directory entry.", $result);

				return;
			}

			$result = $efss->rewinddir($dir);
			if (!$result["success"])
			{
				DisplayError("Unable to rewind directory.", $result);

				return;
			}
		}

		$output = false;
		$blocks = isset($args["opts"]["blocks"]);
		$dircompress = ($efss->GetDirMode() & EFSS_DIRMODE_COMPRESS);
		$result = $efss->readdir($dir, $blocks);
		while ($result["success"])
		{
			if ($blocks)
			{
				$handle = $result["info"];

				echo "Block #" . $handle["currblock"] . "\n";
			}

			if (!$output && isset($args["opts"]["recursive"]) && !isset($args["origopts"]["regex"]))
			{
				echo ($blocks ? "\t" : "") . $path . "\n";

				$output = true;
			}

			do
			{
				if ($blocks)
				{
					if (!count($handle["currdir"]->entries))  continue;

					$entry = array_shift($handle["currdir"]->entries);
					$result = array("success" => true, "name" => $entry->name, "info" => $entry);
				}

				$name = $result["name"];

				if (isset($args["opts"]["all"]) || substr($name, 0, 1) != ".")
				{
					$found = false;

					foreach ($args["opts"]["regex"] as $pattern)
					{
						if (preg_match($pattern, $name))
						{
							$found = true;

							break;
						}
					}

					if ($found)
					{
						if (!$output && isset($args["opts"]["recursive"]))
						{
							echo ($blocks ? "\t" : "") . $path . "\n";

							$output = true;
						}

						if (isset($args["opts"]["long"]))
						{
							// Attributes:  l/d c i sst rwx rwx rwx
							$symlink = ($result["info"]->type == EFSS_DIRTYPE_SYMLINK);
							$permflags = $result["info"]->permflags;

							if ($result["info"]->type == EFSS_DIRTYPE_DIR)
							{
								$attr = "d";
								if ($dircompress)  $permflags |= EFSS_FLAG_COMPRESS;
							}
							else if ($symlink)  $attr = "l";
							else if ($permflags & EFSS_FLAG_INLINE)  $attr = "i";
							else  $attr = "-";

							$attr .= ($permflags & EFSS_FLAG_COMPRESS ? "c" : "-");

							$attr .= ($symlink || $permflags & EFSS_PERM_O_R ? "r" : "-");
							$attr .= ($symlink || $permflags & EFSS_PERM_O_W ? "w" : "-");
							if ($permflags & EFSS_PERM_O_S)  $attr .= "s";
							else if ($symlink || $permflags & EFSS_PERM_O_X)  $attr .= "x";
							else  $attr .= "-";

							$attr .= ($symlink || $permflags & EFSS_PERM_G_R ? "r" : "-");
							$attr .= ($symlink || $permflags & EFSS_PERM_G_W ? "w" : "-");
							if ($permflags & EFSS_PERM_G_S)  $attr .= "s";
							else if ($symlink || $permflags & EFSS_PERM_G_X)  $attr .= "x";
							else  $attr .= "-";

							$attr .= ($symlink || $permflags & EFSS_PERM_W_R ? "r" : "-");
							$attr .= ($symlink || $permflags & EFSS_PERM_W_W ? "w" : "-");
							if ($permflags & EFSS_PERM_W_T)  $attr .= "s";
							else if ($symlink || $permflags & EFSS_PERM_W_X)  $attr .= "x";
							else  $attr .= "-";

							// Output:  Attributes Owner Group Created Filesize
							echo ($blocks ? "\t" : "") . $attr . " " . sprintf("%-" . $maxowner . "s", $result["info"]->ownername) . " " . sprintf("%-" . $maxgroup . "s", $result["info"]->groupname) . " " . sprintf("%" . $maxfullsize . "s", number_format($result["info"]->fullsize, 0)) . " " . date("Y-M-d h:i A", $result["info"]->created) . "  ";
						}

						echo $name;

						if (isset($args["opts"]["long"]) && $result["info"]->type == EFSS_DIRTYPE_SYMLINK)  echo " -> " . $result["info"]->data;

						echo "\n";
					}
				}
			} while ($blocks && count($handle["currdir"]->entries));

			$result = $efss->readdir($dir, $blocks);
		}

		if (!$output && isset($args["opts"]["recursive"]) && !isset($args["origopts"]["regex"]))
		{
			echo ($blocks ? "\t" : "") . $path . "\n";

			$output = true;
		}

		if ($result["errorcode"] != "dir_end")
		{
			DisplayError("Unable to read directory entry.", $result);

			return;
		}

		if ($output)  echo "\n";

		if (isset($args["opts"]["recursive"]))
		{
			$result = $efss->rewinddir($dir);
			if (!$result["success"])
			{
				DisplayError("Unable to rewind directory.", $result);

				return;
			}

			$result = $efss->readdir($dir);
			while ($result["success"])
			{
				if ($result["info"]->type == EFSS_DIRTYPE_DIR)
				{
					$name = $result["name"];
					$args["params"][0] = ($path == "/" ? "/" : $path . "/") . $name;
					shell_cmd_ls($args);
				}

				$result = $efss->readdir($dir);
			}

			if ($result["errorcode"] != "dir_end")
			{
				DisplayError("Unable to read directory entry.", $result);

				return;
			}
		}

		$efss->closedir($dir);
	}

	function shell_cmd_dir($line)
	{
		shell_cmd_ls($line);
	}

	function shell_cmd_cd($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Change directory command\n";
			echo "Purpose:  Change to another directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] path\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " /etc\n";

			return;
		}

		$path = $args["params"][0];

		$result = $efss->chdir($path);
		if (!$result["success"])
		{
			DisplayError("Unable to change directory to '" . $path . "'.", $result);

			return;
		}
	}

	function shell_cmd_chdir($line)
	{
		shell_cmd_cd($line);
	}

	function shell_cmd_md($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"m" => "mode",
				"o" => "owner",
				"g" => "group",
				"t" => "ts",
				"?" => "help"
			),
			"rules" => array(
				"mode" => array("arg" => true),
				"owner" => array("arg" => true),
				"group" => array("arg" => true),
				"ts" => array("arg" => true),
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Make directory command\n";
			echo "Purpose:  Create a new directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] path\n";
			echo "Options:\n";
			echo "\t-m=mode        The mode, in octal, of the directory.\n";
			echo "\t-o=owner       The owner of the directory.\n";
			echo "\t-g=group       The group of the directory.\n";
			echo "\t-t=timestamp   The local timestamp of the directory.\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " -t=\"" . gmdate("Y-m-d H:i:s") . "\" /etc\n";

			return;
		}

		$mode = (isset($args["opts"]["mode"]) ? octdec($args["opts"]["mode"]) : false);
		$owner = (isset($args["opts"]["owner"]) ? $args["opts"]["owner"] : false);
		$group = (isset($args["opts"]["group"]) ? $args["opts"]["group"] : false);
		$created = (isset($args["opts"]["ts"]) ? EFSS::ConvertFromLocalDateTime($args["opts"]["ts"]) : false);

		$path = $args["params"][0];

		$result = $efss->mkdir($path, $mode, true, $owner, $group, $created);
		if (!$result["success"])
		{
			DisplayError("Unable to create directory '" . $path . "'.", $result);

			return;
		}
	}

	function shell_cmd_mkdir($line)
	{
		shell_cmd_md($line);
	}

	function shell_cmd_rd($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"r" => "recursive",
				"?" => "help"
			),
			"rules" => array(
				"recursive" => array("arg" => false),
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Remove directory command\n";
			echo "Purpose:  Remove a directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] path\n";
			echo "Options:\n";
			echo "\t-r   Recursive delete.\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " /etc\n";

			return;
		}

		$recursive = isset($args["opts"]["recursive"]);
		$path = $args["params"][0];

		$result = $efss->rmdir($path, $recursive);
		if (!$result["success"])
		{
			DisplayError("Unable to remove directory '" . $path . "'.", $result);

			return;
		}
	}

	function shell_cmd_rmdir($line)
	{
		shell_cmd_rd($line);
	}

	function shell_cmd_chown($line)
	{
		global $efss;

		if (is_array($line))  $args = $line;
		else
		{
			$options = array(
				"shortmap" => array(
					"r" => "regex",
					"R" => "recursive",
					"?" => "help"
				),
				"rules" => array(
					"regex" => array("arg" => true, "multiple" => true),
					"recursive" => array("arg" => false),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, $line);
		}

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Change owner command\n";
			echo "Purpose:  Change the owner of a file or directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] ownername path\n";
			echo "Options:\n";
			echo "\t-r=regex   Regular expression match.\n";
			echo "\t-R         Recursive change owner.\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " root /etc\n";

			return;
		}

		$owner = $args["params"][0];
		$path = $args["params"][1];

		$processdir = isset($args["opts"]["regex"]);
		if (!$processdir)  $args["opts"]["regex"] = array('/.*/');
		if ($efss->GetDirMode() & EFSS_DIRMODE_CASE_INSENSITIVE)
		{
			foreach ($args["opts"]["regex"] as $num => $pattern)  $args["opts"]["regex"][$num] = $pattern . "i";
		}

		$result = $efss->realpath($path);
		if (!$result["success"])
		{
			DisplayError("Unable to change owner of '" . $path . "'.", $result);

			return;
		}
		if (!count($result["dirinfo"]))
		{
			DisplayError("Unable to change owner of the root directory.");

			return;
		}
		$name = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
		$path = $result["path"];
		$type = $result["type"];

		foreach ($args["opts"]["regex"] as $pattern)
		{
			if (preg_match($pattern, $name))
			{
				$result = $efss->chown($path, $owner);
				if (!$result["success"])
				{
					DisplayError("Unable to change owner of '" . $path . "'.", $result);

					return;
				}

				break;
			}
		}

		$recursive = isset($args["opts"]["recursive"]);

		if ($type == "dir" && ($processdir || $recursive))
		{
			$regexes = $args["opts"]["regex"];
			if (!$recursive)  unset($args["opts"]["regex"]);

			$result = $efss->opendir($path);
			if (!$result["success"])
			{
				DisplayError("Unable to open directory '" . $path . "'.", $result);

				return;
			}
			$dir = $result["dir"];
			$path = $result["path"];

			$result = $efss->readdir($dir);
			while ($result["success"])
			{
				$name = $result["name"];
				$args["params"][1] = ($path == "/" ? "/" : $path . "/") . $name;

				if ($recursive && ($result["info"]->type == EFSS_DIRTYPE_DIR || $result["info"]->type == EFSS_DIRTYPE_FILE))  shell_cmd_chown($args);
				else
				{
					foreach ($regexes as $pattern)
					{
						if (preg_match($pattern, $name))
						{
							if ($result["info"]->type == EFSS_DIRTYPE_DIR || $result["info"]->type == EFSS_DIRTYPE_FILE)  shell_cmd_chown($args);
							else if ($result["info"]->type == EFSS_DIRTYPE_SYMLINK)
							{
								$result = $efss->lchown($args["params"][1], $owner);
								if (!$result["success"])
								{
									DisplayError("Unable to change owner of '" . $path . "'.", $result);

									return;
								}
							}

							break;
						}
					}
				}

				$result = $efss->readdir($dir);
			}

			if ($result["errorcode"] != "dir_end")
			{
				DisplayError("Unable to read directory entry.", $result);

				return;
			}

			$efss->closedir($dir);
		}
	}

	function shell_cmd_chgrp($line)
	{
		global $efss;

		if (is_array($line))  $args = $line;
		else
		{
			$options = array(
				"shortmap" => array(
					"r" => "regex",
					"R" => "recursive",
					"?" => "help"
				),
				"rules" => array(
					"regex" => array("arg" => true, "multiple" => true),
					"recursive" => array("arg" => false),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, $line);
		}

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Change group command\n";
			echo "Purpose:  Change the group of a file or directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] groupname path\n";
			echo "Options:\n";
			echo "\t-r=regex   Regular expression match.\n";
			echo "\t-R         Recursive change group.\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " root /etc\n";

			return;
		}

		$group = $args["params"][0];
		$path = $args["params"][1];

		$processdir = isset($args["opts"]["regex"]);
		if (!$processdir)  $args["opts"]["regex"] = array('/.*/');
		if ($efss->GetDirMode() & EFSS_DIRMODE_CASE_INSENSITIVE)
		{
			foreach ($args["opts"]["regex"] as $num => $pattern)  $args["opts"]["regex"][$num] = $pattern . "i";
		}

		$result = $efss->realpath($path);
		if (!$result["success"])
		{
			DisplayError("Unable to change group of '" . $path . "'.", $result);

			return;
		}
		if (!count($result["dirinfo"]))
		{
			DisplayError("Unable to change group of the root directory.");

			return;
		}
		$name = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
		$path = $result["path"];
		$type = $result["type"];

		foreach ($args["opts"]["regex"] as $pattern)
		{
			if (preg_match($pattern, $name))
			{
				$result = $efss->chgrp($path, $group);
				if (!$result["success"])
				{
					DisplayError("Unable to change group of '" . $path . "'.", $result);

					return;
				}

				break;
			}
		}

		$recursive = isset($args["opts"]["recursive"]);

		if ($type == "dir" && ($processdir || $recursive))
		{
			$regexes = $args["opts"]["regex"];
			if (!$recursive)  unset($args["opts"]["regex"]);

			$result = $efss->opendir($path);
			if (!$result["success"])
			{
				DisplayError("Unable to open directory '" . $path . "'.", $result);

				return;
			}
			$dir = $result["dir"];
			$path = $result["path"];

			$result = $efss->readdir($dir);
			while ($result["success"])
			{
				$name = $result["name"];
				$args["params"][1] = ($path == "/" ? "/" : $path . "/") . $name;

				if ($recursive && ($result["info"]->type == EFSS_DIRTYPE_DIR || $result["info"]->type == EFSS_DIRTYPE_FILE))  shell_cmd_chgrp($args);
				else
				{
					foreach ($regexes as $pattern)
					{
						if (preg_match($pattern, $name))
						{
							if ($result["info"]->type == EFSS_DIRTYPE_DIR || $result["info"]->type == EFSS_DIRTYPE_FILE)  shell_cmd_chgrp($args);
							else if ($result["info"]->type == EFSS_DIRTYPE_SYMLINK)
							{
								$result = $efss->lchgrp($args["params"][1], $group);
								if (!$result["success"])
								{
									DisplayError("Unable to change group of '" . $path . "'.", $result);

									return;
								}
							}

							break;
						}
					}
				}

				$result = $efss->readdir($dir);
			}

			if ($result["errorcode"] != "dir_end")
			{
				DisplayError("Unable to read directory entry.", $result);

				return;
			}

			$efss->closedir($dir);
		}
	}

	function shell_cmd_chmod($line)
	{
		global $efss;

		if (is_array($line))  $args = $line;
		else
		{
			$options = array(
				"shortmap" => array(
					"r" => "regex",
					"R" => "recursive",
					"?" => "help"
				),
				"rules" => array(
					"regex" => array("arg" => true, "multiple" => true),
					"recursive" => array("arg" => false),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, $line);
		}

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Change mode command\n";
			echo "Purpose:  Change the mode of a file or directory.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] octalmode path\n";
			echo "Options:\n";
			echo "\t-r=regex   Regular expression match.\n";
			echo "\t-R         Recursive change mode.\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " 775 /etc\n";

			return;
		}

		$mode = octdec($args["params"][0]);
		$path = $args["params"][1];

		$processdir = isset($args["opts"]["regex"]);
		if (!$processdir)  $args["opts"]["regex"] = array('/.*/');
		if ($efss->GetDirMode() & EFSS_DIRMODE_CASE_INSENSITIVE)
		{
			foreach ($args["opts"]["regex"] as $num => $pattern)  $args["opts"]["regex"][$num] = $pattern . "i";
		}

		$result = $efss->realpath($path);
		if (!$result["success"])
		{
			DisplayError("Unable to change mode of '" . $path . "'.", $result);

			return;
		}
		if (!count($result["dirinfo"]))
		{
			DisplayError("Unable to change mode of the root directory.");

			return;
		}
		$name = $result["dirinfo"][count($result["dirinfo"]) - 1][0];
		$path = $result["path"];
		$type = $result["type"];

		foreach ($args["opts"]["regex"] as $pattern)
		{
			if (preg_match($pattern, $name))
			{
				$result = $efss->chmod($path, $mode);
				if (!$result["success"])
				{
					DisplayError("Unable to change mode of '" . $path . "'.", $result);

					return;
				}

				break;
			}
		}

		$recursive = isset($args["opts"]["recursive"]);

		if ($type == "dir" && ($processdir || $recursive))
		{
			if (!$recursive)  unset($args["opts"]["regex"]);

			$result = $efss->opendir($path);
			if (!$result["success"])
			{
				DisplayError("Unable to open directory '" . $path . "'.", $result);

				return;
			}
			$dir = $result["dir"];
			$path = $result["path"];

			$result = $efss->readdir($dir);
			while ($result["success"])
			{
				if ($result["info"]->type == EFSS_DIRTYPE_DIR || $result["info"]->type == EFSS_DIRTYPE_FILE)
				{
					$name = $result["name"];
					$args["params"][1] = ($path == "/" ? "/" : $path . "/") . $name;
					shell_cmd_chmod($args);
				}

				$result = $efss->readdir($dir);
			}

			if ($result["errorcode"] != "dir_end")
			{
				DisplayError("Unable to read directory entry.", $result);

				return;
			}

			$efss->closedir($dir);
		}
	}

	function shell_cmd_lchown($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Change symlink owner command\n";
			echo "Purpose:  Change the owner of a symlink.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] ownername path\n";
			echo "Options:\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " root /etc/alink\n";

			return;
		}

		$owner = $args["params"][0];
		$path = $args["params"][1];

		$result = $efss->lchown($path, $owner);
		if (!$result["success"])
		{
			DisplayError("Unable to change owner of '" . $path . "'.", $result);

			return;
		}
	}

	function shell_cmd_lchgrp($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Change symlink group command\n";
			echo "Purpose:  Change the group of a symlink.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] groupname path\n";
			echo "Options:\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " root /etc/alink\n";

			return;
		}

		$group = $args["params"][0];
		$path = $args["params"][1];

		$result = $efss->lchgrp($path, $group);
		if (!$result["success"])
		{
			DisplayError("Unable to change group of '" . $path . "'.", $result);

			return;
		}
	}

	function shell_cmd_import($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (!count($args["params"]) || count($args["params"]) > 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Import file command\n";
			echo "Purpose:  Import a file from the local file system.  Only imports one file.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] srcfile [destpathfile]\n";
			echo "Options:\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " test_suite/test_3.txt donate.txt\n";

			return;
		}

		$srcfile = str_replace("\\", "/", $args["params"][0]);

		$pos = strrpos($srcfile, "/");
		if ($pos === false)  $filename = $srcfile;
		else  $filename = (string)substr($srcfile, $pos + 1);
		if ($filename == "")
		{
			DisplayError("Unable to determine filename of '" . $srcfile . "'.", $result);

			return;
		}

		if (count($args["params"]) == 1)  $destfile = $filename;
		else if (substr($args["params"][1], -1) == "/")  $destfile = $args["params"][1] . $filename;
		else if ($args["params"][1] == ".")  $destfile = $filename;
		else  $destfile = $args["params"][1];

		$result = $efss->copy($srcfile, $destfile, EFSS_COPYMODE_REAL_SOURCE);
		if (!$result["success"])
		{
			DisplayError("Unable to import '" . $srcfile . "' to '" . $destfile . "'.", $result);

			return;
		}
	}

	function shell_cmd_export($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (!count($args["params"]) || count($args["params"]) > 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Export file command\n";
			echo "Purpose:  Export a file to the local file system.  Only exports one file.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] srcfile [destpathfile]\n";
			echo "Options:\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " donate.txt test_donate.txt\n";

			return;
		}

		$srcfile = str_replace("\\", "/", $args["params"][0]);

		$pos = strrpos($srcfile, "/");
		if ($pos === false)  $filename = $srcfile;
		else  $filename = (string)substr($srcfile, $pos + 1);
		if ($filename == "")
		{
			DisplayError("Unable to determine filename of '" . $srcfile . "'.", $result);

			return;
		}

		if (count($args["params"]) == 1)  $destfile = $filename;
		else if (substr($args["params"][1], -1) == "/")  $destfile = $args["params"][1] . $filename;
		else if ($args["params"][1] == ".")  $destfile = $filename;
		else  $destfile = $args["params"][1];

		$result = $efss->copy($srcfile, $destfile, EFSS_COPYMODE_REAL_DEST);
		if (!$result["success"])
		{
			DisplayError("Unable to export '" . $srcfile . "' to '" . $destfile . "'.", $result);

			return;
		}
	}

	function shell_cmd_cp($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Copy file command\n";
			echo "Purpose:  Copy a file to the destination.  Only copies one file.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] srcfile destpathfile\n";
			echo "Options:\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " donate.txt test_donate.txt\n";

			return;
		}

		$srcfile = str_replace("\\", "/", $args["params"][0]);

		$pos = strrpos($srcfile, "/");
		if ($pos === false)  $filename = $srcfile;
		else  $filename = (string)substr($srcfile, $pos + 1);
		if ($filename == "")
		{
			DisplayError("Unable to determine filename of '" . $srcfile . "'.", $result);

			return;
		}

		if (substr($args["params"][1], -1) == "/")  $destfile = $args["params"][1] . $filename;
		else if ($args["params"][1] == ".")  $destfile = $filename;
		else  $destfile = $args["params"][1];

		$result = $efss->copy($srcfile, $destfile);
		if (!$result["success"])
		{
			DisplayError("Unable to copy '" . $srcfile . "' to '" . $destfile . "'.", $result);

			return;
		}
	}

	function shell_cmd_copy($line)
	{
		shell_cmd_cp($line);
	}

	function shell_cmd_mv($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Move path command\n";
			echo "Purpose:  Move a path to the destination.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] srcpathfile destpathfile\n";
			echo "Options:\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " donate.txt test_donate.txt\n";

			return;
		}

		$srcpath = str_replace("\\", "/", $args["params"][0]);

		$pos = strrpos($srcpath, "/");
		if ($pos === false)  $filename = $srcpath;
		else  $filename = (string)substr($srcpath, $pos + 1);
		if ($filename == "")
		{
			DisplayError("Unable to determine source name of '" . $srcpath . "'.", $result);

			return;
		}

		if (substr($args["params"][1], -1) == "/")  $destpath = $args["params"][1] . $filename;
		else if ($args["params"][1] == ".")  $destpath = $filename;
		else  $destpath = $args["params"][1];

		$result = $efss->rename($srcpath, $destpath);
		if (!$result["success"])
		{
			DisplayError("Unable to move '" . $srcpath . "' to '" . $destpath . "'.", $result);

			return;
		}
	}

	function shell_cmd_move($line)
	{
		shell_cmd_mv($line);
	}

	function shell_cmd_ren($line)
	{
		shell_cmd_mv($line);
	}

	function shell_cmd_rename($line)
	{
		shell_cmd_mv($line);
	}

	function shell_cmd_rm($line)
	{
		global $efss;

		if (is_array($line))  $args = $line;
		else
		{
			$options = array(
				"shortmap" => array(
					"f" => "force",
					"R" => "regex",
					"r" => "recursive",
					"?" => "help"
				),
				"rules" => array(
					"force" => array("arg" => false),
					"regex" => array("arg" => true, "multiple" => true),
					"recursive" => array("arg" => false),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, $line);
		}

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Remove command\n";
			echo "Purpose:  Removes files, symlinks, and subdirectories.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] path\n";
			echo "Options:\n";
			echo "\t-R=regex   Regular expression match.\n";
			echo "\t-r         Recursive removal.\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " -r /etc/\n";

			return;
		}

		$path = $args["params"][0];

		$processdir = isset($args["opts"]["regex"]);
		if (!$processdir)  $args["opts"]["regex"] = array('/.*/');
		if ($efss->GetDirMode() & EFSS_DIRMODE_CASE_INSENSITIVE)
		{
			foreach ($args["opts"]["regex"] as $num => $pattern)  $args["opts"]["regex"][$num] = $pattern . "i";
		}

		$result = $efss->filetype($path);
		if (!$result["success"])
		{
			DisplayError("Unable to determine type for '" . $path . "'.", $result);

			return;
		}
		$type = $result["type"];
		if ($type == "unknown")
		{
			DisplayError("Unable to determine type for '" . $path . "'.");

			return;
		}
		$name = $result["name"];

		if ($type == "file" || $type == "link")
		{
			foreach ($args["opts"]["regex"] as $pattern)
			{
				if (preg_match($pattern, $name))
				{
					$result = $efss->unlink($path);
					if (!$result["success"])
					{
						DisplayError("Unable to remove '" . $path . "'.", $result);

						return;
					}

					break;
				}
			}
		}
		else
		{
			$recursive = isset($args["opts"]["recursive"]);

			if ($processdir || $recursive)
			{
				$regexes = $args["opts"]["regex"];
				if (!$recursive)  unset($args["opts"]["regex"]);

				$result = $efss->opendir($path);
				if (!$result["success"])
				{
					DisplayError("Unable to open directory '" . $path . "'.", $result);

					return;
				}
				$dir = $result["dir"];
				$path = $result["path"];

				$result = $efss->readdir($dir);
				while ($result["success"])
				{
					$name = $result["name"];
					$args["params"][0] = ($path == "/" ? "/" : $path . "/") . $name;

					if ($recursive)  shell_cmd_rm($args);

					foreach ($regexes as $pattern)
					{
						if (preg_match($pattern, $name))
						{
							if ($result["info"]->type == EFSS_DIRTYPE_DIR)
							{
								if ($recursive)  $efss->rmdir($args["params"][0]);
							}
							else if (!$recursive)
							{
								$result = $efss->unlink($args["params"][0]);
								if (!$result["success"])
								{
									DisplayError("Unable to remove '" . $path . "'.", $result);

									return;
								}
							}

							break;
						}
					}

					$result = $efss->readdir($dir);
				}

				if ($result["errorcode"] != "dir_end")
				{
					DisplayError("Unable to read directory entry.", $result);

					return;
				}

				$efss->closedir($dir);
			}
			else
			{
				DisplayError("Unable to remove directory.  Use 'rmdir' or 'rd' instead.");

				return;
			}
		}
	}

	function shell_cmd_del($line)
	{
		shell_cmd_rm($line);
	}

	function shell_cmd_cat($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Display file command\n";
			echo "Purpose:  Display the contents of a file.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] filename\n";
			echo "Options:\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " somefile.txt\n";

			return;
		}

		$filename = $args["params"][0];

		$result = $efss->readfile($filename);
		if (!$result["success"])
		{
			DisplayError("Unable to read '" . $filename . "'.", $result);

			return;
		}

		echo "\n";
	}

	function shell_cmd_type($line)
	{
		shell_cmd_cat($line);
	}

	function shell_cmd_grep($line)
	{
		global $efss;

		if (is_array($line))  $args = $line;
		else
		{
			$options = array(
				"shortmap" => array(
					"n" => "linenums",
					"i" => "insensitive",
					"r" => "recursive",
					"f" => "regex",
					"?" => "help"
				),
				"rules" => array(
					"linenums" => array("arg" => false),
					"insensitive" => array("arg" => false),
					"recursive" => array("arg" => false),
					"regex" => array("arg" => true, "multiple" => true),
					"help" => array("arg" => false)
				)
			);
			$args = CLI::ParseCommandLine($options, $line);
		}

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Find command\n";
			echo "Purpose:  Finds a pattern in a file.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] pattern path\n";
			echo "Options:\n";
			echo "\t-n         Display line numbers of matches.\n";
			echo "\t-i         Case insensitive pattern.\n";
			echo "\t-r         Recursive search.\n";
			echo "\t-f=regex   Regular expression file match.\n";
			echo "\t-?         This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " -nir -f=/[.]txt\$/ \"Donate\" /\n";

			return;
		}

		$search = $args["params"][0];
		$path = $args["params"][1];

		if (substr($search, 0, 1) != "/")  $search = "/" . $search;
		if (substr($search, -1) != "/")  $search .= "/";
		if (isset($args["opts"]["insensitive"]))  $search .= "i";

		if (!isset($args["opts"]["regex"]))  $args["opts"]["regex"] = array('/.*/');
		if ($efss->GetDirMode() & EFSS_DIRMODE_CASE_INSENSITIVE)
		{
			foreach ($args["opts"]["regex"] as $num => $pattern)  $args["opts"]["regex"][$num] = $pattern . "i";
		}

		$result = $efss->filetype($path);
		if (!$result["success"])
		{
			DisplayError("Unable to determine type for '" . $path . "'.", $result);

			return;
		}
		$type = $result["type"];
		if ($type == "unknown")
		{
			DisplayError("Unable to determine type for '" . $path . "'.");

			return;
		}
		$name = $result["name"];

		if ($type == "file")
		{
			$showlinenums = isset($args["opts"]["linenums"]);

			foreach ($args["opts"]["regex"] as $pattern)
			{
				if (preg_match($pattern, $name))
				{
					$result = $efss->fopen($path, "rb");
					if (!$result["success"])
					{
						DisplayError("Unable to open '" . $path . "'.", $result);

						return;
					}
					$fp = $result["fp"];

					$num = 1;
					$result = $efss->fgets($fp, FILE_IGNORE_NEW_LINES);
					while ($result["success"])
					{
						if (preg_match($search, $result["data"]))  echo $path . ":" . ($showlinenums ? $num . ":" : "") . $result["data"] . "\n";

						$num++;
						$result = $efss->fgets($fp, FILE_IGNORE_NEW_LINES);
					}

					$efss->fclose($fp);

					if ($result["errorcode"] != "eof")  return $result;

					break;
				}
			}
		}
		else if ($type == "link")
		{
		}
		else
		{
			$recursive = isset($args["opts"]["recursive"]);

			$result = $efss->opendir($path);
			if (!$result["success"])
			{
				DisplayError("Unable to open directory '" . $path . "'.", $result);

				return;
			}
			$dir = $result["dir"];
			$path = $result["path"];

			$result = $efss->readdir($dir);
			while ($result["success"])
			{
				if ($recursive || $result["info"]->type == EFSS_DIRTYPE_FILE)
				{
					$name = $result["name"];
					$args["params"][1] = ($path == "/" ? "/" : $path . "/") . $name;
					shell_cmd_grep($args);
				}

				$result = $efss->readdir($dir);
			}

			if ($result["errorcode"] != "dir_end")
			{
				DisplayError("Unable to read directory entry.", $result);

				return;
			}

			$efss->closedir($dir);
		}
	}

	function shell_cmd_findstr($line)
	{
		shell_cmd_grep($line);
	}

	function shell_cmd_ln($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"o" => "owner",
				"g" => "group",
				"t" => "ts",
				"?" => "help"
			),
			"rules" => array(
				"owner" => array("arg" => true),
				"group" => array("arg" => true),
				"ts" => array("arg" => true),
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Create symlink command\n";
			echo "Purpose:  Create a new symlink.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] targetpath linkpath\n";
			echo "Options:\n";
			echo "\t-o=owner       The owner of the symlink.\n";
			echo "\t-g=group       The group of the symlink.\n";
			echo "\t-t=timestamp   The local timestamp of the symlink.\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " /var/stuff /etc/stufflink\n";

			return;
		}

		$owner = (isset($args["opts"]["owner"]) ? $args["opts"]["owner"] : false);
		$group = (isset($args["opts"]["group"]) ? $args["opts"]["group"] : false);
		$created = (isset($args["opts"]["ts"]) ? EFSS::ConvertFromLocalDateTime($args["opts"]["ts"]) : false);

		$target = $args["params"][0];
		$link = $args["params"][1];

		$result = $efss->symlink($target, $link, $owner, $group, $created);
		if (!$result["success"])
		{
			DisplayError("Unable to create symlink '" . $link . "'.", $result);

			return;
		}
	}

	function shell_cmd_symlink($line)
	{
		shell_cmd_ln($line);
	}

	function shell_cmd_touch($line)
	{
		global $efss;

		$options = array(
			"shortmap" => array(
				"t" => "ts",
				"?" => "help"
			),
			"rules" => array(
				"ts" => array("arg" => true),
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Touch command\n";
			echo "Purpose:  Modify the timestamp of a file, directory, or symlink.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] path\n";
			echo "Options:\n";
			echo "\t-t=timestamp   The local timestamp to use.\n";
			echo "\t-?             This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " somefile.txt\n";

			return;
		}

		$created = (isset($args["opts"]["ts"]) ? EFSS::ConvertFromLocalDateTime($args["opts"]["ts"]) : false);

		$path = $args["params"][0];

		$result = $efss->touch($path, $created);
		if (!$result["success"])
		{
			DisplayError("Unable to modify the timestamp of '" . $path . "'.", $result);

			return;
		}
	}

	function CheckFS_DisplayProgress($blocksprocessed, $totalblocks, $startts)
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

				echo ", " . EFSS::TimeElapsedToString($timeleft) . " left";
			}
		}
		echo "\n";
	}

	function CheckFS_BlocksProcessedCallback($blocksprocessed, $totalblocks, $startts)
	{
		global $basets;

		if ($basets === false)  $basets = $startts;
		if (microtime(true) - $basets > 10.0)
		{
			CheckFS_DisplayProgress($blocksprocessed, $totalblocks, $startts);

			$basets = microtime(true);
		}
	}

	function CheckFS_FileProcessedCallback($name, $startts)
	{
		global $basets;

		if ($basets === false)  $basets = $startts;
		if (microtime(true) - $basets > 10.0)
		{
			echo "\t" . $name . "\n";

			$basets = microtime(true);
		}
	}

	function shell_cmd_fsck($line)
	{
		global $efss, $basets;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - File System Check\n";
			echo "Purpose:  Checks the mounted EFSS data store.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options]\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . "\n";

			return;
		}

		$basets = false;
		$result = $efss->CheckFS("CheckFS_BlocksProcessedCallback", "CheckFS_FileProcessedCallback");
		if (!$result["success"])
		{
			DisplayError("An error occurred while checking the mounted EFSS data store.", $result);

			return;
		}

		echo "Total time:  " . EFSS::TimeElapsedToString($result["endts"] - $result["startts"]) . "\n";
		echo "The EFSS data store passed all file system checks.\n\n";
	}

	function shell_cmd_chkdsk($line)
	{
		shell_cmd_fsck($line);
	}

	function Defrag_PathProcessedCallback($name, $fragments, $startts)
	{
		global $basets;

		if ($basets === false)  $basets = $startts;
		if (microtime(true) - $basets > 10.0)
		{
			echo "\t" . $name . "\n";
			echo "\tFragments so far:  " . $fragments . "\n";

			$basets = microtime(true);
		}
	}

	function shell_cmd_defrag($line)
	{
		global $efss, $basets;

		$options = array(
			"shortmap" => array(
				"r" => "recursive",
				"?" => "help"
			),
			"rules" => array(
				"recursive" => array("arg" => false),
				"help" => array("arg" => false)
			)
		);
		$args = CLI::ParseCommandLine($options, $line);

		if (count($args["params"]) > 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Defragment directory\n";
			echo "Purpose:  Defragments a directory structure.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] [path]\n";
			echo "Options:\n";
			echo "\t-r   Recursive scan.\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " -r /\n";

			return;
		}

		$path = (count($args["params"]) ? $args["params"][0] : "");

		$result = $efss->realpath($path);
		if (!$result["success"])
		{
			DisplayError("Unable to locate '" . $path . "'.", $result);

			return;
		}
		$path = $result["path"];

		$basets = false;
		$result = $efss->Defrag($path, isset($args["opts"]["recursive"]), "Defrag_PathProcessedCallback");
		if (!$result["success"])
		{
			DisplayError("An error occurred while defragmenting '" . $path . "'.", $result);

			return;
		}

		echo "Total time:  " . EFSS::TimeElapsedToString($result["endts"] - $result["startts"]) . "\n";
		echo "Total fragments:  " . $result["fragments"] . "\n";
		echo "Defragmentation of '" . $path . "' is done.\n\n";
	}

	function shell_cmd_help($line)
	{
		echo "help - List available shell functions\n";
		echo "\n";
		echo "Functions:\n";

		$result = get_defined_functions();
		if (isset($result["user"]))
		{
			sort($result["user"]);
			foreach ($result["user"] as $name)
			{
				if (strtolower(substr($name, 0, 10)) == "shell_cmd_")  echo "\t" . substr($name, 10) . "\n";
			}
		}

		echo "\n";
	}
?>