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
 * \file    htdocs/custom/prescription/admin/setup.php
 * \ingroup prescription
 * \brief   Module setup: retention, free lines, paper format, product
 *          category filters, default decoction usage.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/prescription/lib/prescription.lib.php');
dol_include_once('/prescription/class/prescriptiondictimport.class.php');
dol_include_once('/prescription/core/modules/modPrescription.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "errors", "categories", "prescription@prescription"));

if (!$user->admin && !$user->hasRight('prescription', 'admin')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$module = new modPrescription($db);
$form = new Form($db);
$importResult = null;

if ($action == 'save') {
	$ok = true;
	$retention = max(1, GETPOSTINT('PRESCRIPTION_RETENTION_YEARS'));
	$free = GETPOSTINT('PRESCRIPTION_ALLOW_FREE_LINES') ? '1' : '0';
	$format = GETPOST('PRESCRIPTION_PDF_FORMAT', 'aZ09') === 'A5' ? 'A5' : 'A4';
	$tcmCat = GETPOSTINT('PRESCRIPTION_TCM_CATEGORY') > 0 ? (string) GETPOSTINT('PRESCRIPTION_TCM_CATEGORY') : '';
	$wmCat = GETPOSTINT('PRESCRIPTION_WM_CATEGORY') > 0 ? (string) GETPOSTINT('PRESCRIPTION_WM_CATEGORY') : '';
	$usage = trim(GETPOST('PRESCRIPTION_TCM_DEFAULT_USAGE', 'alphanohtml'));
	$ok = $ok && dolibarr_set_const($db, 'PRESCRIPTION_RETENTION_YEARS', (string) $retention, 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'PRESCRIPTION_ALLOW_FREE_LINES', $free, 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'PRESCRIPTION_PDF_FORMAT', $format, 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'PRESCRIPTION_TCM_CATEGORY', $tcmCat, 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'PRESCRIPTION_WM_CATEGORY', $wmCat, 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'PRESCRIPTION_TCM_DEFAULT_USAGE', $usage, 'chaine', 0, '', $conf->entity) > 0;
	setEventMessages($langs->trans($ok ? "SetupSaved" : "Error"), null, $ok ? 'mesgs' : 'errors');
	$action = '';
}

// Dictionary CSV import (spec §3.1): idempotent upsert on code
if ($action == 'importdict') {
	$type = GETPOST('dict_type', 'aZ09');
	if (!isset(PrescriptionDictImport::TABLES[$type])) {
		setEventMessages($langs->trans("PrescriptionImportBadType"), null, 'errors');
	} elseif (empty($_FILES['dictfile']['tmp_name']) || !is_uploaded_file($_FILES['dictfile']['tmp_name'])) {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("File")), null, 'errors');
	} elseif ($_FILES['dictfile']['size'] > 20 * 1024 * 1024) {
		setEventMessages($langs->trans("ErrorFileSizeTooLarge"), null, 'errors');
	} else {
		$importer = new PrescriptionDictImport($db);
		$importResult = $importer->importFile($_FILES['dictfile']['tmp_name'], $type);
		if ($importResult === null) {
			setEventMessages($langs->trans("PrescriptionImportFailed").': '.$langs->trans($importer->error), null, 'errors');
		} else {
			setEventMessages($langs->trans("PrescriptionImportOk", $importResult['read'], $importResult['inserted'], $importResult['updated'], $importResult['skipped']), null, 'mesgs');
		}
	}
	$action = '';
}

llxHeader('', $langs->trans("PrescriptionSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("PrescriptionSetup"), $linkback, 'title_setup');

$head = prescription_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans("ModulePrescriptionName"), -1, 'fa-prescription');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("PrescriptionRetentionYears").'</td>';
print '<td><input type="number" min="1" name="PRESCRIPTION_RETENTION_YEARS" class="maxwidth75" value="'.getDolGlobalInt('PRESCRIPTION_RETENTION_YEARS', 1).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("PrescriptionAllowFreeLines").'</td>';
print '<td>'.$form->selectyesno('PRESCRIPTION_ALLOW_FREE_LINES', getDolGlobalInt('PRESCRIPTION_ALLOW_FREE_LINES', 1), 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("PrescriptionPdfFormat").'</td>';
print '<td>'.$form->selectarray('PRESCRIPTION_PDF_FORMAT', array('A4' => 'A4', 'A5' => 'A5'), getDolGlobalString('PRESCRIPTION_PDF_FORMAT', 'A4'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td></tr>';
if (isModEnabled('category') && isModEnabled('product')) {
	print '<tr class="oddeven"><td>'.$langs->trans("PrescriptionTcmCategory").'</td>';
	print '<td>'.$form->select_all_categories('product', getDolGlobalString('PRESCRIPTION_TCM_CATEGORY'), 'PRESCRIPTION_TCM_CATEGORY', 64, 0, 0, 0, 'minwidth300').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("PrescriptionWmCategory").'</td>';
	print '<td>'.$form->select_all_categories('product', getDolGlobalString('PRESCRIPTION_WM_CATEGORY'), 'PRESCRIPTION_WM_CATEGORY', 64, 0, 0, 0, 'minwidth300').'</td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans("PrescriptionTcmDefaultUsage").'</td>';
print '<td><input type="text" name="PRESCRIPTION_TCM_DEFAULT_USAGE" class="minwidth400" maxlength="255" value="'.dol_escape_htmltag(getDolGlobalString('PRESCRIPTION_TCM_DEFAULT_USAGE')).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

print '<br><p class="opacitymedium">'.$langs->trans("ModulePrescriptionName").' '.$module->version.' &middot; ';
print '<a href="'.DOL_URL_ROOT.'/admin/dict.php">'.$langs->trans("Dictionaries").'</a>: ';
print $langs->trans("PrescriptionDictDecoct").' / '.$langs->trans("PrescriptionDictRoute").' / '.$langs->trans("PrescriptionDictFreq").' / '.$langs->trans("PrescriptionDictDoseUnit").'</p>';

// Import
$counts = array();
foreach (PrescriptionDictImport::TABLES as $t => $table) {
	$resql = $db->query("SELECT COUNT(*) as c, SUM(active) as a FROM ".$db->prefix().$table);
	$o = $resql ? $db->fetch_object($resql) : null;
	$counts[$t] = $o ? array('total' => (int) $o->c, 'active' => (int) $o->a) : array('total' => 0, 'active' => 0);
}
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="importdict">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("PrescriptionImportTitle").'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("Dictionary").'</td><td>';
$typeOptions = array(
	'decoct' => $langs->trans("PrescriptionDictDecoct").' ('.$counts['decoct']['active'].'/'.$counts['decoct']['total'].')',
	'route' => $langs->trans("PrescriptionDictRoute").' ('.$counts['route']['active'].'/'.$counts['route']['total'].')',
	'freq' => $langs->trans("PrescriptionDictFreq").' ('.$counts['freq']['active'].'/'.$counts['freq']['total'].')',
	'dose_unit' => $langs->trans("PrescriptionDictDoseUnit").' ('.$counts['dose_unit']['active'].'/'.$counts['dose_unit']['total'].')',
);
print $form->selectarray('dict_type', $typeOptions, GETPOST('dict_type', 'aZ09') ?: 'decoct', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("File").'</td><td><input type="file" name="dictfile" accept=".csv,.txt"></td></tr>';
print '<tr class="oddeven"><td></td><td class="opacitymedium">'.$langs->trans("PrescriptionImportHelp").'</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("Import").'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
