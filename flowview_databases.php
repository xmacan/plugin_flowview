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

chdir('../../');
include('./include/auth.php');
include('./plugins/flowview/functions.php');
include('./plugins/flowview/database.php');

flowview_connect();

$actions = array(
	1 => __('Delete', 'flowview'),
);

/* set default action */
set_default_action();

switch (get_request_var('action')) {
	case 'actions':
		form_actions();

		break;
	case 'purge':
		flowview_db_execute('TRUNCATE plugin_flowview_dnscache');
		raise_message('flowview_dns_purge', __('DNS Cache has been purged.  It will refill as records come in.', 'flowview'), MESSAGE_LEVEL_INFO);
	default:
		top_header();

		view_databases();

		bottom_footer();
		break;
}

function view_databases() {
	global $actions, $item_rows;

	if (!isset_request_var('tab')) {
		if (isset($_SESSION['sess_fv_db_tab'])) {
			set_request_var('tab', $_SESSION['sess_fv_db_tab']);
		} else {
			set_request_var('tab', 'dns_cache');
		}
	}

	$_SESSION['sess_fv_db_tab'] = get_request_var('tab');

	display_db_tabs();

	if (get_request_var('tab') == 'dns_cache') {
		view_dns_cache();
	} elseif (get_request_var('tab') == 'routes') {
		view_routes();
	} elseif (get_request_var('tab') == 'asn') {
		print "Under Construction";
	}
}

function form_actions() {
	global $actions;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));
	/* ==================================================== */

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_nfilter_request_var('drp_action') == '1') { /* delete */
				flowview_db_execute('DELETE FROM plugin_flowview_dnscache WHERE ' . array_to_sql_or($selected_items, 'id'));
			}
		}

		header('Location: flowview_databases.php?tab=dns_cache&header=false');
		exit;
	}

	/* setup some variables */
	$dns_list = '';
	$dns_array = array();

	/* loop through each of the graphs selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$dns_list .= '<li>' . html_escape(flowview_db_fetch_cell_prepared('SELECT CONCAT(host, "(", ip, ")") AS name FROM plugin_flowview_dnscache WHERE id = ?', array($matches[1]))) . '</li>';
			$dns_array[] = $matches[1];
		}
	}

	top_header();

	form_start('flowview_databases.php?tab=dns_cache');

	html_start_box($actions[get_nfilter_request_var('drp_action')], '60%', '', '3', 'center', '');

	if (isset($dns_array) && cacti_sizeof($dns_array)) {
		if (get_nfilter_request_var('drp_action') == '1') { /* delete */
			print "<tr>
				<td class='textArea'>
					<p>" . __n('Click \'Continue\' to delete the following DNS Cache Entriy.', 'Click \'Continue\' to delete all following DNS Cache Entries.', cacti_sizeof($dns_array), 'flowview') . "</p>
					<div class='itemlist'><ul>$dns_list</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget' value='" . __esc('Cancel', 'flowview') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue', 'flowview') . "' title='" . __n('Delete DNS Entry', 'Delete DNS Entries', cacti_sizeof($dns_array), 'flowview') . "'>";
		}
	} else {
		raise_message(40);
		header('Location: flowview_databases.php?tab=dns_cache&header=false');
		exit;
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($dns_array) ? serialize($dns_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . html_escape(get_nfilter_request_var('drp_action')) . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function view_dns_cache() {
	global $actions, $item_rows;

	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
		),
		'filter' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		),
		'source' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => '-1'
		),
		'verified' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => '-1'
		),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'host',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
		)
	);

	validate_store_request_vars($filters, 'sess_fv_dnscache');
	/* ================= input validation ================= */

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}

	html_start_box(__('Flowview DNS Cache Entries', 'flowview'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='form' action='flowview_databases.php&tab=dns_cache'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'flowview');?>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' name='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Verified', 'flowview');?>
					</td>
					<td>
						<select id='verified' name='verified' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('verified') == '-1' ? ' selected>':'>') . __('Any', 'flowview');?></option>
							<option value='0'<?php print (get_request_var('verified') == '0' ? ' selected>':'>') . __('Unverified', 'flowview');?></option>
							<option value='1'<?php print (get_request_var('verified') == '1' ? ' selected>':'>') . __('Verified', 'flowview');?></option>
						</select>
					</td>
					<td>
						<?php print __('Source', 'flowview');?>
					</td>
					<td>
						<select id='source' name='source' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('source') == '-1' ? ' selected>':'>') . __('Any', 'flowview');?></option>
							<?php
							$sources = array_rekey(
								flowview_db_fetch_assoc('SELECT DISTINCT source
									FROM plugin_flowview_dnscache
									ORDER BY source'),
								'source', 'source'
							);

							if (cacti_sizeof($sources) > 0) {
								foreach ($sources as $key => $value) {
									print "<option value='" . $key . "'" . (get_request_var('source') == $key ? ' selected':'') . '>' . html_escape($value) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Entries', 'flowview');?>
					</td>
					<td>
						<select id='rows' name='rows' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default', 'flowview');?></option>
							<?php
							if (cacti_sizeof($item_rows) > 0) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'" . (get_request_var('rows') == $key ? ' selected':'') . '>' . html_escape($value) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='refresh' value='<?php print __esc('Go', 'flowview');?>' title='<?php print __esc('Set/Refresh Filters', 'flowview');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc('Clear', 'flowview');?>' title='<?php print __esc('Clear Filters', 'flowview');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='purge' value='<?php print __esc('Purge', 'flowview');?>' title='<?php print __esc('Purge the DNS Cache', 'flowview');?>'>
						</span>
					</td>
				</tr>
			</table>
			</form>
			<script type='text/javascript'>

			function applyFilter() {
				strURL  = 'flowview_databases.php?header=false';
				strURL += '&tab=dns_cache';
				strURL += '&filter='+$('#filter').val();
				strURL += '&source='+$('#source').val();
				strURL += '&verified='+$('#verified').val();
				strURL += '&rows='+$('#rows').val();
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'flowview_databases.php?tab=dns_cache&clear=1&header=false';
				loadPageNoHeader(strURL);
			}

			function purgeFilter() {
				strURL = 'flowview_databases.php?tab=dns_cache&action=purge&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#purge').click(function() {
					purgeFilter();
				});

				$('#form').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});

			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	$sql_where = '';

	/* form the 'where' clause for our main sql query */
	if (get_request_var('filter') != '') {
		$sql_where = 'WHERE (host LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR ip LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR source LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	if (get_request_var('source') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND ':'WHERE ') . ' source = ' . db_qstr(get_request_var('source'));
	}

	if (get_request_var('verified') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND ':'WHERE ') . ' arin_verified = ' . db_qstr(get_request_var('verified'));
	}

	$total_rows = flowview_db_fetch_cell("SELECT COUNT(*)
		FROM plugin_flowview_dnscache AS dc
		LEFT JOIN plugin_flowview_arin_information AS ai
		ON dc.arin_id = ai.id
		$sql_where");

	$sql_order = get_order_string();

	/* sort naturally if the IP is in the sort */
	if (strpos($sql_order, 'ip ') !== false) {
		$sql_order = str_replace('ip ', 'NATURAL_SORT_KEY(ip) ', $sql_order);
	}

	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	$dns_cache = flowview_db_fetch_assoc("SELECT dc.*, ai.origin
		FROM plugin_flowview_dnscache AS dc
		LEFT JOIN plugin_flowview_arin_information AS ai
		ON dc.arin_id = ai.id
		$sql_where
		$sql_order
		$sql_limit");

	$nav = html_nav_bar('flowview_databases.php?tab=dns_cache&filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 8, __('Entries', 'flowview'), 'page', 'main');

	form_start('flowview_databases.php?tab=dns_cache', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'ip' => array(
			'display' => __('IP Address', 'flowview'),
			'align'   => 'left',
			'tip'     => __('This is the IP Address of the Cache entry.', 'flowview')
		),
		'host' => array(
			'display' => __('DNS Hostname', 'flowview'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('The DNS Name assigned to the IP Address.', 'flowview')
		),
		'source' => array(
			'display' => __('Source', 'flowview'),
			'align'   => 'left',
			'sort'    => 'DESC',
			'tip'     => __('The source of the DNS Hostname.  It can either be DNS, Static Lookup or ARIN.', 'flowview')
		),
		'arin_verified' => array(
			'display' => __('Arin Verified', 'flowview'),
			'align'   => 'left',
			'sort'    => 'DESC',
			'tip'     => __('The Arin information for this IP Address is verified, or it\'s a Local Domain IP Address.', 'flowview')
		),
		'arin_id' => array(
			'display' => __('CIDR ID', 'flowview'),
			'align'   => 'right',
			'sort'    => 'DESC',
			'tip'     => __('The Arin primary key.  This is not official Arin information.', 'flowview')
		),
		'origin' => array(
			'display' => __('Autonomous System ID', 'flowview'),
			'align'   => 'right',
			'sort'    => 'DESC',
			'tip'     => __('The Arin Origin AS.  This is official Arin AS information.', 'flowview')
		),
		'time' => array(
			'display' => __('Updated Time', 'flowview'),
			'align'   => 'right',
			'sort'    => 'DESC',
			'tip'     => __('This is the time that the DNS cache was entered or last updated.', 'flowview')
		)
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'flowview_databases.php?tab=dns_cache');

	$i = 0;
	if (cacti_sizeof($dns_cache)) {
		foreach ($dns_cache as $l) {
			form_alternate_row('line' . $l['id'], false);
			form_selectable_cell(filter_value($l['ip'], get_request_var('filter')), $l['id']);
			form_selectable_cell(filter_value($l['host'], get_request_var('filter')), $l['id']);
			form_selectable_cell($l['source'], $l['id']);
			form_selectable_cell($l['arin_verified'] == 1 ? __('Verified', 'flowview'):__('Unverified', 'flowview'), $l['id']);
			form_selectable_cell($l['arin_id'], $l['id'], '', 'right');
			form_selectable_cell($l['origin'], $l['id'], '', 'right');
			form_selectable_cell(date('Y-m-d H:i:s', $l['time']), $l['id'], '', 'right');
			form_checkbox_cell($l['host'], $l['id']);
			form_end_row();
		}
	} else {
		print "<tr class='tableRow'><td colspan='" . (cacti_sizeof($display_text)+1) . "'><em>" . __('No DNS Cache Entries Found', 'flowview') . "</em></td></tr>\n";
	}

	html_end_box(false);

	if (cacti_sizeof($dns_cache)) {
		print $nav;
	}

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($actions);

	form_end();
}

function view_routes() {
	global $actions, $item_rows;

	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
		),
		'filter' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		),
		'version' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '-1'
		),
		'source' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => '-1'
		),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'route',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
		)
	);

	validate_store_request_vars($filters, 'sess_fv_routes');
	/* ================= input validation ================= */

	if (get_request_var('rows') == '-1' || isempty_request_var('rows')) {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}

	html_start_box(__('Flowview Internet Routes', 'flowview'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='form' action='flowview_databases.php?tab=routes'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'flowview');?>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' name='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('IP Version', 'flowview');?>
					</td>
					<td>
						<select id='version' name='verified' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('version') == '-1' ? ' selected>':'>') . __('Any', 'flowview');?></option>
							<option value='0'<?php print (get_request_var('version') == '0' ? ' selected>':'>') . __('IPv4', 'flowview');?></option>
							<option value='1'<?php print (get_request_var('version') == '1' ? ' selected>':'>') . __('IPv6', 'flowview');?></option>
						</select>
					</td>
					<td>
						<?php print __('Source', 'flowview');?>
					</td>
					<td>
						<select id='source' name='source' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('source') == '-1' ? ' selected>':'>') . __('Any', 'flowview');?></option>
							<?php
							$sources = array_rekey(
								flowview_db_fetch_assoc('SELECT DISTINCT source
									FROM plugin_flowview_irr_route
									ORDER BY source'),
								'source', 'source'
							);

							if (cacti_sizeof($sources)) {
								foreach ($sources as $key => $value) {
									print "<option value='" . $key . "'" . (get_request_var('source') == $key ? ' selected':'') . '>' . html_escape($value) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Entries', 'flowview');?>
					</td>
					<td>
						<select id='rows' name='rows' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default', 'flowview');?></option>
							<?php
							if (cacti_sizeof($item_rows) > 0) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'" . (get_request_var('rows') == $key ? ' selected':'') . '>' . html_escape($value) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='refresh' value='<?php print __esc('Go', 'flowview');?>' title='<?php print __esc('Set/Refresh Filters', 'flowview');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc('Clear', 'flowview');?>' title='<?php print __esc('Clear Filters', 'flowview');?>'>
						</span>
					</td>
				</tr>
			</table>
			</form>
			<script type='text/javascript'>

			function applyFilter() {
				strURL  = 'flowview_databases.php?header=false';
				strURL += '&tab=routes';
				strURL += '&filter='+$('#filter').val();
				strURL += '&version='+$('#version').val();
				strURL += '&source='+$('#source').val();
				strURL += '&verified='+$('#verified').val();
				strURL += '&rows='+$('#rows').val();
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'flowview_databases.php?tab=routes&clear=1&header=false';
				loadPageNoHeader(strURL);
			}

			function purgeFilter() {
				strURL = 'flowview_databases.php?tab=routes&action=purge&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#purge').click(function() {
					purgeFilter();
				});

				$('#form').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});

			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	$sql_where = '';

	/* form the 'where' clause for our main sql query */
	if (get_request_var('filter') != '') {
		$sql_where = 'WHERE (remarks LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR mnt_by LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR admin_c LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR tech_c LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR member_of LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR origin LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR status LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR route LIKE ' . db_qstr('%' . get_request_var('filter') . '%') .
			' OR descr LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	if (get_request_var('source') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND ':'WHERE ') . ' source = ' . db_qstr(get_request_var('source'));
	}

	if (get_request_var('version') == '1') {
		$sql_where .= ($sql_where != '' ? ' AND ':'WHERE ') . ' route LIKE "%:%"';
	} elseif (get_request_var('version') == '0') {
		$sql_where .= ($sql_where != '' ? ' AND ':'WHERE ') . ' route NOT LIKE "%:%"';
	}

	$total_rows = flowview_db_fetch_cell("SELECT COUNT(*)
		FROM plugin_flowview_irr_route AS dc
		$sql_where");

	$sql_order = get_order_string();

	/* sort naturally if the orgin is in the sort */
	if (strpos($sql_order, 'origin ') !== false) {
		$sql_order = str_replace('`origin` ', 'NATURAL_SORT_KEY(`origin`) ', $sql_order);
	}

	/* sort naturally if the route is in the sort */
	if (strpos($sql_order, 'route ') !== false) {
		$sql_order = str_replace('route ', 'NATURAL_SORT_KEY(route) ', $sql_order);
	}

	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	$dns_cache = flowview_db_fetch_assoc("SELECT routes.*
		FROM plugin_flowview_irr_route AS routes
		$sql_where
		$sql_order
		$sql_limit");

	$nav = html_nav_bar('flowview_databases.php?tab=routes&filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 8, __('Entries', 'flowview'), 'page', 'main');

	form_start('flowview_databases.php?tab=routes', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'route' => array(
			'display' => __('Route', 'flowview'),
			'align'   => 'left',
			'tip'     => __('This is the published Internet Route.', 'flowview')
		),
		'origin' => array(
			'display' => __('AS Number', 'flowview'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('The Autonomous System Number for the route.', 'flowview')
		),
		'descr' => array(
			'display' => __('Route Description', 'flowview'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('The Descrption logged by the Route Maintainer.', 'flowview')
		),
		'source' => array(
			'display' => __('Source', 'flowview'),
			'align'   => 'left',
			'sort'    => 'DESC',
			'tip'     => __('The source database used to publish the route.', 'flowview')
		),
		'mnt_by' => array(
			'display' => __('Maintained By', 'flowview'),
			'align'   => 'left',
			'sort'    => 'DESC',
			'tip'     => __('The Internet Carrier who is maintaining the route for the customer.', 'flowview')
		),
		'last_modified' => array(
			'display' => __('Last Modified', 'flowview'),
			'align'   => 'right',
			'sort'    => 'DESC',
			'tip' => __('The last time the information on this route was modified.', 'flowview')
		)
	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'flowview_databases.php?tab=routes');

	$i = 0;
	if (cacti_sizeof($dns_cache)) {
		foreach ($dns_cache as $l) {
			form_alternate_row('line' . $i, false);
			form_selectable_cell(filter_value($l['route'], get_request_var('filter')), $i);
			form_selectable_cell(filter_value($l['origin'], get_request_var('filter')), $i);
			form_selectable_cell(filter_value($l['descr'], get_request_var('filter')), $i);
			form_selectable_cell(filter_value($l['source'], get_request_var('filter')), $i);
			form_selectable_cell(filter_value($l['mnt_by'], get_request_var('filter')), $i);
			form_selectable_cell($l['last_modified'], $i, '', 'right');
			form_end_row();

			$i++;
		}
	} else {
		print "<tr class='tableRow'><td colspan='" . (cacti_sizeof($display_text)+1) . "'><em>" . __('No Matching Routes Found', 'flowview') . "</em></td></tr>\n";
	}

	html_end_box(false);

	if (cacti_sizeof($dns_cache)) {
		print $nav;
	}

	form_end();
}

