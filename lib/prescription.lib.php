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
 * \file    htdocs/custom/prescription/lib/prescription.lib.php
 * \ingroup prescription
 * \brief   Shared helpers: status labels, dictionary lookups, lists by
 *          patient / medical record, tabs. Audit goes through modPatient's
 *          patient_audit() with PRESCRIPTION_* actions.
 */

dol_include_once('/patient/lib/patient.lib.php');

/** Status values (spec §3.4); 2 = dispensed is reserved for modPharmacy */
define('PRESCRIPTION_STATUS_DRAFT', 0);
define('PRESCRIPTION_STATUS_ISSUED', 1);
define('PRESCRIPTION_STATUS_DISPENSED', 2);
define('PRESCRIPTION_STATUS_VOIDED', 9);

/** PrescriptionSheet types */
define('PRESCRIPTION_TYPE_TCM', 'TCM');
define('PRESCRIPTION_TYPE_WM', 'WM');

/**
 * @param	int		$status		Status value
 * @return	string				Translated label
 */
function prescription_status_label($status)
{
	global $langs;
	$langs->load('prescription@prescription');
	switch ((int) $status) {
		case PRESCRIPTION_STATUS_ISSUED:
			return $langs->trans('PrescriptionStatusIssued');
		case PRESCRIPTION_STATUS_DISPENSED:
			return $langs->trans('PrescriptionStatusDispensed');
		case PRESCRIPTION_STATUS_VOIDED:
			return $langs->trans('PrescriptionStatusVoided');
		default:
			return $langs->trans('PrescriptionStatusDraft');
	}
}

/**
 * @param	int		$status		Status value
 * @return	string				Badge HTML
 */
function prescription_status_badge($status)
{
	$cls = array(PRESCRIPTION_STATUS_DRAFT => 'badge-status0', PRESCRIPTION_STATUS_ISSUED => 'badge-status4',
		PRESCRIPTION_STATUS_DISPENSED => 'badge-status6', PRESCRIPTION_STATUS_VOIDED => 'badge-status9');
	$status = (int) $status;
	return '<span class="badge '.(isset($cls[$status]) ? $cls[$status] : 'badge-status0').'">'.prescription_status_label($status).'</span>';
}

/**
 * @param	string	$type	TCM | WM
 * @return	string			Translated label
 */
function prescription_type_label($type)
{
	global $langs;
	$langs->load('prescription@prescription');
	return $langs->trans($type === PRESCRIPTION_TYPE_WM ? 'PrescriptionTypeWM' : 'PrescriptionTypeTCM');
}

/**
 * Active rows of a module dictionary as code => label.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	c_prescription_decoct | _route | _freq | _dose_unit
 * @return	array<string,string>
 */
function prescription_dict_options($db, $table)
{
	if (!preg_match('/^c_prescription_[a-z_]+$/', $table)) {
		return array();
	}
	$out = array();
	$resql = $db->query("SELECT code, label FROM ".$db->prefix().$table." WHERE active = 1 ORDER BY pos ASC, label ASC");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[$obj->code] = $obj->label;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Label of a dictionary code (live), code itself when unknown, '' when empty.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	Dictionary table without prefix
 * @param	string	$code	Code
 * @return	string
 */
function prescription_dict_label($db, $table, $code)
{
	$code = trim((string) $code);
	if ($code === '') {
		return '';
	}
	$options = prescription_dict_options($db, $table);
	return isset($options[$code]) ? $options[$code] : $code;
}

/**
 * Prescriptions of a medical record (for the hook block on the record card).
 *
 * @param	DoliDB	$db				Database handler
 * @param	int		$fkMedrecord	Record rowid
 * @param	bool	$includeVoided	Include voided
 * @return	array<int,object>
 */
function prescription_list_by_medrecord($db, $fkMedrecord, $includeVoided = true)
{
	global $conf;

	$sql = "SELECT p.rowid, p.ref, p.presc_type, p.status, p.date_presc, p.doses, p.fk_doctor,";
	$sql .= " (SELECT COUNT(l.rowid) FROM ".$db->prefix()."prescription_line as l WHERE l.fk_prescription = p.rowid) as nb_lines";
	$sql .= " FROM ".$db->prefix()."prescription as p";
	$sql .= " WHERE p.fk_medrecord = ".((int) $fkMedrecord)." AND p.entity = ".((int) $conf->entity);
	if (!$includeVoided) {
		$sql .= " AND p.status <> ".PRESCRIPTION_STATUS_VOIDED;
	}
	$sql .= " ORDER BY p.date_presc DESC, p.rowid DESC";
	return prescription_fetch_rows($db, $sql);
}

/**
 * Prescriptions of a patient (timeline on the patient tab).
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkPatient	Patient rowid
 * @param	int		$limit		Max rows
 * @param	bool	$includeVoided	Include voided
 * @return	array<int,object>
 */
function prescription_list_by_patient($db, $fkPatient, $limit = 50, $includeVoided = true)
{
	global $conf;

	$sql = "SELECT p.rowid, p.ref, p.presc_type, p.status, p.date_presc, p.doses, p.fk_doctor, p.fk_medrecord, p.diagnosis_text, m.ref as medrecord_ref,";
	$sql .= " u.lastname, u.firstname,";
	$sql .= " (SELECT COUNT(l.rowid) FROM ".$db->prefix()."prescription_line as l WHERE l.fk_prescription = p.rowid) as nb_lines";
	$sql .= " FROM ".$db->prefix()."prescription as p";
	$sql .= " LEFT JOIN ".$db->prefix()."user as u ON u.rowid = p.fk_doctor";
	$sql .= " LEFT JOIN ".$db->prefix()."medrecord as m ON m.rowid = p.fk_medrecord";
	$sql .= " WHERE p.fk_patient = ".((int) $fkPatient)." AND p.entity = ".((int) $conf->entity);
	if (!$includeVoided) {
		$sql .= " AND p.status <> ".PRESCRIPTION_STATUS_VOIDED;
	}
	$sql .= " ORDER BY p.date_presc DESC, p.rowid DESC".$db->plimit(max(1, (int) $limit), 0);
	return prescription_fetch_rows($db, $sql);
}

/**
 * @param	DoliDB	$db		Database handler
 * @param	string	$sql	Query
 * @return	array<int,object>
 */
function prescription_fetch_rows($db, $sql)
{
	$out = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[] = $obj;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Tabs of the prescription card.
 *
 * @param	object	$object		Loaded prescription (id)
 * @return	array<int,array{0:string,1:string,2:string}>
 */
function prescription_prepare_head($object)
{
	global $langs;

	$langs->load('prescription@prescription');
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/prescription/card.php', 1).'?id='.((int) $object->id);
	$head[$h][1] = $langs->trans('PrescriptionTab');
	$head[$h][2] = 'card';
	$h++;
	return $head;
}

/**
 * Tabs for the module admin pages.
 *
 * @return	array<int,array{0:string,1:string,2:string}>
 */
function prescription_admin_prepare_head()
{
	global $langs;

	$langs->load('prescription@prescription');
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/prescription/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;
	return $head;
}
