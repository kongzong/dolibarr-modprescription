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
 * \file    htdocs/custom/prescription/patient_tab.php
 * \ingroup prescription
 * \brief   "Prescriptions" tab on the patient card: timeline + new buttons.
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

dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/prescription/lib/prescription.lib.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "prescription@prescription"));

$id = GETPOSTINT('id');
if ($id <= 0 || !$user->hasRight('patient', 'read') || !$user->hasRight('prescription', 'read')) {
	accessforbidden();
}

$patient = new PatientProfile($db);
if ($patient->fetch($id) <= 0) {
	accessforbidden($langs->trans("PatientNotYet"));
}

llxHeader('', $langs->trans("PrescriptionTab"));

$head = patient_prepare_head($patient);
print dol_get_fiche_head($head, 'prescription', $langs->trans("PatientTab"), -1, 'user');

print patient_summary_banner(patient_get_summary($db, $patient->id), array(), 'prescription');

if ($user->hasRight('prescription', 'write')) {
	$base = dol_buildpath('/prescription/card.php', 1).'?action=create&fk_patient='.$patient->id;
	print '<div class="tabsAction">';
	print dolGetButtonAction($langs->trans("PrescriptionNewTCM"), '', 'default', $base.'&presc_type=TCM', '', 1);
	print dolGetButtonAction($langs->trans("PrescriptionNewWM"), '', 'default', $base.'&presc_type=WM', '', 1);
	print '</div>';
}

$rows = prescription_list_by_patient($db, $patient->id, 50, true);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("PrescriptionRef").'</th>';
print '<th>'.$langs->trans("PrescriptionType").'</th>';
print '<th>'.$langs->trans("PrescriptionDate").'</th>';
print '<th>'.$langs->trans("PrescriptionDoctor").'</th>';
print '<th>'.$langs->trans("PrescriptionDiagnosis").'</th>';
print '<th>'.$langs->trans("PrescriptionLines").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '</tr>';
if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($rows as $r) {
	print '<tr class="oddeven"'.((int) $r->status === PRESCRIPTION_STATUS_VOIDED ? ' style="opacity:.55"' : '').'>';
	print '<td><a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.((int) $r->rowid).'">'.dol_escape_htmltag($r->ref).'</a></td>';
	print '<td>'.prescription_type_label($r->presc_type).'</td>';
	print '<td>'.dol_print_date($db->jdate($r->date_presc), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag(trim($r->lastname.' '.$r->firstname)).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->diagnosis_text).'</td>';
	print '<td>'.((int) $r->nb_lines).'</td>';
	print '<td class="center">'.prescription_status_badge($r->status).'</td>';
	print '</tr>';
}
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
