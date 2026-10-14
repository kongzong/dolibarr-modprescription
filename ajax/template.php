<?php
/* Copyright (C) 2026  modPrescription contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/prescription/ajax/template.php
 * \brief   Read-only JSON for the prescribing form's "apply template" picker.
 *          GET ?list=1&type=TCM|WM  -> [{id,name,nlines}]
 *          GET ?id=N                 -> template head + rows (addRow keys)
 *          Requires 'prescription read'.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/prescription/class/prescriptiontemplate.class.php');

/* @var DoliDB $db */

header('Content-Type: application/json; charset=utf-8');

if (!$user->hasRight('prescription', 'read')) {
	print json_encode(array('error' => 'forbidden'));
	exit;
}

$type = GETPOST('type', 'aZ') === 'WM' ? 'WM' : 'TCM';
$id = GETPOSTINT('id');

if (GETPOSTINT('tlist') === 1) {
	print json_encode(PrescriptionTemplate::listAll($db, $type), JSON_UNESCAPED_UNICODE);
	exit;
}

if ($id > 0) {
	$o = new PrescriptionTemplate($db);
	if ($o->fetch($id) <= 0) {
		print json_encode(array('error' => 'notfound'));
		exit;
	}
	$lines = array();
	foreach ($o->lines as $l) {
		$lines[] = array(
			'label' => $l['label'],
			'fk_product' => $l['fk_product'],
			'product_ref' => $l['product_ref'],
			'qty' => $l['qty'] !== null ? rtrim(rtrim(number_format($l['qty'], 3, '.', ''), '0'), '.') : '',
			'qty_unit' => $l['qty_unit'],
			'decoct' => $l['decoct_code'],
			'dose' => $l['dose'] !== null ? rtrim(rtrim(number_format($l['dose'], 3, '.', ''), '0'), '.') : '',
			'dose_unit' => $l['dose_unit'],
			'route' => $l['route_code'],
			'freq' => $l['freq_code'],
			'days' => $l['days'] !== null ? (string) $l['days'] : '',
			'sig' => $l['sig_note'],
		);
	}
	print json_encode(array(
		'id' => $o->rowid,
		'name' => $o->name,
		'presc_type' => $o->presc_type,
		'doses' => $o->doses,
		'diagnosis' => $o->diagnosis_text,
		'lines' => $lines,
	), JSON_UNESCAPED_UNICODE);
	exit;
}

print json_encode(array('error' => 'badrequest'));
