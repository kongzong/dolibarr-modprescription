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
 * \file    htdocs/custom/prescription/template.php
 * \brief   Manage reusable prescription templates (list + edit + delete).
 *          Templates are global (per entity) row sets for the prescribing
 *          form; rows mirror the prescription_line snapshot columns.
 *          Requires 'prescription read' (write to modify).
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
dol_include_once('/prescription/lib/prescription.lib.php');
dol_include_once('/prescription/class/prescriptiontemplate.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var HookManager $hookmanager
 */

$langs->loadLangs(array("patient@patient", "prescription@prescription"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$typeParam = GETPOST('presc_type', 'aZ') === 'WM' ? 'WM' : 'TCM';

$canRead = $user->hasRight('prescription', 'read');
$canWrite = $user->hasRight('prescription', 'write');
if (!$canRead) {
	accessforbidden();
}

$form = new Form($db);
$object = new PrescriptionTemplate($db);
$dictDecoct = prescription_dict_options($db, 'c_prescription_decoct');
$dictRoute = prescription_dict_options($db, 'c_prescription_route');
$dictFreq = prescription_dict_options($db, 'c_prescription_freq');
$dictDoseUnit = prescription_dict_options($db, 'c_prescription_dose_unit');

$errors = array();

// ---------------------------------------------------------------- actions
if (($action == 'save' || $action == 'confirm_delete') && $canWrite) {
	if ($action == 'confirm_delete' && $confirm == 'yes') {
		if ($object->fetch($id) > 0) {
			$object->delete();
		}
		header("Location: ".dol_buildpath('/prescription/template.php', 1));
		exit;
	}
	if ($action == 'save' && !GETPOST('cancel', 'alpha')) {
		$readLines = function () {
			$labels = GETPOST('line_label', 'array');
			$out = array();
			foreach ((array) $labels as $i => $label) {
				$get = function ($name) use ($i) {
					$arr = GETPOST($name, 'array');
					return isset($arr[$i]) ? $arr[$i] : '';
				};
				$out[] = array(
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
			return $out;
		};
		$object->name = trim(GETPOST('tpl_name', 'alphanohtml'));
		$object->presc_type = GETPOST('presc_type', 'aZ') === 'WM' ? 'WM' : 'TCM';
		$object->doses = GETPOSTINT('doses');
		$object->diagnosis_text = trim(GETPOST('diagnosis_text', 'alphanohtml'));
		$object->note = GETPOST('tpl_note', 'alphanohtml');
		$object->lines = $readLines();
		if ($object->name === '') {
			$errors[] = $langs->trans("PrescriptionTplErrName");
		} elseif (count(array_filter($object->lines, function ($l) { return trim((string) $l['label']) !== ''; })) === 0) {
			$errors[] = $langs->trans("PrescriptionTplErrLines");
		} else {
			if ($id > 0) {
				$object->rowid = $id;
				$ok = $object->update($user);
			} else {
				$ok = $object->create($user);
			}
			if ($ok > 0) {
				header("Location: ".dol_buildpath('/prescription/template.php', 1).'?saved=1');
				exit;
			}
			$errors[] = $langs->trans((string) $object->error);
		}
	} elseif ($action == 'save') {
		header("Location: ".dol_buildpath('/prescription/template.php', 1));
		exit;
	}
}

// ---------------------------------------------------------------- state
$editing = ($action == 'create' || ($action == 'edit' && $id > 0)) && $canWrite;
if ($editing && $id > 0) {
	if ($object->fetch($id) <= 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
}
if ($action == 'create' && !$id) {
	$object = new PrescriptionTemplate($db);
	$object->presc_type = $typeParam;
	$object->doses = 7;
}

llxHeader('', $langs->trans("PrescriptionTplTitle"));

print load_fiche_titre($langs->trans("PrescriptionTplTitle"), '', 'fa-prescription');

// Validation errors are printed inline: setEventMessages() after llxHeader()
// only surfaces on the next page load, so a rejected save looked like a silent
// no-op (nothing saved, no message).
foreach ($errors as $e) {
	print '<div class="error" style="padding:6px 10px;">'.dol_escape_htmltag($e).'</div>';
}
if (GETPOSTINT('saved') === 1) {
	print '<div class="ok" style="padding:6px 10px;">'.dol_escape_htmltag($langs->trans("RecordSaved")).'</div>';
}

// ---------------------------------------------------------------- edit view
if ($editing) {
	$isTcm = ($object->presc_type === 'TCM');
	$jsLines = array();
	foreach ($object->lines as $l) {
		$jsLines[] = array(
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

	$typeSelect = $form->selectarray('presc_type', array('TCM' => prescription_type_label('TCM'), 'WM' => prescription_type_label('WM')), $object->presc_type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
	print '<input type="hidden" name="id" value="'.(int) $object->rowid.'">';

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("PrescriptionTplName").'</td><td><input type="text" name="tpl_name" class="minwidth300" value="'.dol_escape_htmltag($object->name).'" maxlength="128"></td></tr>';
	print '<tr><td>'.$langs->trans("PrescriptionType").'</td><td>'.$typeSelect.'</td></tr>';
	print '<tr><td>'.$langs->trans("PrescriptionDoses").'</td><td><input type="number" min="1" name="doses" class="width50" value="'.(int) ($object->doses ?: 7).'"> '.$langs->trans("PrescriptionDosesUnit").' <span class="opacitymedium">('.dol_escape_htmltag($langs->trans("PrescriptionTplDosesHint")).')</span></td></tr>';
	print '<tr><td>'.$langs->trans("PrescriptionDiagnosis").'</td><td><input type="text" name="diagnosis_text" class="minwidth300" value="'.dol_escape_htmltag((string) $object->diagnosis_text).'" maxlength="255"></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("PrescriptionNote").'</td><td><textarea name="tpl_note" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag((string) $object->note).'</textarea></td></tr>';
	print '</table>';

	print '<h3 style="margin:16px 0 6px;font-size:14px;">'.$langs->trans("PrescriptionLines").'</h3>';
	print '<button type="button" id="tpl_add_line" class="butActionSmall" style="margin-bottom:6px;">+ '.$langs->trans("AddLine").'</button>';
	print '<table id="tpl_lines" class="noborder centpercent"><thead><tr class="liste_titre">';
	print '<th class="center" style="width:34px;">#</th><th>'.$langs->trans("PrescriptionColDrug").'</th>';
	if ($isTcm) {
		print '<th>'.$langs->trans("PrescriptionColQtyPerDose").'</th><th>'.$langs->trans("PrescriptionColDecoct").'</th>';
	} else {
		print '<th>'.$langs->trans("PrescriptionColDose").'</th><th>'.$langs->trans("PrescriptionLineRoute").'</th><th>'.$langs->trans("PrescriptionLineFreq").'</th><th>'.$langs->trans("PrescriptionColDays").'</th><th>'.$langs->trans("PrescriptionColQty").'</th><th>'.$langs->trans("PrescriptionColSig").'</th>';
	}
	print '<th class="center" style="width:34px;"></th></tr></thead><tbody></tbody></table>';

	print '<div class="center" style="margin-top:10px;">';
	print '<input type="submit" class="button" name="submit" value="'.$langs->trans("Save").'">';
	print ' &nbsp; <a class="button button-cancel" href="'.dol_buildpath('/prescription/template.php', 1).'">'.$langs->trans("Cancel").'</a>';
	print '</div>';

	print '<script type="text/javascript">
$(function() {
	var isTcm = '.($isTcm ? 'true' : 'false').';
	var optDecoct = '.json_encode($dictDecoct, JSON_UNESCAPED_UNICODE).';
	var optRoute = '.json_encode($dictRoute, JSON_UNESCAPED_UNICODE).';
	var optFreq = '.json_encode($dictFreq, JSON_UNESCAPED_UNICODE).';
	var optDose = '.json_encode($dictDoseUnit, JSON_UNESCAPED_UNICODE).';
	var tbody = $("#tpl_lines tbody");
	function sel(name, options, value) {
		var s = $("<select class=\"flat\"></select>").attr("name", name);
		$.each(options, function(k, v) { s.append($("<option></option>").attr("value", k).text(v)); });
		if (value) { s.val(value); }
		return s;
	}
	function inp(name, cls, value) {
		return $("<input type=\"text\" class=\"flat " + (cls || "") + "\">").attr("name", name).val(value || "");
	}
	function renumber() { tbody.find("tr").each(function(i) { $(this).find("td.tpl-no").text(i + 1); }); }
	function addRow(d) {
		d = d || {};
		var tr = $("<tr class=\"oddeven\"></tr>");
		tr.append("<td class=\"tpl-no center\"></td>");
		var drug = $("<td></td>");
		var label = inp("line_label[]", "quatrevingtpercent tpl-label", d.label);
		var fk = $("<input type=\"hidden\" name=\"line_fk_product[]\">").val(d.fk_product || 0);
		var ref = $("<input type=\"hidden\" name=\"line_product_ref[]\">").val(d.product_ref || "");
		label.on("input", function() { fk.val(0); ref.val(""); });
		drug.append(label).append(fk).append(ref);
		tr.append(drug);
		if (isTcm) {
			tr.append($("<td></td>").append(inp("line_qty[]", "width50 right", d.qty)).append(" ").append(inp("line_qty_unit[]", "width25", d.qty_unit || "g")));
			tr.append($("<td></td>").append(sel("line_decoct[]", optDecoct, d.decoct)));
			tr.append($("<input type=\"hidden\" name=\"line_dose[]\"><input type=\"hidden\" name=\"line_dose_unit[]\"><input type=\"hidden\" name=\"line_route[]\"><input type=\"hidden\" name=\"line_freq[]\"><input type=\"hidden\" name=\"line_days[]\"><input type=\"hidden\" name=\"line_sig[]\">"));
		} else {
			tr.append($("<td></td>").append(inp("line_dose[]", "width50 right", d.dose)).append(" ").append(sel("line_dose_unit[]", optDose, d.dose_unit)));
			tr.append($("<td></td>").append(sel("line_route[]", optRoute, d.route)));
			tr.append($("<td></td>").append(sel("line_freq[]", optFreq, d.freq)));
			tr.append($("<td></td>").append(inp("line_days[]", "width50 right", d.days)));
			tr.append($("<td></td>").append(inp("line_qty[]", "width50 right", d.qty)).append(" ").append(inp("line_qty_unit[]", "width50", d.qty_unit)));
			tr.append($("<td></td>").append(inp("line_sig[]", "quatrevingtpercent", d.sig)));
			tr.append("<input type=\"hidden\" name=\"line_decoct[]\">");
		}
		tr.append($("<td class=\"center\"></td>").append($("<a href=\"#\">&times;</a>").on("click", function(e) { e.preventDefault(); tr.remove(); renumber(); })));
		tbody.append(tr);
		renumber();
		return tr;
	}
	var initial = '.json_encode($jsLines, JSON_UNESCAPED_UNICODE).';
	if (initial.length) { $.each(initial, function(i, d) { addRow(d); }); } else { addRow(); }
	$("#tpl_add_line").on("click", function(e) { e.preventDefault(); addRow().find(".tpl-label").focus(); });
});
</script>';
	print '</form>';
	exit;
}

// ---------------------------------------------------------------- list view
$templates = PrescriptionTemplate::listAll($db);
$newUrl = dol_buildpath('/prescription/template.php', 1);

print '<div style="margin-bottom:10px;">';
if ($canWrite) {
	print '<a class="butAction" href="'.$newUrl.'?action=create&presc_type=TCM">+ '.$langs->trans("PrescriptionTplNewTcm").'</a> ';
	print '<a class="butAction" href="'.$newUrl.'?action=create&presc_type=WM">+ '.$langs->trans("PrescriptionTplNewWm").'</a>';
}
print '</div>';

print '<table class="noborder centpercent"><thead><tr class="liste_titre">';
print '<th>'.$langs->trans("PrescriptionTplName").'</th>';
print '<th class="center">'.$langs->trans("PrescriptionType").'</th>';
print '<th class="center">'.$langs->trans("PrescriptionDoses").'</th>';
print '<th class="center">'.$langs->trans("PrescriptionColSig").'</th>';
print '<th class="center">'.$langs->trans("PrescriptionTplDiagnosis").'</th>';
print '<th class="center" style="width:120px;"></th>';
print '</tr></thead><tbody>';

if (empty($templates)) {
	print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans("PrescriptionTplEmpty").'</span></td></tr>';
}
foreach ($templates as $t) {
	print '<tr class="oddeven">';
	print '<td><a href="'.$newUrl.'?action=edit&id='.$t['id'].'">'.dol_escape_htmltag($t['name']).'</a></td>';
	print '<td class="center">'.dol_escape_htmltag(prescription_type_label($t['presc_type'])).'</td>';
	print '<td class="center">'.($t['presc_type'] === 'TCM' && $t['doses'] ? (int) $t['doses'] : '—').'</td>';
	print '<td class="center">'.(int) $t['nlines'].'</td>';
	print '<td class="center">'.dol_escape_htmltag((string) $t['diagnosis_text']).'</td>';
	print '<td class="center">';
	if ($canWrite) {
		print '<a class="paddingright" href="'.$newUrl.'?action=edit&id='.$t['id'].'" title="'.dol_escape_htmltag($langs->trans("Edit")).'"><span class="fas fa-edit"></span></a>';
		print '<a href="'.$newUrl.'?action=delete&id='.$t['id'].'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans("Delete")).'"><span class="fas fa-trash" style="color:#c0392b;"></span></a>';
	}
	print '</td></tr>';
}
print '</tbody></table>';

if ($action == 'delete' && $id > 0 && $canWrite && $object->fetch($id) > 0) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.'&action=delete', $langs->trans("PrescriptionTplDelete"), $langs->trans("PrescriptionTplConfirmDelete", $object->name), 'confirm_delete', array(), 0, 1);
}
