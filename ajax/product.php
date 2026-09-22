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
 * \file    htdocs/custom/prescription/ajax/product.php
 * \ingroup prescription
 * \brief   Drug picker data source: GET type=TCM|WM&term=... -> JSON
 *          [{id, ref, label, unit, value}]. Filters by the configured product
 *          category of the type when set. Session-authenticated, needs
 *          'prescription read' and 'produit lire'.
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
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

if (empty($user->id) || !$user->hasRight('prescription', 'read') || !isModEnabled('product') || !$user->hasRight('produit', 'lire')) {
	header('HTTP/1.1 403 Forbidden');
	exit;
}

header('Content-Type: application/json; charset=utf-8');

$type = GETPOST('type', 'aZ') === 'WM' ? 'WM' : 'TCM';
$term = trim(GETPOST('term', 'alphanohtml'));
if ($term === '') {
	echo json_encode(array());
	$db->close();
	exit;
}

$category = (int) getDolGlobalString($type === 'WM' ? 'PRESCRIPTION_WM_CATEGORY' : 'PRESCRIPTION_TCM_CATEGORY');

$sql = "SELECT p.rowid, p.ref, p.label, p.fk_unit FROM ".$db->prefix()."product as p";
if ($category > 0) {
	$sql .= " INNER JOIN ".$db->prefix()."categorie_product as cp ON cp.fk_product = p.rowid AND cp.fk_categorie = ".$category;
}
$sql .= " WHERE p.entity IN (".getEntity('product').") AND p.tosell = 1";
$sql .= " AND (p.ref LIKE '".$db->escape($term)."%' OR p.label LIKE '%".$db->escape($term)."%' OR p.barcode = '".$db->escape($term)."')";
$sql .= " ORDER BY CASE WHEN p.label LIKE '".$db->escape($term)."%' THEN 0 ELSE 1 END, p.label ASC".$db->plimit(20, 0);

$out = array();
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$unit = '';
		if ($obj->fk_unit) {
			$u = measuringUnitString((int) $obj->fk_unit, '', null, 1, $langs);
			$unit = ($u === -1 || $u === false) ? '' : (string) $u;
		}
		$out[] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'label' => $obj->label, 'unit' => $unit, 'value' => $obj->label.' ('.$obj->ref.')');
	}
	$db->free($resql);
}
echo json_encode($out);
$db->close();
