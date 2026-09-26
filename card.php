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
 * \file    htdocs/custom/prescription/card.php
 * \ingroup prescription
 * \brief   PrescriptionSheet card: create / view / edit / issue / void / copy.
 *          Two line editors (TCM grid, western sig table) fed by
 *          ajax/product.php. Allergy conflicts block the save and are shown
 *          with the override form when the rule allows it (spec §3.3).
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

$langs->loadLangs(array("patient@patient", "medrecord@medrecord", "prescription@prescription"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$fkPatientParam = GETPOSTINT('fk_patient');
$fkMedrecordParam = GETPOSTINT('fk_medrecord');
$typeParam = GETPOST('presc_type', 'aZ') === 'WM' ? PRESCRIPTION_TYPE_WM : PRESCRIPTION_TYPE_TCM;

$canRead = $user->hasRight('prescription', 'read');
$canWrite = $user->hasRight('prescription', 'write');
$canProfile = $user->hasRight('patient', 'profile');
if (!$canRead) {
	accessforbidden();
}

$form = new Form($db);
$object = new PrescriptionSheet($db);
// Extension point for modPharmacy (dispense button + sheet list, spec-pharmacy §3.5)
$hookmanager->initHooks(array('prescriptioncard'));

if ($id > 0) {
	$r = $object->fetch($id);
	if ($r < 0) {
		dol_print_error($db, $object->error);
		exit;
	}
	if ($r == 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
}

$doctors = patient_doctor_options($db);
$departments = patient_dict_rows($db, 'c_patient_department');
$dictDecoct = prescription_dict_options($db, 'c_prescription_decoct');
$dictRoute = prescription_dict_options($db, 'c_prescription_route');
$dictFreq = prescription_dict_options($db, 'c_prescription_freq');
$dictDoseUnit = prescription_dict_options($db, 'c_prescription_dose_unit');
$decoctModes = array('SELF' => $langs->trans("PrescriptionDecoctSelf"), 'CLINIC' => $langs->trans("PrescriptionDecoctClinic"), 'GRANULE' => $langs->trans("PrescriptionDecoctGranule"));

/**
 * Read header + line arrays from the form into the object.
 *
 * @param	PrescriptionSheet	$o	Target
 * @return	void
 */
function prescription_read_form(PrescriptionSheet $o)
{
	$o->fk_doctor = GETPOSTINT('fk_doctor');
	$o->fk_department = GETPOSTINT('fk_department');
	$o->date_presc = dol_mktime(GETPOSTINT('date_preschour'), GETPOSTINT('date_prescmin'), 0, GETPOSTINT('date_prescmonth'), GETPOSTINT('date_prescday'), GETPOSTINT('date_prescyear'));
	$o->diagnosis_text = GETPOST('diagnosis_text', 'alphanohtml');
	$o->doses = GETPOSTINT('doses');
	$o->decoct_mode = GETPOST('decoct_mode', 'aZ');
	$o->usage_note = GETPOST('usage_note', 'restricthtml');
	$o->note = GETPOST('note', 'restricthtml');
	$o->allergy_override_reason = GETPOSTINT('allergy_override') ? GETPOST('allergy_override_reason', 'alphanohtml') : '';

	$labels = GETPOST('line_label', 'array');
	$o->lines = array();
	foreach ((array) $labels as $i => $label) {
		$get = function ($name) use ($i) {
			$arr = GETPOST($name, 'array');
			return isset($arr[$i]) ? $arr[$i] : '';
		};
		$o->lines[] = array(
			'label' => $label,
			'fk_product' => (int) $get('line_fk_product'),
			'product_ref' => $get('line_product_ref'),
			'qty' => $get('line_qty'),
			'qty_unit' => $get('line_qty_unit'),
			'decoct_code' => $get('line_decoct'),
			'dose' => $get('line_dose'),
			'dose_unit' => $get('line_dose_unit'),
			'route_code' => $get('line_route'),
			'freq_code' => $get('line_freq'),
			'days' => $get('line_days'),
			'sig_note' => $get('line_sig'),
		);
	}
}


/*
 * Actions
 */

$blocked = null; // PrescriptionSheet whose save was blocked by allergies (re-rendered with the panel)

if ($action == 'add' && $canWrite) {
	if (GETPOST('cancel', 'alpha')) {
		header("Location: ".($fkMedrecordParam > 0 ? dol_buildpath('/medrecord/card.php', 1).'?id='.$fkMedrecordParam : dol_buildpath('/prescription/list.php', 1)));
		exit;
	}
	$object = new PrescriptionSheet($db);
	$object->presc_type = $typeParam;
	$object->fk_patient = $fkPatientParam;
	$object->fk_medrecord = $fkMedrecordParam;
	prescription_read_form($object);
	$result = $object->create($user);
	if ($result > 0) {
		setEventMessages($langs->trans("PrescriptionCreated", $object->ref), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	if ($result === PrescriptionSheet::ERR_ALLERGY) {
		$blocked = $object;
	} else {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	}
	$action = 'create';
}

if ($action == 'update' && $object->id > 0) {
	if (GETPOST('cancel', 'alpha')) {
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	if (!$object->canEdit($user)) {
		accessforbidden($langs->trans("PrescriptionErrNotEditable"));
	}
	prescription_read_form($object);
	$result = $object->update($user);
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	if ($result === PrescriptionSheet::ERR_ALLERGY) {
		$blocked = $object;
	} else {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	}
	$action = 'edit';
}

if ($action == 'confirm_issue' && $confirm == 'yes' && $object->id > 0) {
	$object->allergy_override_reason = GETPOSTINT('allergy_override') ? GETPOST('allergy_override_reason', 'alphanohtml') : $object->allergy_override_reason;
	$result = $object->issue($user);
	if ($result > 0) {
		// Sheet is generated right after issuing (spec §3.4); a failure here does not undo the issue
		if ($object->generateDocument('', $langs) <= 0) {
			setEventMessages($langs->trans("PrescriptionPdfFailed").' '.$object->error, null, 'warnings');
		}
		setEventMessages($langs->trans("PrescriptionIssued"), null, 'mesgs');
	} elseif ($result === PrescriptionSheet::ERR_ALLERGY) {
		setEventMessages($langs->trans($object->error), null, 'errors');
		foreach ($object->allergyHits as $h) {
			setEventMessages(dol_escape_htmltag($h['label']).' → '.dol_escape_htmltag($h['allergy']).' ('.PatientAllergy::severityLabel($h['severity']).')', null, 'warnings');
		}
	} else {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
	exit;
}

if ($action == 'confirm_void' && $confirm == 'yes' && $object->id > 0) {
	$result = $object->void($user, GETPOST('void_reason', 'alphanohtml'));
	setEventMessages($langs->trans($result > 0 ? "PrescriptionVoided" : $object->error), null, $result > 0 ? 'mesgs' : 'errors');
	header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
	exit;
}

$prefill = null;
if ($action == 'copy' && $object->id > 0 && $canWrite) {
	$prefill = $object->copyAsNew();
	$fkPatientParam = $object->fk_patient;
	$fkMedrecordParam = (int) $object->fk_medrecord;
	$typeParam = $object->presc_type;
	patient_audit($db, $object->fk_patient, 'PRESCRIPTION_COPY', $user, array('ref' => $object->ref, 'prescription' => $object->id));
	$action = 'create';
}

// Prefill a fresh draft from the medical record (doctor, department, diagnosis snapshot)
if ($action == 'create' && $prefill === null && $blocked === null && $fkMedrecordParam > 0 && isModEnabled('medrecord')) {
	dol_include_once('/medrecord/class/medicalrecord.class.php');
	$rec = new MedicalRecord($db);
	if ($rec->fetch($fkMedrecordParam) > 0) {
		$prefill = new PrescriptionSheet($db);
		$prefill->presc_type = $typeParam;
		$prefill->fk_patient = $rec->fk_patient;
		$prefill->fk_medrecord = $rec->id;
		$prefill->fk_doctor = $rec->fk_doctor;
		$prefill->fk_department = $rec->fk_department;
		$parts = array_filter(array($rec->primaryDiagnosisLabel(), $rec->tcm_disease_label, $rec->tcm_syndrome_label));
		$prefill->diagnosis_text = dol_substr(implode('；', $parts), 0, 255);
		$fkPatientParam = $rec->fk_patient;
	}
}

if ($object->id > 0 && $action != 'create') {
	patient_audit($db, $object->fk_patient, 'PRESCRIPTION_READ', $user, array('ref' => $object->ref, 'prescription' => $object->id));
}


/*
 * View
 */

$title = $action == 'create' ? $langs->trans("PrescriptionNew") : ($object->ref ? $object->ref : $langs->trans("PrescriptionTab"));
llxHeader('', $title);

/**
 * Allergy block panel (after a blocked save): hits + override form when allowed.
 *
 * @param	PrescriptionSheet	$p	Blocked object
 * @return	void
 */
function prescription_print_allergy_panel(PrescriptionSheet $p)
{
	global $langs, $user, $canProfile;

	print '<div class="error" style="padding:8px 12px;margin-bottom:10px;">';
	if (!$canProfile) {
		print $langs->trans("PrescriptionAllergyNoProfile");
	} else {
		print '<strong>'.$langs->trans("PrescriptionAllergyBlocked").'</strong><ul style="margin:4px 0;">';
		foreach ($p->allergyHits as $h) {
			print '<li>'.dol_escape_htmltag($h['label']).' → '.dol_escape_htmltag($h['allergy']).' ('.PatientAllergy::severityLabel($h['severity']).')</li>';
		}
		print '</ul>';
		if ($p->overridePossible($user)) {
			print '<label><input type="checkbox" name="allergy_override" value="1"'.(GETPOSTINT('allergy_override') ? ' checked' : '').'> '.$langs->trans("PrescriptionAllergyOverride").'</label> ';
			print '<input type="text" name="allergy_override_reason" class="minwidth300" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans("PrescriptionAllergyOverrideReason")).'" value="'.dol_escape_htmltag(GETPOST('allergy_override_reason', 'alphanohtml')).'">';
		} else {
			print '<div>'.$langs->trans($p->error === 'PrescriptionAllergySevereNoOverride' ? "PrescriptionAllergySevereNoOverride" : "PrescriptionAllergyNoOverrideRight").'</div>';
		}
	}
	print '</div>';
}

/**
 * Header rows + line editor shared by create/edit.
 *
 * @param	PrescriptionSheet	$o			Object (unsaved draft on create)
 * @param	bool			$isCreate	Create mode
 * @return	void
 */
function prescription_print_form(PrescriptionSheet $o, $isCreate)
{
	global $langs, $form, $user, $db, $doctors, $departments, $dictDecoct, $dictRoute, $dictFreq, $dictDoseUnit, $decoctModes;

	$isTcm = ($o->presc_type === PRESCRIPTION_TYPE_TCM);
	$v = function ($field) use ($o) {
		return GETPOSTISSET($field) ? GETPOST($field, 'restricthtml') : (string) $o->$field;
	};

	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("PrescriptionType").'</td><td><strong>'.prescription_type_label($o->presc_type).'</strong></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("PrescriptionDoctor").'</td><td>';
	$selDoctor = GETPOSTISSET('fk_doctor') ? GETPOSTINT('fk_doctor') : ($o->fk_doctor ? $o->fk_doctor : (isset($doctors[$user->id]) ? $user->id : 0));
	print $form->selectarray('fk_doctor', $doctors, $selDoctor, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("PrescriptionDepartment").'</td><td>';
	print $form->selectarray('fk_department', $departments, GETPOSTISSET('fk_department') ? GETPOSTINT('fk_department') : (int) $o->fk_department, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("PrescriptionDate").'</td><td>';
	$ts = GETPOSTISSET('date_prescyear') ? dol_mktime(GETPOSTINT('date_preschour'), GETPOSTINT('date_prescmin'), 0, GETPOSTINT('date_prescmonth'), GETPOSTINT('date_prescday'), GETPOSTINT('date_prescyear')) : ($o->date_presc ? $o->date_presc : dol_now());
	print $form->selectDate($ts, 'date_presc', 1, 1, 0, 'formprescription', 1, 1);
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("PrescriptionDiagnosis").'</td><td><input type="text" name="diagnosis_text" class="quatrevingtpercent" maxlength="255" value="'.dol_escape_htmltag(GETPOSTISSET('diagnosis_text') ? GETPOST('diagnosis_text', 'alphanohtml') : (string) $o->diagnosis_text).'"></td></tr>';
	print '</table>';

	// ---- line editor
	$lines = array();
	if (GETPOSTISSET('line_label')) {
		$tmp = new PrescriptionSheet($db);
		prescription_read_form($tmp);
		$lines = $tmp->lines;
	} else {
		$lines = $o->lines;
	}
	$jsLines = array();
	foreach ($lines as $l) {
		$jsLines[] = array(
			'label' => (string) $l['label'], 'fk_product' => (int) ($l['fk_product'] ?? 0), 'product_ref' => (string) ($l['product_ref'] ?? ''),
			'qty' => $l['qty'] !== null && $l['qty'] !== '' ? (string) $l['qty'] : '', 'qty_unit' => (string) ($l['qty_unit'] ?? ''), 'decoct' => (string) ($l['decoct_code'] ?? ''),
			'dose' => $l['dose'] !== null && $l['dose'] !== '' ? (string) $l['dose'] : '', 'dose_unit' => (string) ($l['dose_unit'] ?? ''), 'route' => (string) ($l['route_code'] ?? ''),
			'freq' => (string) ($l['freq_code'] ?? ''), 'days' => $l['days'] !== null && $l['days'] !== '' ? (string) $l['days'] : '', 'sig' => (string) ($l['sig_note'] ?? ''),
			'hit' => (int) ($l['allergy_hit'] ?? 0),
		);
	}
	$opt = function ($dict, $empty = true) {
		$o = $empty ? '<option value=""></option>' : '';
		foreach ($dict as $code => $label) {
			$o .= '<option value="'.dol_escape_htmltag($code).'">'.dol_escape_htmltag($label).'</option>';
		}
		return $o;
	};

	print '<br><div class="titre">'.$langs->trans("PrescriptionLines").'</div>';
	print '<table class="noborder centpercent" id="presc_lines"><thead><tr class="liste_titre">';
	print '<th style="width:28px;">#</th><th>'.$langs->trans("PrescriptionLineDrug").'</th>';
	if ($isTcm) {
		print '<th style="width:90px;">'.$langs->trans("PrescriptionLineGrams").'</th><th style="width:130px;">'.$langs->trans("PrescriptionLineDecoct").'</th>';
	} else {
		print '<th style="width:150px;">'.$langs->trans("PrescriptionLineDose").'</th><th style="width:110px;">'.$langs->trans("PrescriptionLineRoute").'</th>';
		print '<th style="width:120px;">'.$langs->trans("PrescriptionLineFreq").'</th><th style="width:70px;">'.$langs->trans("PrescriptionLineDays").'</th>';
		print '<th style="width:150px;">'.$langs->trans("PrescriptionLineQty").'</th><th>'.$langs->trans("PrescriptionLineSig").'</th>';
	}
	print '<th style="width:30px;"></th></tr></thead><tbody></tbody></table>';
	print '<div style="margin:6px 0;"><a href="#" id="presc_add_line" class="button small">'.$langs->trans("PrescriptionAddLine").'</a> ';
	print '<span class="opacitymedium">'.$langs->trans("PrescriptionLineHint").'</span></div>';

	print '<script>
$(function() {
	var isTcm = '.($isTcm ? 'true' : 'false').';
	var tbody = $("#presc_lines tbody");
	var ajaxUrl = "'.dol_buildpath('/prescription/ajax/product.php', 1).'?type='.($isTcm ? 'TCM' : 'WM').'";
	var optDecoct = '.json_encode($opt($dictDecoct), JSON_UNESCAPED_UNICODE).';
	var optRoute = '.json_encode($opt($dictRoute), JSON_UNESCAPED_UNICODE).';
	var optFreq = '.json_encode($opt($dictFreq), JSON_UNESCAPED_UNICODE).';
	var optDose = '.json_encode($opt($dictDoseUnit), JSON_UNESCAPED_UNICODE).';
	function sel(name, options, value) {
		var s = $("<select class=\"flat\"></select>").attr("name", name).html(options);
		s.val(value || "");
		return s;
	}
	function inp(name, cls, value, attrs) {
		var i = $("<input type=\"text\" class=\"flat " + (cls || "") + "\">").attr("name", name).val(value || "");
		if (attrs) { i.attr(attrs); }
		return i;
	}
	function renumber() { tbody.find("tr").each(function(i) { $(this).find("td.presc-no").text(i + 1); }); }
	function addRow(d) {
		d = d || {};
		var tr = $("<tr class=\"oddeven\"></tr>");
		if (d.hit) { tr.css("background", "#fdecea"); }
		tr.append("<td class=\"presc-no\"></td>");
		var drug = $("<td></td>");
		var label = inp("line_label[]", "quatrevingtpercent presc-label", d.label, {autocomplete: "off", placeholder: "'.dol_escape_js($langs->trans("PrescriptionDrugSearchHint")).'"});
		var fk = $("<input type=\"hidden\" name=\"line_fk_product[]\">").val(d.fk_product || 0);
		var ref = $("<input type=\"hidden\" name=\"line_product_ref[]\">").val(d.product_ref || "");
		var refShow = $("<span class=\"opacitymedium small presc-ref\"></span>").text(d.product_ref ? " " + d.product_ref : "");
		label.on("input", function() { fk.val(0); ref.val(""); refShow.text(""); });
		label.autocomplete({
			minLength: 1,
			source: function(req, resp) { $.getJSON(ajaxUrl, { term: req.term }, function(data) { resp(data); }); },
			select: function(e, ui) { label.val(ui.item.label); fk.val(ui.item.id); ref.val(ui.item.ref); refShow.text(" " + ui.item.ref); if (isTcm) { tr.find("input[name=\"line_qty[]\"]").focus(); } else if (ui.item.unit) { tr.find("input[name=\"line_qty_unit[]\"]").val(ui.item.unit); } return false; }
		});
		drug.append(label).append(fk).append(ref).append(refShow);
		tr.append(drug);
		if (isTcm) {
			tr.append($("<td></td>").append(inp("line_qty[]", "width50 right", d.qty, {inputmode: "decimal"})).append(" ").append(inp("line_qty_unit[]", "width25", d.qty_unit || "g")));
			tr.append($("<td></td>").append(sel("line_decoct[]", optDecoct, d.decoct)));
			tr.append($("<input type=\"hidden\" name=\"line_dose[]\"><input type=\"hidden\" name=\"line_dose_unit[]\"><input type=\"hidden\" name=\"line_route[]\"><input type=\"hidden\" name=\"line_freq[]\"><input type=\"hidden\" name=\"line_days[]\"><input type=\"hidden\" name=\"line_sig[]\">"));
		} else {
			tr.append($("<td></td>").append(inp("line_dose[]", "width50 right", d.dose, {inputmode: "decimal"})).append(" ").append(sel("line_dose_unit[]", optDose, d.dose_unit)));
			tr.append($("<td></td>").append(sel("line_route[]", optRoute, d.route)));
			tr.append($("<td></td>").append(sel("line_freq[]", optFreq, d.freq)));
			tr.append($("<td></td>").append(inp("line_days[]", "width50 right", d.days, {inputmode: "numeric"})));
			tr.append($("<td></td>").append(inp("line_qty[]", "width50 right", d.qty, {inputmode: "decimal"})).append(" ").append(inp("line_qty_unit[]", "width50", d.qty_unit)));
			tr.append($("<td></td>").append(inp("line_sig[]", "quatrevingtpercent", d.sig)));
			tr.append("<input type=\"hidden\" name=\"line_decoct[]\">");
		}
		tr.append($("<td class=\"center\"></td>").append($("<a href=\"#\" title=\"'.dol_escape_js($langs->trans("Remove")).'\">&times;</a>").on("click", function(e) { e.preventDefault(); tr.remove(); renumber(); })));
		tbody.append(tr);
		renumber();
		return tr;
	}
	var initial = '.json_encode($jsLines, JSON_UNESCAPED_UNICODE).';
	if (initial.length) { $.each(initial, function(i, d) { addRow(d); }); } else { addRow(); addRow(); addRow(); }
	$("#presc_add_line").on("click", function(e) { e.preventDefault(); addRow().find(".presc-label").focus(); });
	// Enter in the last row adds a new one instead of submitting
	$("#presc_lines").on("keydown", "input", function(e) {
		if (e.keyCode === 13) { e.preventDefault(); var tr = $(this).closest("tr"); if (tr.is(":last-child")) { addRow().find(".presc-label").focus(); } else { tr.next().find(".presc-label").focus(); } }
	});
});
</script>';

	print '<br><table class="border centpercent">';
	if ($isTcm) {
		print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("PrescriptionDoses").'</td><td><input type="number" min="1" name="doses" class="width50" value="'.(GETPOSTISSET('doses') ? GETPOSTINT('doses') : ($o->doses ? (int) $o->doses : 7)).'"> '.$langs->trans("PrescriptionDosesUnit").'</td></tr>';
		print '<tr><td>'.$langs->trans("PrescriptionDecoctMode").'</td><td>'.$form->selectarray('decoct_mode', $decoctModes, GETPOSTISSET('decoct_mode') ? GETPOST('decoct_mode', 'aZ') : ($o->decoct_mode ? $o->decoct_mode : 'SELF'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td></tr>';
		$usage = GETPOSTISSET('usage_note') ? GETPOST('usage_note', 'restricthtml') : ($o->usage_note !== null && $o->usage_note !== '' ? $o->usage_note : getDolGlobalString('PRESCRIPTION_TCM_DEFAULT_USAGE'));
		print '<tr><td class="tdtop">'.$langs->trans("PrescriptionUsage").'</td><td><textarea name="usage_note" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($usage).'</textarea></td></tr>';
	} else {
		print '<tr><td class="titlefieldcreate tdtop">'.$langs->trans("PrescriptionUsage").'</td><td><textarea name="usage_note" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($v('usage_note')).'</textarea></td></tr>';
	}
	print '<tr><td class="tdtop">'.$langs->trans("PrescriptionNote").'</td><td><textarea name="note" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($v('note')).'</textarea></td></tr>';
	print '</table>';
}

// ---------------------------------------------------------------- create
if ($action == 'create') {
	if (!$canWrite) {
		accessforbidden();
	}
	$draft = $blocked ? $blocked : ($prefill ? $prefill : new PrescriptionSheet($db));
	if (!$blocked && !$prefill) {
		$draft->presc_type = $typeParam;
	}
	if ($fkPatientParam > 0) {
		$draft->fk_patient = $fkPatientParam;
	}
	if ($fkMedrecordParam > 0) {
		$draft->fk_medrecord = $fkMedrecordParam;
	}
	print load_fiche_titre($langs->trans("PrescriptionNew").' — '.prescription_type_label($draft->presc_type), '', 'fa-prescription');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formprescription">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="presc_type" value="'.$draft->presc_type.'">';
	if ($draft->fk_medrecord > 0) {
		print '<input type="hidden" name="fk_medrecord" value="'.((int) $draft->fk_medrecord).'">';
	}
	print dol_get_fiche_head(array(), '', '', -1);
	if ($blocked) {
		prescription_print_allergy_panel($blocked);
	}
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("PrescriptionPatient").'</td><td>';
	if ($draft->fk_patient > 0) {
		print '<input type="hidden" name="fk_patient" value="'.((int) $draft->fk_patient).'">';
		$trail = array();
		if ($draft->fk_medrecord > 0) {
			$trail[] = array('label' => $langs->trans("MedRecordTab"), 'url' => dol_buildpath('/medrecord/patient_tab.php', 1).'?id='.((int) $draft->fk_patient));
			$recLabel = '#'.(int) $draft->fk_medrecord;
			if (isModEnabled('medrecord')) {
				dol_include_once('/medrecord/class/medicalrecord.class.php');
				$tmpRec = new MedicalRecord($db);
				if ($tmpRec->fetch($draft->fk_medrecord) > 0) {
					$recLabel = $tmpRec->ref;
				}
			}
			$trail[] = array('label' => $recLabel, 'url' => dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $draft->fk_medrecord));
		} else {
			$trail[] = array('label' => $langs->trans("PrescriptionTab"), 'url' => dol_buildpath('/prescription/patient_tab.php', 1).'?id='.((int) $draft->fk_patient));
		}
		$trail[] = array('label' => $langs->trans("PrescriptionNew"));
		print patient_summary_banner(patient_get_summary($db, $draft->fk_patient), $trail, 'prescription');
	} else {
		print patient_select_html($db, 'fk_patient', GETPOSTINT('fk_patient'));
	}
	print '</td></tr>';
	if ($draft->fk_medrecord > 0 && isModEnabled('medrecord')) {
		dol_include_once('/medrecord/class/medicalrecord.class.php');
		$rec = new MedicalRecord($db);
		if ($rec->fetch($draft->fk_medrecord) > 0) {
			print '<tr><td>'.$langs->trans("PrescriptionMedRecord").'</td><td>'.$rec->getNomUrl(1).' '.$rec->getLibStatut().'</td></tr>';
		}
	}
	print '</table>';
	prescription_print_form($draft, true);
	print dol_get_fiche_end();
	print $form->buttonsSaveCancel("Create");
	print '</form>';
} elseif ($object->id > 0) {
	$head = prescription_prepare_head($object);
	print dol_get_fiche_head($head, 'card', $langs->trans("PrescriptionTab"), -1, 'fa-prescription');

	$summary = patient_get_summary($db, $object->fk_patient);
	$linkback = '<a href="'.dol_buildpath('/prescription/list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
	print '<div class="arearef heightref valignmiddle centpercent">';
	print '<div class="inline-block floatleft refid refidpadding">'.img_picto('', 'fa-prescription', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($object->ref).'</strong> '.$object->getLibStatut().' <span class="opacitymedium">'.prescription_type_label($object->presc_type).'</span></div>';
	print '<div class="inline-block floatright">'.$linkback.'</div>';
	print '<div class="clearboth"></div></div>';
	print '<div class="underbanner clearboth"></div>';
	$trail = array();
	if ($object->fk_medrecord > 0) {
		$trail[] = array('label' => $langs->trans("MedRecordTab"), 'url' => dol_buildpath('/medrecord/patient_tab.php', 1).'?id='.((int) $object->fk_patient));
		$recLabel = '#'.(int) $object->fk_medrecord;
		if (isModEnabled('medrecord')) {
			dol_include_once('/medrecord/class/medicalrecord.class.php');
			$tmpRec = new MedicalRecord($db);
			if ($tmpRec->fetch($object->fk_medrecord) > 0) {
				$recLabel = $tmpRec->ref;
			}
		}
		$trail[] = array('label' => $recLabel, 'url' => dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $object->fk_medrecord));
	} else {
		$trail[] = array('label' => $langs->trans("PrescriptionTab"), 'url' => dol_buildpath('/prescription/patient_tab.php', 1).'?id='.((int) $object->fk_patient));
	}
	$trail[] = array('label' => $object->ref);
	print patient_summary_banner($summary, $trail, 'prescription');

	if ($action == 'edit' && $object->canEdit($user)) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" name="formprescription">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="update">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		if ($blocked) {
			prescription_print_allergy_panel($blocked);
		}
		prescription_print_form($blocked ? $blocked : $object, false);
		print $form->buttonsSaveCancel();
		print '</form>';
	} else {
		if ($action == 'issue' && $object->canIssue($user)) {
			$q = array();
			$max = $object->checkAllergies();
			if ($max > 0 && $max < 3 && $user->hasRight('prescription', 'override')) {
				$q[] = array('type' => 'checkbox', 'name' => 'allergy_override', 'label' => $langs->trans("PrescriptionAllergyOverride"), 'value' => $object->allergy_override_reason ? 1 : 0);
				$q[] = array('type' => 'text', 'name' => 'allergy_override_reason', 'label' => $langs->trans("PrescriptionAllergyOverrideReason"), 'value' => (string) $object->allergy_override_reason, 'size' => 60);
			}
			print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans("PrescriptionIssue"), $langs->trans("PrescriptionConfirmIssue"), 'confirm_issue', $q, 0, 1);
		}
		if ($action == 'void' && $object->canVoid($user)) {
			$q = array(array('type' => 'text', 'name' => 'void_reason', 'label' => $langs->trans("PrescriptionVoidReason"), 'value' => '', 'size' => 60));
			print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans("PrescriptionVoid"), $langs->trans("PrescriptionConfirmVoid"), 'confirm_void', $q, 0, 1);
		}

		print '<div class="fichecenter"><table class="border tableforfield centpercent">';
		print '<tr><td class="titlefield">'.$langs->trans("PrescriptionDoctor").'</td><td>'.dol_escape_htmltag(isset($doctors[$object->fk_doctor]) ? $doctors[$object->fk_doctor] : '#'.$object->fk_doctor);
		if ($object->fk_department && isset($departments[$object->fk_department])) {
			print ' <span class="opacitymedium">'.dol_escape_htmltag($departments[$object->fk_department]).'</span>';
		}
		print '</td></tr>';
		print '<tr><td>'.$langs->trans("PrescriptionDate").'</td><td>'.dol_print_date($object->date_presc, 'dayhour').'</td></tr>';
		if ($object->fk_medrecord && isModEnabled('medrecord')) {
			dol_include_once('/medrecord/class/medicalrecord.class.php');
			$rec = new MedicalRecord($db);
			if ($rec->fetch($object->fk_medrecord) > 0) {
				print '<tr><td>'.$langs->trans("PrescriptionMedRecord").'</td><td>'.$rec->getNomUrl(1).' '.$rec->getLibStatut().'</td></tr>';
			}
		}
		print '<tr><td>'.$langs->trans("PrescriptionDiagnosis").'</td><td>'.dol_escape_htmltag((string) $object->diagnosis_text).'</td></tr>';
		print '</table>';

		// Lines
		$isTcm = ($object->presc_type === PRESCRIPTION_TYPE_TCM);
		print '<br><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
		print '<th style="width:28px;">#</th><th>'.$langs->trans("PrescriptionLineDrug").'</th>';
		if ($isTcm) {
			print '<th>'.$langs->trans("PrescriptionLineGrams").'</th><th>'.$langs->trans("PrescriptionLineDecoct").'</th>';
		} else {
			print '<th>'.$langs->trans("PrescriptionLineDose").'</th><th>'.$langs->trans("PrescriptionLineRoute").'</th><th>'.$langs->trans("PrescriptionLineFreq").'</th>';
			print '<th>'.$langs->trans("PrescriptionLineDays").'</th><th>'.$langs->trans("PrescriptionLineQty").'</th><th>'.$langs->trans("PrescriptionLineSig").'</th>';
		}
		print '</tr>';
		$fmt = function ($n) {
			return $n === null ? '' : rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
		};
		foreach ($object->lines as $i => $l) {
			print '<tr class="oddeven"'.($l['allergy_hit'] ? ' style="background:#fdecea;"' : '').'>';
			print '<td>'.($i + 1).'</td>';
			print '<td>'.($l['allergy_hit'] ? '<span class="error" title="'.dol_escape_htmltag($langs->trans("PrescriptionAllergyHit")).'">※</span> ' : '').dol_escape_htmltag($l['label']).($l['product_ref'] ? ' <span class="opacitymedium small">'.dol_escape_htmltag($l['product_ref']).'</span>' : '').'</td>';
			if ($isTcm) {
				print '<td>'.$fmt($l['qty']).dol_escape_htmltag((string) $l['qty_unit']).'</td>';
				print '<td>'.dol_escape_htmltag(isset($dictDecoct[$l['decoct_code']]) ? $dictDecoct[$l['decoct_code']] : (string) $l['decoct_code']).'</td>';
			} else {
				print '<td>'.$fmt($l['dose']).' '.dol_escape_htmltag(isset($dictDoseUnit[$l['dose_unit']]) ? $dictDoseUnit[$l['dose_unit']] : (string) $l['dose_unit']).'</td>';
				print '<td>'.dol_escape_htmltag(isset($dictRoute[$l['route_code']]) ? $dictRoute[$l['route_code']] : (string) $l['route_code']).'</td>';
				print '<td>'.dol_escape_htmltag(isset($dictFreq[$l['freq_code']]) ? $dictFreq[$l['freq_code']] : (string) $l['freq_code']).'</td>';
				print '<td>'.($l['days'] !== null ? (int) $l['days'] : '').'</td>';
				print '<td>'.$fmt($l['qty']).' '.dol_escape_htmltag((string) $l['qty_unit']).'</td>';
				print '<td>'.dol_escape_htmltag((string) $l['sig_note']).'</td>';
			}
			print '</tr>';
		}
		print '</table></div>';

		print '<table class="border tableforfield centpercent" style="margin-top:8px;">';
		if ($isTcm) {
			print '<tr><td class="titlefield">'.$langs->trans("PrescriptionDoses").'</td><td>'.((int) $object->doses).' '.$langs->trans("PrescriptionDosesUnit").($object->decoct_mode && isset($decoctModes[$object->decoct_mode]) ? ' &middot; '.$decoctModes[$object->decoct_mode] : '').'</td></tr>';
		}
		print '<tr><td class="titlefield tdtop">'.$langs->trans("PrescriptionUsage").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->usage_note)).'</td></tr>';
		print '<tr><td class="tdtop">'.$langs->trans("PrescriptionNote").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->note)).'</td></tr>';
		if ($object->allergy_override_reason) {
			print '<tr><td>'.$langs->trans("PrescriptionAllergyReleased").'</td><td class="error">'.dol_escape_htmltag($object->allergy_override_reason).'</td></tr>';
		}
		if ($object->status >= PRESCRIPTION_STATUS_ISSUED && $object->fk_user_issue) {
			print '<tr><td>'.$langs->trans("PrescriptionIssuedBy").'</td><td>'.dol_escape_htmltag(isset($doctors[$object->fk_user_issue]) ? $doctors[$object->fk_user_issue] : '#'.$object->fk_user_issue).' '.dol_print_date($object->date_issued, 'dayhour').'</td></tr>';
		}
		if ($object->status == PRESCRIPTION_STATUS_VOIDED) {
			print '<tr><td>'.$langs->trans("PrescriptionVoidReason").'</td><td class="error">'.dol_escape_htmltag((string) $object->void_reason).' <span class="opacitymedium">('.dol_print_date($object->date_void, 'dayhour').')</span></td></tr>';
		}
		print '<tr><td>'.$langs->trans("DateCreation").'</td><td>'.dol_print_date($object->date_creation, 'dayhour').'</td></tr>';
		print '</table></div>';

		print dol_get_fiche_end();

		// clinicpay charge linkage + double-charge guard
		$chargedBillId = 0;
		if (isModEnabled('clinicpay')) {
			$sqlc = "SELECT l.fk_bill as bid FROM ".$db->prefix()."clinicpay_bill_line l";
			$sqlc .= " JOIN ".$db->prefix()."clinicpay_bill b ON l.fk_bill = b.rowid";
			$sqlc .= " WHERE l.fk_prescription = ".((int) $object->id)." AND b.fk_patient = ".((int) $object->fk_patient)." LIMIT 1";
			$resc = $db->query($sqlc);
			if ($resc && ($objc = $db->fetch_object($resc))) {
				$chargedBillId = (int) $objc->bid;
			}
		}

		print '<div class="tabsAction">';
		if ($object->canEdit($user)) {
			print dolGetButtonAction($langs->trans("Modify"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=edit&token='.newToken(), '', 1);
		}
		if ($object->canIssue($user)) {
			print dolGetButtonAction($langs->trans("PrescriptionIssue"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=issue&token='.newToken(), '', 1);
		}
		if ($canWrite) {
			print dolGetButtonAction($langs->trans("PrescriptionCopy"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=copy&token='.newToken(), '', 1);
		}
		if ($object->canVoid($user)) {
			print dolGetButtonAction($langs->trans("PrescriptionVoid"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=void&token='.newToken(), '', 1);
		}
		print dolGetButtonAction($langs->trans("PrescriptionSheetPdf"), '', 'default', dol_buildpath('/prescription/pdf.php', 1).'?id='.$object->id, '', 1, array('attr' => array('target' => '_blank')));
		if (isModEnabled('clinicpay') && $user->hasRight('clinicpay', 'write')) {
			if ($chargedBillId > 0) {
				print dolGetButtonAction($langs->trans("PrescriptionAlreadyCharged"), '', 'default', dol_buildpath('/clinicpay/bill.php', 1).'?id='.$chargedBillId, '', 1);
			} else {
				print dolGetButtonAction($langs->trans("PrescriptionCharge"), '', 'default', dol_buildpath('/clinicpay/bill.php', 1).'?action=create&fk_patient='.((int) $object->fk_patient).'&fk_prescription='.((int) $object->id).'&token='.newToken(), '', 1);
			}
		}
		print '</div>';

		// Extension point for modPharmacy: dispense button + dispense sheets
		$parameters = array('object' => $object);
		$reshook = $hookmanager->executeHooks('prescriptionCard', $parameters, $object, $action);
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}
		print $hookmanager->resPrint;
	}
} else {
	print load_fiche_titre($langs->trans("PrescriptionTab"), '', 'fa-prescription');
	print '<div class="opacitymedium">'.$langs->trans("ErrorRecordNotFound").'</div>';
}

llxFooter();
$db->close();
