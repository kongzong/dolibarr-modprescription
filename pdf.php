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
 * \file    htdocs/custom/prescription/pdf.php
 * \ingroup prescription
 * \brief   Stream the prescription sheet. Drafts are regenerated on every
 *          download (content still changes); issued / voided sheets are
 *          regenerated only when missing. Every download writes
 *          PRESCRIPTION_PRINT (spec §3.4). Requires 'prescription read'.
 */

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

dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/prescription/lib/prescription.lib.php');
dol_include_once('/prescription/class/prescriptionsheet.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "prescription@prescription"));

$id = GETPOSTINT('id');
$force = GETPOSTINT('regen');
if ($id <= 0 || !$user->hasRight('prescription', 'read')) {
	accessforbidden();
}
$object = new PrescriptionSheet($db);
if ($object->fetch($id) <= 0) {
	accessforbidden($langs->trans("ErrorRecordNotFound"));
}

$file = $object->pdfPath();
$mustBuild = $force || !is_file($file) || (int) $object->status === PRESCRIPTION_STATUS_DRAFT;
if ($mustBuild) {
	$result = $object->generateDocument('', $langs);
	if ($result <= 0 || !is_file($file)) {
		// If regeneration failed but the old file still exists, serve it as fallback
		if (is_file($file)) {
			$mustBuild = false;
		} else {
			dol_syslog('prescription pdf generation failed for '.$object->ref.': '.$object->error, LOG_ERR);
			header('Content-Type: text/plain; charset=utf-8');
			print $langs->trans("PrescriptionPdfFailed").' '.dol_escape_htmltag((string) $object->error);
			exit;
		}
	}
}

patient_audit($db, $object->fk_patient, 'PRESCRIPTION_PRINT', $user, array('ref' => $object->ref, 'prescription' => $object->id, 'status' => (int) $object->status));

// Flush any output buffer so nothing pollutes the binary PDF data
while (ob_get_level()) {
	ob_end_clean();
}

$filename = dol_sanitizeFileName($object->ref).'.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.$filename.'"');
header('Content-Length: '.filesize($file));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
readfile($file);
$db->close();
