#!/usr/bin/env php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2024 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/cli_check.php');
include_once('./plugins/flowview/functions.php');
include_once('./plugins/flowview/setup.php');
include_once('./plugins/flowview/database.php');

flowview_connect();

ini_set('max_execution_time', '0');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$proceed = false;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '--proceed':
				$proceed = true;
				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit(0);
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit(0);
			default:
				print 'ERROR: Invalid Parameter ' . $parameter . PHP_EOL . PHP_EOL;
				display_help();
				exit(1);
		}
	}
}

if ($proceed == false) {
	print "WARNING: This utility is meant for development purposes only.  It will kill and cleanup parallel queries." . PHP_EOL;
	print "Use the --proceed option if you wish to do so.". PHP_EOL;
	exit(1);
}

if (read_config_option('flowview_use_arin') == 'on') {
	print "NOTE: Check for Unverified Arin Addresses" . PHP_EOL;
} else {
	print "WARNING: Arin Address Verification Disabled." . PHP_EOL;
	exit(1);
}

$time = time();
$ips  = flowview_db_fetch_assoc('SELECT *
	FROM plugin_flowview_dnscache
	WHERE arin_verified = 0');

if (cacti_sizeof($ips)) {
	foreach($ips as $p) {
		print "NOTE: Verifying Arin for IP Address:{$p['ip']} and DNS Name:{$p['host']}" . PHP_EOL;

		$arin_id  = 0;
		$arin_ver = 0;

		$data = flowview_get_owner_from_arin($p['ip']);

		if ($data !== false) {
			$arin_id  = $data['arin_id'];
			$arin_ver = 1;
		}

		if ($arin_ver == 1) {
			print "NOTE: Arin Verified for IP Address:{$p['ip']} and DNS Name:{$p['host']}" . PHP_EOL;
			/* return the hostname, without the trailing '.' */
			flowview_db_execute_prepared('UPDATE plugin_flowview_dnscache
				SET `arin_verified` = ?, `arin_id` = ?, `time` = ?
				WHERE `ip` = ?',
				array($arin_ver, $arin_id, $time, $p['ip']));
		} else {
			print "WARNING: Arin Not Verified for IP Address:{$p['ip']} and DNS Name:{$p['host']}" . PHP_EOL;
		}
	}
}

exit(0);

/*  display_version - displays version information */
function display_version() {
	$info    = plugin_flowview_version();
	$version = $info['version'];

	print "Cacti Flowview Arin Bulk Loader, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/*  display_help - displays the usage of the function */
function display_help () {
	display_version();

	print PHP_EOL . 'usage: flowview_bulkarin.php [--proceed]' . PHP_EOL . PHP_EOL;
	print 'A command line version of the Cacti Flowview Arin bulk loader.' . PHP_EOL;
	print 'To perform a bulk resolution of unverified Arin details,.' . PHP_EOL;
	print 'use the --proceed option.' . PHP_EOL . PHP_EOL;
}
