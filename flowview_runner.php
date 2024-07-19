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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
} else {
	declare(ticks = 100);
}

ini_set('output_buffering', 'Off');

chdir(__DIR__ . '/../..');
require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');

flowview_connect();

/* get the boost polling cycle */
$max_run_duration = read_config_option('flowview_query_max_runtime');

if (empty($max_run_duration)) {
	$max_run_duration = 60;
}

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$debug    = false;
$query_id = false;
$shard_id = false;

global $shard_id, $query_id;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
			case '--debug':
				$debug = true;

				break;
			case '--query-id':
				$query_id = $value;

				break;
			case '--shard-id':
				$shard_id = $value;

				break;
			case '--version':
			case '-V':
			case '-v':

				display_version();
				exit;
			case '--help':
			case '-H':
			case '-h':

				display_help();
				exit;
			default:
				print 'ERROR: Invalid Parameter ' . $parameter . PHP_EOL . PHP_EOL;

				display_help();
				exit(1);
		}
	}
}

/* install signal handlers for UNIX only */
if (function_exists('pcntl_signal')) {
	pcntl_signal(SIGTERM, 'sig_handler');
	pcntl_signal(SIGINT, 'sig_handler');
}

/* take time and log performance data */
$start = microtime(true);
$start_time = time();

db_debug(sprintf('Variables Processed for Query ID:%s and Shard ID:%s', $query_id, $shard_id));

/* let's give this script lot of time to run for ever */
ini_set('max_execution_time', '0');

if ($shard_id === false) {
	db_debug('About to start parent');

	/* we will warn if the process is taking extra long */
	if (!register_process_start('flowview', "db_query_{$query_id}", 0, 300)) {
		exit(0);
	}

	$current_time  = time();

	/**
	 * first get the number of threads from the database settings table
	 * then get the query information from the database.
	 */
	$threads = read_config_option('flowview_parallel_threads');

	$query   = flowview_db_fetch_row_prepared('SELECT *
		FROM parallel_database_query
		WHERE id = ?',
		array($query_id));

	$shards  = flowview_db_fetch_cell_prepared('SELECT COUNT(*)
		FROM parallel_database_query_shard
		WHERE query_id = ?',
		array($query_id));

	$tables = array_rekey(
		flowview_db_fetch_assoc_prepared('SELECT map_table
			FROM parallel_database_query_shard
			WHERE query_id = ?',
			array($query_id)),
		'map_table', 'map_table'
	);

	$map_range     = json_decode($query['map_range_params'], true);
	$cached_tables = array_rekey(
		flowview_db_fetch_assoc_prepared('SELECT map_table
			FROM parallel_database_query_shard_cache
			WHERE md5sum = ?
			AND min_date BETWEEN ? AND ?
			AND max_date BETWEEN ? AND ?',
			array(
				$query['md5sum_tables'],
				$map_range[0],
				$map_range[1],
				$map_range[0],
				$map_range[1],
			)
		),
		'map_table', 'map_table'
	);

	if (cacti_sizeof($tables)) {
		$total_size = flowview_db_fetch_cell('SELECT SUM(data_length+index_length)
			FROM information_schema.TABLES
			WHERE TABLE_NAME IN ("' . implode('","', $tables) . '")');
	} else {
		$total_size = 0;
	}

	if (cacti_sizeof($cached_tables)) {
		$cached_size = flowview_db_fetch_cell('SELECT SUM(data_length+index_length)
			FROM information_schema.TABLES
			WHERE TABLE_NAME IN ("' . implode('","', $cached_tables) . '")');
	} else {
		$cached_size = 0;
	}

	$total_size  /= 1000 * 1000 * 1000;
	$cached_size /= 1000 * 1000 * 1000;

	$running = 0;
	$start   = microtime(true);

	if (cacti_sizeof($query)) {
		$finished = $query['finished_shards'];
		$total    = $query['total_shards'];
		$table    = $query['map_table'];

		while(true) {
			$running = flowview_db_fetch_cell_prepared('SELECT COUNT(*)
                FROM parallel_database_query_shard
				WHERE query_id = ?
				AND status = "running"',
				array($query_id));

			flowview_launch_workers($query_id, $threads, $running);

			usleep(5000);

			$notfinished = flowview_db_fetch_cell_prepared('SELECT COUNT(*)
				FROM parallel_database_query_shard
				WHERE query_id = ?
				AND status != "finished"',
				array($query_id));

			if ($notfinished == 0) {
				break;
			}
		}

		$stru_outer = json_decode($query['reduce_query'], true);

		$reduce_query = $stru_outer['sql_query'];
		$reduce_query .= " FROM $table";
		$reduce_query .= (isset($stru_outer['sql_where'])   ? ' ' . $stru_outer['sql_where']:'');
		$reduce_query .= (isset($stru_outer['sql_groupby']) ? ' ' . $stru_outer['sql_groupby']:'');
		$reduce_query .= (isset($stru_outer['sql_having'])  ? ' ' . $stru_outer['sql_having']:'');
		$reduce_query .= (isset($stru_outer['sql_order'])   ? ' ' . $stru_outer['sql_order']:'');
		$reduce_query .= (isset($stru_outer['sql_limit'])   ? ' ' . $stru_outer['sql_limit']:'');

		$data = flowview_db_fetch_assoc_prepared($reduce_query, $stru_outer['sql_params']);

		flowview_db_execute_prepared('UPDATE parallel_database_query
			SET results = ?, status = ?
			WHERE id = ?',
			array(json_encode($data), 'complete', $query_id));

		flowview_db_execute_prepared('DELETE FROM parallel_database_query_shard
			WHERE query_id = ?',
			array($query_id));

		$cached = flowview_db_fetch_cell_prepared('SELECT cached_shards
			FROM parallel_database_query
			WHERE id = ?',
			array($query_id));

		flowview_db_execute("DROP TABLE $table");

		unregister_process('flowview', "db_query_{$query_id}", 0);

		db_debug("Query $query_id finished");

		$end = microtime(true);

		cacti_log(sprintf('PARALLEL STATS: Time:%0.3f Threads:%d Shards:%d Cached:%d TotalSize:%0.2fGB CachedSize:%0.2fGB', $end - $start, $threads, $shards, $cached, $total_size, $cached_size), false, 'FLOWVIEW');
	}
} else {
	/* we will warn if the process is taking extra long */
	if (!register_process_start('flowview', "db_shard_{$query_id}", $shard_id, 300)) {
		exit(0);
	}

	if (read_config_option('flowview_use_maxscale') == 'on') {
		$max_cnn = flowview_connect(true);
	} else {
		$max_cnn = false;
	}

	db_debug(sprintf('Starting Shard Query ID:%s and Shard ID:%s', $query_id, $shard_id));

	$query = flowview_db_fetch_row_prepared('SELECT *
		FROM parallel_database_query
		WHERE id = ?',
		array($query_id), false, $max_cnn);

	if (cacti_sizeof($query)) {
		$shard = flowview_db_fetch_row_prepared('SELECT *
			FROM parallel_database_query_shard
			WHERE query_id = ?
			AND shard_id = ?',
			array($query_id, $shard_id), false, $max_cnn);

		if (cacti_sizeof($shard)) {
			$table = $query['map_table'];

			flowview_db_execute_prepared('UPDATE parallel_database_query_shard
				SET status = ?
				WHERE query_id = ?
				AND shard_id = ?',
				array('running', $query_id, $shard_id));

			if ($shard['full_scan']) {
				$exists = flowview_db_fetch_row_prepared('SELECT *
					FROM parallel_database_query_shard_cache
					WHERE md5sum = ?
					AND map_table = ?
					AND map_partition = ?',
					array(
						$query['md5sum_tables'],
						$shard['map_table'],
						$shard['map_partition']
					)
				);
			} else {
				$exists = array();
			}

			if (!cacti_sizeof($exists)) {
				//cacti_log("The table is {$shard['map_query']}, the params are: {$shard['map_params']}");
				$results = flowview_db_fetch_assoc_prepared("{$shard['map_query']}", json_decode($shard['map_params']), false, $max_cnn);
			} else {
				flowview_db_execute_prepared('UPDATE parallel_database_query
					SET cached_shards = cached_shards + 1
					WHERE id = ?',
					array($query_id));

				$results = json_decode($exists['results'], true);
			}

			if ($shard['full_scan'] == 1 && !cacti_sizeof($exists)) {
				$details = flowview_db_fetch_row("SELECT MIN(start_time) AS min_date, MAX(end_time) AS max_date
					FROM {$shard['map_table']}");

				if (!cacti_sizeof($details)) {
					$details = array('min_date' => date('Y-m-d 00:00:00'), 'max_date' => date('Y-m-d 00:00:00'));
				}

				flowview_db_execute_prepared('INSERT INTO parallel_database_query_shard_cache
					(md5sum, map_table, map_partition, min_date, max_date, results)
					VALUES (?, ?, ?, ?, ?, ?)',
					array(
						$query['md5sum_tables'],
						$shard['map_table'],
						$shard['map_partition'],
						$details['min_date'],
						$details['max_date'],
						json_encode($results)
					)
				);
			}

			if (read_config_option('flowview_use_maxscale') == 'on') {
				if ($max_cnn !== false) {
					flowview_db_close($max_cnn);
				}
			}

			if (cacti_sizeof($results)) {
				$sql     = array();
				$columns = array_keys($results[0]);

				$sql_prefix = "INSERT INTO $table (";
				foreach($columns as $index => $column) {
					$sql_prefix .= ($index == 0 ? '':', ') . '`' . $column . '`';
				}

				$sql_prefix .= ') VALUES ';

				foreach($results as $row) {
					$sql_string = '';

					foreach($columns as $index => $column) {
						$sql_string .= ($index == 0 ? '':', ') . db_qstr($row[$column]);
					}

					$sql[] = '(' . $sql_string . ')';
				}

				/* insert entries into the intermediary table */
				flowview_db_execute($sql_prefix . implode(', ', $sql));
			}

			/* mark the worker as finished */
			flowview_db_execute_prepared('UPDATE parallel_database_query_shard
				SET status = ?
				WHERE query_id = ?
				AND shard_id = ?',
				array('finished', $query_id, $shard_id));
		} else {
			db_debug("Shard $shard_id Not Found");
		}
	} else {
		db_debug("Query $query_id Not Found");
	}

	unregister_process('flowview', "db_shard_{$query_id}", $shard_id);

	flowview_db_execute_prepared('UPDATE parallel_database_query
		SET finished_shards = finished_shards + 1
		WHERE id = ?', array($query_id));

	exit(0);
}

function sig_handler($signo) {
	global $shard_id, $query_id, $config, $current_lock;

	switch ($signo) {
		case SIGTERM:
		case SIGINT:
			cacti_log('WARNING: Flowview Database Parallel Poller terminated by user', false, 'BOOST');

			if ($shard_id) {
				unregister_process('flowview', "db_shard{$query_id}", $shard_id, getmypid());
			} else {
				unregister_process('flowview', "db_query{$query_id}", 0, getmypid());
			}

			exit;
			break;
		default:
			/* ignore all other signals */
	}
}

function flowview_launch_workers($query_id, $threads, $running) {
	global $config, $debug;

	$php_binary = read_config_option('path_php_binary');
	$launching  = $threads - $running;
	$pending    = flowview_db_fetch_cell_prepared('SELECT COUNT(*)
		FROM parallel_database_query_shard
		WHERE query_id = ?
		AND status = ?',
		array($query_id, 'pending'));

	$redirect   = '';

	if ($pending > 0 && $launching > 0) {
		db_debug("About to launch $launching processes.");

		while($launching > 0 && $pending > 0) {
			$shard_id = flowview_db_fetch_cell_prepared('SELECT shard_id
				FROM parallel_database_query_shard
				WHERE query_id = ?
				AND status = ?
				LIMIT 1',
				array($query_id, 'pending'));

			if ($shard_id >= 0 && $shard_id != '') {
				flowview_db_execute_prepared('UPDATE parallel_database_query_shard
					SET status = ?
					WHERE query_id = ?
					AND shard_id = ?',
					array('running', $query_id, $shard_id));

				db_debug('Launching FlowView Database Shard Process ' . $shard_id);

				//cacti_log('NOTE: Launching FlowView Database Shard Process ' . $shard_id, false, 'BOOST', POLLER_VERBOSITY_MEDIUM);

				exec_background($php_binary, $config['base_path'] . "/plugins/flowview/flowview_runner.php --query-id=$query_id --shard-id=$shard_id" . ($debug ? ' --debug':''), $redirect);
			} else {
				break;
			}

			$launching--;
			$pending--;
		}
	}

	usleep(5000);
}

/**
 * display_version - displays version information
 */
function display_version() {
	$version = get_cacti_version();
	print "Cacti Boost RRD Update Poller, Version $version " . COPYRIGHT_YEARS . "\n";
}

/**
 * display_help - displays the usage of the function
 */
function display_help () {
	display_version();

	print PHP_EOL;
	print 'usage: flowview_runner.php --query-id=N [--shard-id=N] [--debug]' . PHP_EOL . PHP_EOL;
	print 'FlowView Parallel Database Runner Script.  Provided a Database Query ID and a properly' . PHP_EOL;
	print 'registered query.  Run all the shards in parallel and then consolidate the results' . PHP_EOL;
	print 'for return to the user.' . PHP_EOL . PHP_EOL;
	print 'Optional:' . PHP_EOL;
	print '    --debug   - Display verbose output during execution' . PHP_EOL . PHP_EOL;
}

