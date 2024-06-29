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
	1 => __('Delete'),
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

		view_dns_cache();

		bottom_footer();
		break;
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

		header('Location: flowview_dnscache.php?header=false');
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

	form_start('flowview_dnscache.php');

	html_start_box($actions[get_nfilter_request_var('drp_action')], '60%', '', '3', 'center', '');

	if (isset($dns_array) && cacti_sizeof($dns_array)) {
		if (get_nfilter_request_var('drp_action') == '1') { /* delete */
			print "<tr>
				<td class='textArea'>
					<p>" . __n('Click \'Continue\' to delete the following DNS Cache Entriy.', 'Click \'Continue\' to delete all following DNS Cache Entries.', cacti_sizeof($dns_array)) . "</p>
					<div class='itemlist'><ul>$dns_list</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget' value='" . __esc('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Delete DNS Entry', 'Delete DNS Entries', cacti_sizeof($dns_array)) . "'>";
		}
	} else {
		raise_message(40);
		header('Location: flowview_dnscache.php?header=false');
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

	html_start_box(__('Flowview DNS Cache Entries'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='form' action='flowview_dnscache.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search');?>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' name='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Source');?>
					</td>
					<td>
						<select id='source' name='source' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('source') == '-1' ? ' selected>':'>') . __('Any');?></option>
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
						<?php print __('Entries');?>
					</td>
					<td>
						<select id='rows' name='rows' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default');?></option>
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
							<input type='button' class='ui-button ui-corner-all ui-widget' id='refresh' value='<?php print __esc('Go', 'flowview');?>' title='<?php print __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc('Clear', 'flowview');?>' title='<?php print __esc('Clear Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='purge' value='<?php print __esc('Purge', 'flowview');?>' title='<?php print __esc('Purge the DNS Cache');?>'>
						</span>
					</td>
				</tr>
			</table>
			</form>
			<script type='text/javascript'>

			function applyFilter() {
				strURL  = 'flowview_dnscache.php?header=false';
				strURL += '&filter='+$('#filter').val();
				strURL += '&source='+$('#source').val();
				strURL += '&rows='+$('#rows').val();
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'flowview_dnscache.php?clear=1&header=false';
				loadPageNoHeader(strURL);
			}

			function purgeFilter() {
				strURL = 'flowview_dnscache.php?action=purge&header=false';
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

	$total_rows = flowview_db_fetch_cell("SELECT COUNT(*)
		FROM plugin_flowview_dnscache
		$sql_where");

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	$dns_cache = flowview_db_fetch_assoc("SELECT *
		FROM plugin_flowview_dnscache
		$sql_where
		$sql_order
		$sql_limit");

	$nav = html_nav_bar('flowview_dnscache.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 5, __('Entries'), 'page', 'main');

	form_start('flowview_dnscache.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'ip' => array(
			'display' => __('IP Address'),
			'align' => 'left',
			'tip'  => __('This is the IP Address of the Cache entry.')
		),
		'host' => array(
			'display' => __('DNS Hostname'),
			'align' => 'left',
			'sort' => 'ASC',
			'tip' => __('The DNS Name assigned to the IP Address.')
		),
		'source' => array(
			'display' => __('Source'),
			'align' => 'left',
			'sort' => 'DESC',
			'tip' => __('The source of the DNS Hostname.  It can either be DNS, Static Lookup or ARIN.')
		),
		'time' => array(
			'display' => __('Time Inserted'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('This is the time that the DNS cache was entered or last updated.')
		)
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$i = 0;
	if (cacti_sizeof($dns_cache)) {
		foreach ($dns_cache as $l) {
			form_alternate_row('line' . $l['id'], false);
			form_selectable_cell(filter_value($l['ip'], get_request_var('filter')), $l['id']);
			form_selectable_cell(filter_value($l['host'], get_request_var('filter')), $l['id']);
			form_selectable_cell($l['source'], $l['id']);
			form_selectable_cell(date('Y-m-d H:i:s', $l['time']), $l['id'], '', 'right');
			form_checkbox_cell($l['host'], $l['id']);
			form_end_row();
		}
	} else {
		print "<tr class='tableRow'><td colspan='" . (cacti_sizeof($display_text)+1) . "'><em>" . __('No DNS Cache Entries Found') . "</em></td></tr>\n";
	}

	html_end_box(false);

	if (cacti_sizeof($dns_cache)) {
		print $nav;
	}

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($actions);

	form_end();
}

