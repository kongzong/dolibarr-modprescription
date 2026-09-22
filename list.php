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
 * \file    htdocs/custom/prescription/list.php
 * \ingroup prescription
 * \brief   PrescriptionSheet list: ref / card no / name, type, doctor, status,
 *          date range. Voided hidden unless filtered.
 */

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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
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

if (!$user->hasRight('prescription', 'read')) {
	accessforbidden();
}

$form = new Form($db);

$search = trim(GETPOST('search', 'alphanohtml'));
$searchType = GETPOST('search_type', 'aZ');
$searchDoctor = GETPOSTINT('search_doctor');
$searchStatus = GETPOST('search_status', 'alpha');
$status = ($searchStatus !== '' && is_numeric($searchStatus)) ? (int) $searchStatus : -1;
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search = '';
	$searchType = '';
	$searchDoctor = 0;
	$status = -1;
	$dateFrom = '';
	$dateTo = '';
}

$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$page = (int) GETPOST('page', 'int');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$dao = new PrescriptionSheet($db);
$result = $dao->search(array('q' => $search, 'type' => $searchType, 'doctor' => $searchDoctor, 'status' => $status, 'from' => $dateFrom, 'to' => $dateTo), $limit, $offset);
if ($result === null) {
	dol_print_error($db, $dao->error);
	exit;
}
$total = $result['total'];
$rows = $result['rows'];
$doctors = patient_doctor_options($db);

llxHeader('', $langs->trans("PrescriptionList"));

$param = '&limit='.(int) $limit;
if ($search !== '') {
	$param .= '&search='.urlencode($search);
}
if ($searchType !== '') {
	$param .= '&search_type='.urlencode($searchType);
}
if ($searchDoctor > 0) {
	$param .= '&search_doctor='.$searchDoctor;
}
if ($status >= 0) {
	$param .= '&search_status='.$status;
}
foreach (array('from', 'to') as $bound) {
	foreach (array('day', 'month', 'year') as $part) {
		$v = GETPOSTINT('search_'.$bound.$part);
		if ($v > 0) {
			$param .= '&search_'.$bound.$part.'='.$v;
		}
	}
}

$newcardbutton = '';
if ($user->hasRight('prescription', 'write')) {
	$newcardbutton = dolGetButtonTitle($langs->trans("PrescriptionNewTCM"), '', 'fa fa-plus-circle', dol_buildpath('/prescription/card.php', 1).'?action=create&presc_type=TCM');
	$newcardbutton .= dolGetButtonTitle($langs->trans("PrescriptionNewWM"), '', 'fa fa-plus-circle', dol_buildpath('/prescription/card.php', 1).'?action=create&presc_type=WM');
}

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formprescriptionlist">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print_barre_liste($langs->trans("PrescriptionList"), $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, 'fa-prescription', 0, $newcardbutton, '', $limit, 0, 0, 1);

$statusOptions = array(
	(string) PRESCRIPTION_STATUS_DRAFT => prescription_status_label(PRESCRIPTION_STATUS_DRAFT),
	(string) PRESCRIPTION_STATUS_ISSUED => prescription_status_label(PRESCRIPTION_STATUS_ISSUED),
	(string) PRESCRIPTION_STATUS_DISPENSED => prescription_status_label(PRESCRIPTION_STATUS_DISPENSED),
	(string) PRESCRIPTION_STATUS_VOIDED => prescription_status_label(PRESCRIPTION_STATUS_VOIDED),
);
$typeOptions = array(PRESCRIPTION_TYPE_TCM => prescription_type_label(PRESCRIPTION_TYPE_TCM), PRESCRIPTION_TYPE_WM => prescription_type_label(PRESCRIPTION_TYPE_WM));

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="2"><input type="text" name="search" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans("PrescriptionSearchHint")).'" value="'.dol_escape_htmltag($search).'"></td>';
print '<td class="liste_titre">'.$form->selectarray('search_type', $typeOptions, $searchType, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125').'</td>';
print '<td class="liste_titre">'.$form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - '.$form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0).'</td>';
print '<td class="liste_titre">'.$form->selectarray('search_doctor', $doctors, $searchDoctor, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre"></td><td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statusOptions, $status >= 0 ? (string) $status : '', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center maxwidthsearch">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td></tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans("PrescriptionRef").'</th>';
print '<th>'.$langs->trans("PrescriptionPatient").'</th>';
print '<th>'.$langs->trans("PrescriptionType").'</th>';
print '<th>'.$langs->trans("PrescriptionDate").'</th>';
print '<th>'.$langs->trans("PrescriptionDoctor").'</th>';
print '<th>'.$langs->trans("PrescriptionDiagnosis").'</th>';
print '<th>'.$langs->trans("PrescriptionLines").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th></th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="9"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($rows as $row) {
	$url = dol_buildpath('/prescription/card.php', 1).'?id='.((int) $row->rowid);
	print '<tr class="oddeven"'.((int) $row->status === PRESCRIPTION_STATUS_VOIDED ? ' style="opacity:.55"' : '').'>';
	print '<td><a href="'.$url.'">'.img_picto('', 'fa-prescription', 'class="pictofixedwidth"').dol_escape_htmltag($row->ref).'</a></td>';
	print '<td><a href="'.dol_buildpath('/patient/card.php', 1).'?id='.((int) $row->fk_patient).'">'.dol_escape_htmltag($row->patient_name).'</a> <span class="opacitymedium">'.dol_escape_htmltag($row->card_no).'</span></td>';
	print '<td>'.prescription_type_label($row->presc_type).'</td>';
	print '<td>'.dol_print_date($db->jdate($row->date_presc), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag(trim($row->lastname.' '.$row->firstname)).'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->diagnosis_text).'</td>';
	print '<td>'.((int) $row->nb_lines).($row->presc_type === PRESCRIPTION_TYPE_TCM && $row->doses ? ' &times; '.((int) $row->doses).$langs->trans("PrescriptionDosesUnit") : '').'</td>';
	print '<td class="center">'.prescription_status_badge($row->status).'</td>';
	print '<td></td>';
	print '</tr>';
}

print '</table></div>';
print '</form>';

llxFooter();
$db->close();
