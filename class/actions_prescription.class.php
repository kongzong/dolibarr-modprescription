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
 * \file    htdocs/custom/prescription/class/actions_prescription.class.php
 * \ingroup prescription
 * \brief   Hooks: inject "new TCM / western prescription" buttons and the
 *          list of prescriptions into the medical record card (context
 *          'medrecordcard', method printMedRecordCard). Class name follows
 *          HookManager's Actions<ucfirst(module)> rule (DEV.md §二.11).
 */

/**
 * Class ActionsPrescription
 */
class ActionsPrescription
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var array<string,mixed> Results
	 */
	public $results = array();

	/**
	 * @var string HTML to print
	 */
	public $resprints = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Output hook of modMedRecord's card.php: buttons to open a prescription
	 * for this record (draft/signed records only) and the list of existing ones.
	 *
	 * @param	array		$parameters		Hook parameters ('object' => MedRecord)
	 * @param	object		$object			Record
	 * @param	string		$action			Current action
	 * @param	HookManager	$hookmanager	Hook manager
	 * @return	int							0 = keep standard flow, <0 error
	 */
	public function printMedRecordCard($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!in_array('medrecordcard', explode(':', (string) $parameters['context']))) {
			return 0;
		}
		if (empty($object->id) || !$user->hasRight('prescription', 'read')) {
			return 0;
		}
		dol_include_once('/prescription/lib/prescription.lib.php');
		$langs->load('prescription@prescription');

		$out = '<div class="prescription-hook" style="margin-top:10px;">';
		$out .= '<div class="titre inline-block">'.$langs->trans("PrescriptionForRecord").'</div>';

		// Voided records cannot receive new prescriptions
		if ($user->hasRight('prescription', 'write') && (int) $object->status !== 9) {
			$base = dol_buildpath('/prescription/card.php', 1).'?action=create&fk_medrecord='.((int) $object->id).'&fk_patient='.((int) $object->fk_patient);
			$out .= ' <a class="butAction small" href="'.$base.'&presc_type=TCM">'.$langs->trans("PrescriptionNewTCM").'</a>';
			$out .= ' <a class="butAction small" href="'.$base.'&presc_type=WM">'.$langs->trans("PrescriptionNewWM").'</a>';
		}

		$rows = prescription_list_by_medrecord($this->db, $object->id);
		if (empty($rows)) {
			$out .= '<div class="opacitymedium">'.$langs->trans("PrescriptionNoneForRecord").'</div>';
		} else {
			// Linked dispense sheets (design §5.3 merge, option A): the visit
			// thread's prescription/dispense block was folded into this table.
			$canDispense = isModEnabled('pharmacy') && $user->hasRight('pharmacy', 'read');
			if ($canDispense) {
				dol_include_once('/pharmacy/lib/pharmacy.lib.php');
			}
			$out .= '<div class="div-table-responsive-no-min"><table class="tagtable liste centpercent" style="margin-top:4px;"><tr class="liste_titre">';
			$out .= '<th style="white-space:nowrap;">'.$langs->trans("PrescriptionRef").'</th><th style="white-space:nowrap;">'.$langs->trans("PrescriptionType").'</th>';
			$out .= '<th style="white-space:nowrap;">'.$langs->trans("PrescriptionDate").'</th><th style="white-space:nowrap;">'.$langs->trans("PrescriptionLines").'</th><th class="center" style="white-space:nowrap;">'.$langs->trans("Status").'</th>';
			if ($canDispense) {
				$out .= '<th style="white-space:nowrap;">'.$langs->trans("PrescriptionDispense").'</th>';
			}
			$out .= '</tr>';
			foreach ($rows as $r) {
				$out .= '<tr class="oddeven"'.((int) $r->status === PRESCRIPTION_STATUS_VOIDED ? ' style="opacity:.55"' : '').'>';
				$out .= '<td style="white-space:nowrap;"><a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.((int) $r->rowid).'">'.dol_escape_htmltag($r->ref).'</a></td>';
				$out .= '<td>'.prescription_type_label($r->presc_type).'</td>';
				$out .= '<td>'.dol_print_date($this->db->jdate($r->date_presc), 'day').'</td>';
				$out .= '<td>'.((int) $r->nb_lines).($r->presc_type === PRESCRIPTION_TYPE_TCM && $r->doses ? ' &times; '.((int) $r->doses).$langs->trans("PrescriptionDosesUnit") : '').'</td>';
				$out .= '<td class="center">'.prescription_status_badge($r->status).'</td>';
				if ($canDispense) {
					$dispHtml = '<span class="opacitymedium">—</span>';
					$disp = pharmacy_list_by_prescription($this->db, $r->rowid);
					if (count($disp) > 0) {
						$badges = array();
						foreach ($disp as $dp) {
							$badges[] = '<span class="badge badge-status0" style="margin:2px;"><a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.((int) $dp->rowid).'">'.dol_escape_htmltag($dp->ref).'</a> '.dol_print_date($this->db->jdate($dp->date_dispense), 'day').'</span>';
						}
						$dispHtml = implode(' ', $badges);
					}
					$out .= '<td>'.$dispHtml.'</td>';
				}
				$out .= '</tr>';
			}
			$out .= '</table></div>';
		}
		$out .= '</div>';

		$this->resprints = $out;
		return 0;
	}
}
