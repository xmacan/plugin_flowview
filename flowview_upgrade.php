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

ini_set('max_execution_time', '0');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$forcever    = '';

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '--forcever':
				$forcever = $value;
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

/* we need to rerun the upgrade, force the current version */
if ($forcever == '') {
	$old_version = db_fetch_cell('SELECT version FROM plugin_config WHERE directory = "flowview"');
} else {
	$old_version = $forcever;
}

$info            = plugin_flowview_version();
$current_version = $info['version'];

/* do a version check */
if ($forcever == '' && $old_version == $current_version) {
	cacti_log('Your Flowview is already up to date (v' . $current_version . ' vs v' . $old_version . ') not upgrading.  Use --forcever to override', true, 'FLOWVIEW');
	exit;
}

cacti_log('Upgrading from v' . $old_version . ' to ' . $current_version, true, 'FLOWVIEW');

flowview_upgrade($current_version, $old_version);

function flowview_upgrade($current, $old) {
	global $flowviewdb_default;

	flowview_connect();

	if ($current != $old) {
		$bad_titles = flowview_db_fetch_cell('SELECT COUNT(*)
			FROM plugin_flowview_schedules
			WHERE title=""');

		if (!flowview_db_column_exists('plugin_flowview_devices', 'cmethod')) {
			cacti_log("Adding column cmethod to plugin_flowview_devices table.", true, 'FLOWVIEW');

			flowview_db_execute('ALTER TABLE plugin_flowview_devices ADD COLUMN cmethod int unsigned default "0" AFTER name');

			flowview_db_execute('UPDATE plugin_flowview_devices SET cmethod=1');
		}

		if (flowview_db_column_exists('plugin_flowview_devices', 'nesting')) {
			cacti_log("Removing nesting columns from plugin_flowview_devices table.", true, 'FLOWVIEW');

			flowview_db_execute('ALTER TABLE plugin_flowview_devices
				DROP COLUMN nesting,
				DROP COLUMN version,
				DROP COLUMN rotation,
				DROP COLUMN expire,
				DROP COLUMN compression'
			);
		}

		if (flowview_db_column_exists('plugin_flowview_schedules', 'savedquery')) {
			cacti_log("Adding savedquery column to plugin_flowview_schedules table.", true, 'FLOWVIEW');

			flowview_db_execute('ALTER TABLE plugin_flowview_schedules CHANGE COLUMN savedquery query_id INT unsigned NOT NULL default "0"');
		}

		if (!flowview_db_column_exists('plugin_flowview_schedules', 'format_file')) {
			cacti_log("Adding format_file column to plugin_flowview_schedules table.", true, 'FLOWVIEW');

			flowview_db_execute('ALTER TABLE plugin_flowview_schedules ADD COLUMN format_file VARCHAR(128) DEFAULT "" AFTER email');
		}

		flowview_db_execute('DROP TABLE IF EXISTS plugin_flowview_session_cache');
		flowview_db_execute('DROP TABLE IF EXISTS plugin_flowview_session_cache_flow_stats');
		flowview_db_execute('DROP TABLE IF EXISTS plugin_flowview_session_cache_details');

		flowview_db_execute('ALTER TABLE plugin_flowview_queries MODIFY COLUMN protocols varchar(32) default ""');

		if (!flowview_db_column_exists('plugin_flowview_queries', 'device_id')) {
			cacti_log("Adding device_id column to plugin_flowview_queries table.", true, 'FLOWVIEW');

			flowview_db_execute('ALTER TABLE plugin_flowview_queries ADD COLUMN device_id int unsigned NOT NULL default "0" AFTER name');
		}

		flowview_db_execute("CREATE TABLE IF NOT EXISTS `" . $flowviewdb_default . "`.`plugin_flowview_arin_information` (
			`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`cidr` varchar(20) NOT NULL DEFAULT '',
			`net_range` varchar(64) NOT NULL DEFAULT '',
			`name` varchar(64) NOT NULL DEFAULT '',
			`parent` varchar(64) NOT NULL DEFAULT '',
			`net_type` varchar(64) NOT NULL DEFAULT '',
			`origin_as` varchar(64) NOT NULL DEFAULT '',
			`registration` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
			`last_changed` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
			`comments` varchar(128) NOT NULL DEFAULT '',
			`self` varchar(128) NOT NULL DEFAULT '',
			`alternate` varchar(128) NOT NULL DEFAULT '',
			`json_data` blob NOT NULL DEFAULT '',
			PRIMARY KEY (`id`),
			UNIQUE KEY `cidr` (`cidr`))
			ENGINE=InnoDB
			ROW_FORMAT=DYNAMIC
			COMMENT='Holds ARIN Records Downloaded for Caching'");

		if (!flowview_db_column_exists('plugin_flowview_queries', 'graph_type')) {
			cacti_log("Adding charting columns to the plugin_flowview_queries table.", true, 'FLOWVIEW');

			flowview_db_execute("ALTER TABLE plugin_flowview_queries
				ADD COLUMN graph_type varchar(10) NOT NULL default 'bar' AFTER resolve,
				ADD COLUMN graph_height int unsigned NOT NULL default '400' AFTER graph_type,
				ADD COLUMN panel_table char(2) NOT NULL default 'on' AFTER graph_height,
				ADD COLUMN panel_bytes char(2) NOT NULL default 'on' AFTER panel_table,
				ADD COLUMN panel_packets char(2) NOT NULL default 'on' AFTER panel_bytes,
				ADD COLUMN panel_flows char(2) NOT NULL default 'on' AFTER panel_packets");
		}

		if (!flowview_db_column_exists('plugin_flowview_dnscache', 'id')) {
			flowview_db_execute('ALTER TABLE plugin_flowview_dnscache ADD COLUMN id int(11) unsigned AUTO_INCREMENT FIRST,
				DROP PRIMARY KEY,
				ADD PRIMARY KEY(id),
				ADD UNIQUE KEY ip(ip),
				ADD COLUMN source VARCHAR(40) NOT NULL default "" AFTER host');
		}

		if ($bad_titles) {
			cacti_log("Fixing Bad Titles in the plugin_flowview_schedules table.", true, 'FLOWVIEW');

			/* update titles for those that don't have them */
			flowview_db_execute("UPDATE plugin_flowview_schedules SET title='Ugraded Schedule' WHERE title=''");

			/* Set the new version */
			db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('plugin_flowview_version', ?)", array($current));

			flowview_db_execute('ALTER TABLE plugin_flowview_devices ENGINE=InnoDB');
		}

		flowview_db_execute("CREATE TABLE IF NOT EXISTS `" . $flowviewdb_default . "`.`plugin_flowview_device_streams` (
			device_id int(11) unsigned NOT NULL default '0',
			ext_addr varchar(32) NOT NULL default '',
			name varchar(64) NOT NULL default '',
			version varchar(5) NOT NULL default '',
			last_updated timestamp NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (device_id, ext_addr))
			ENGINE=InnoDB,
			ROW_FORMAT=DYNAMIC,
			COMMENT='Plugin Flowview - List of Streams coming into each of the listeners'");

		flowview_db_execute("CREATE TABLE IF NOT EXISTS `" . $flowviewdb_default . "`.`plugin_flowview_device_templates` (
			device_id int(11) unsigned NOT NULL default '0',
			ext_addr varchar(32) NOT NULL default '',
			template_id int(11) unsigned NOT NULL default '0',
			supported tinyint unsigned NOT NULL default '0'
			column_spec blob default '',
			last_updated timestamp NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (device_id, ext_addr, template_id))
			ENGINE=InnoDB,
			ROW_FORMAT=DYNAMIC,
			COMMENT='Plugin Flowview - List of Stream Templates coming into each of the listeners'");

		if (!flowview_db_column_exists('plugin_flowview_deivce_templates', 'supported')) {
			flowview_db_execute('ALTER TABLE plugin_flowview_deivce_templates
				ADD COLUMN supported tinyint unsigned NOT NULL default "0" AFTER template_id');
		}

		if (!flowview_db_column_exists('plugin_flowview_deivces', 'last_updated')) {
			flowview_db_execute('ALTER TABLE plugin_flowview_devices ADD COLUMN last_updated TIMESTAMP NOT NULL default CURRENT_TIMESTAMP');
		}

		db_execute("UPDATE plugin_realms
			SET file='flowview_devices.php,flowview_schedules.php,flowview_filters.php,flowview_dnscache.php'
			WHERE plugin='flowview'
			AND file LIKE '%devices%'");

		$raw_tables = flowview_db_fetch_assoc('SELECT TABLE_NAME, TABLE_COLLATION
			FROM information_schema.TABLES
			WHERE TABLE_NAME LIKE "plugin_flowview_raw_%"');

		foreach($raw_tables as $t) {
			$alter = '';

			if ($t['TABLE_COLLATION'] != 'latin1_swedish_ci') {
				$alter = 'CONVERT TO CHARACTER SET latin1';
			}

			if (flowview_db_column_exists($t['TABLE_NAME'], 'keycol')) {
				$alter .= ($alter != '' ? ', ':'') . 'DROP KEY keycol';
			}

			if (!flowview_db_column_exists($t['TABLE_NAME'], 'end_time')) {
				$alter .= ($alter != '' ? ', ':'') . 'ADD INDEX end_time (end_time)';
			}

			if (!flowview_db_column_exists($t['TABLE_NAME'], 'ex_addr')) {
				$alter .= ($alter != '' ? ', ':'') . 'ADD INDEX ex_addr (ex_addr)';
			}

			if ($alter != '') {
				cacti_log("Altering Table {$t['TABLE_NAME']} Using this alter $alter.", true, 'FLOWVIEW');

				flowview_db_execute('ALTER TABLE ' . $t['TABLE_NAME'] . ' ' . $alter);
			}
		}

		db_execute_prepared("UPDATE plugin_config SET
			version = ?, name = ?, author = ?, webpage = ?
			WHERE directory = ?",
			array(
				$info['version'],
				$info['longname'],
				$info['author'],
				$info['homepage'],
				$info['name']
			)
		);

		cacti_log('Flowview Database Upgrade Complete', true, 'FLOWVIEW');
	}
}

/*  display_version - displays version information */
function display_version() {
	$info    = plugin_flowview_version();
	$version = $info['version'];

	print "Cacti Flowview Database Upgrade Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/*  display_help - displays the usage of the function */
function display_help () {
	display_version();

	print PHP_EOL . 'usage: flowview_database.php [--forcever=VERSION]' . PHP_EOL . PHP_EOL;
	print 'A command line version of the Cacti Flowview database upgrade tool.  You must execute' . PHP_EOL;
	print 'this command as a super user, or someone who can write a PHP session file.' . PHP_EOL;
	print 'Typically, this user account will be apache, www-run, or root.' . PHP_EOL . PHP_EOL;
	print 'If you are running a beta or alpha version of Cacti and need to rerun' . PHP_EOL;
	print 'the upgrade script, simply set the forcever to the previous release.' . PHP_EOL . PHP_EOL;
	print '--forcever - Force the starting version, say ' . CACTI_VERSION . PHP_EOL;
}
