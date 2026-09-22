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
 *  \defgroup   prescription     Module PrescriptionSheet
 *  \brief      Prescriptions (TCM decoction / western) with allergy blocking and PDF sheets.
 *
 *  \file       htdocs/custom/prescription/core/modules/modPrescription.class.php
 *  \ingroup    prescription
 *  \brief      Description and activation file for module PrescriptionSheet
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module PrescriptionSheet
 */
class modPrescription extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Healthcare module family: 501600 + 10 per module
		$this->numero = 501620;

		$this->rights_class = 'prescription';

		$this->family = "crm";
		$this->module_position = '93';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "ModulePrescriptionDesc";
		$this->descriptionlong = "ModulePrescriptionDescLong";

		$this->editor_name = 'modPrescription';
		$this->editor_url = 'https://github.com/kongzong/dolibarr-modprescription';

		$this->version = '0.1.0';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'fa-prescription';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			// Scalar 1 so commonGenerateDocument() scans /prescription/ for
			// core/modules/prescription/doc/pdf_*.modules.php (chinadoc probe: array form breaks dol_buildpath)
			'models' => 1,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			// Inject "new prescription" buttons + list into the medical record card
			'hooks' => array('medrecordcard'),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		// $conf->prescription->dir_output = DOL_DATA_ROOT/prescription (PDF storage, core document.php wrapping)
		$this->dirs = array("/prescription/temp");

		$this->config_page_url = array("setup.php@prescription");

		$this->hidden = getDolGlobalInt('MODULE_PRESCRIPTION_DISABLED');
		$this->depends = array('modPatient', 'modMedRecord');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("prescription@prescription");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 1;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Spec §3.1 constants
		$this->const = array(
			0 => array('PRESCRIPTION_RETENTION_YEARS', 'chaine', '1', 'Minimum retention of prescriptions in years (display only, never deletes)', 0, 'current', 1),
			1 => array('PRESCRIPTION_ALLOW_FREE_LINES', 'chaine', '1', 'Allow drug lines without a linked product (1 until the drug catalogue exists)', 0, 'current', 1),
			2 => array('PRESCRIPTION_PDF_FORMAT', 'chaine', 'A4', 'Paper format of the prescription sheet (A4 or A5)', 0, 'current', 1),
			3 => array('PRESCRIPTION_TCM_CATEGORY', 'chaine', '', 'Product category id used to filter the herb picker (empty = all products)', 0, 'current', 1),
			4 => array('PRESCRIPTION_WM_CATEGORY', 'chaine', '', 'Product category id used to filter the western drug picker (empty = all products)', 0, 'current', 1),
			5 => array('PRESCRIPTION_TCM_DEFAULT_USAGE', 'chaine', '水煎服，每日一剂，分早晚两次温服', 'Default decoction usage text for TCM prescriptions', 0, 'current', 1),
		);

		if (!isModEnabled("prescription")) {
			$conf->prescription = new stdClass();
			$conf->prescription->enabled = 0;
		}

		// "Prescriptions" tab on the patient card (patient 0.1.1 complete_head_from_modules('patient'))
		$this->tabs = array();
		$this->tabs[] = array('data' => 'patient:+prescription:PrescriptionTab:prescription@prescription:$user->hasRight(\'prescription\', \'read\'):/prescription/patient_tab.php?id=__ID__');

		// Dictionaries: decoction methods, routes, frequencies, dose units (spec §3.1)
		$langs->load('prescription@prescription');
		$dictTables = array(
			'c_prescription_decoct' => 'PrescriptionDictDecoct',
			'c_prescription_route' => 'PrescriptionDictRoute',
			'c_prescription_freq' => 'PrescriptionDictFreq',
			'c_prescription_dose_unit' => 'PrescriptionDictDoseUnit',
		);
		$this->dictionaries = array(
			'langs' => 'prescription@prescription',
			'tabname' => array(),
			'tablib' => array(),
			'tabsql' => array(),
			'tabsqlsort' => array(),
			'tabfield' => array(),
			'tabfieldvalue' => array(),
			'tabfieldinsert' => array(),
			'tabrowid' => array(),
			'tabcond' => array(),
			'tabhelp' => array(),
		);
		foreach ($dictTables as $table => $label) {
			$this->dictionaries['tabname'][] = $table;
			$this->dictionaries['tablib'][] = $label;
			$this->dictionaries['tabsql'][] = 'SELECT f.rowid as rowid, f.code, f.label, f.pos, f.active FROM '.MAIN_DB_PREFIX.$table.' as f';
			$this->dictionaries['tabsqlsort'][] = 'pos ASC, label ASC';
			$this->dictionaries['tabfield'][] = 'code,label,pos';
			$this->dictionaries['tabfieldvalue'][] = 'code,label,pos';
			$this->dictionaries['tabfieldinsert'][] = 'code,label,pos';
			$this->dictionaries['tabrowid'][] = 'rowid';
			$this->dictionaries['tabcond'][] = isModEnabled('prescription');
			$this->dictionaries['tabhelp'][] = array('code' => $langs->trans('PrescriptionDictCodeHelp'));
		}

		$this->boxes = array();
		$this->cronjobs = array();

		// Permissions: one-level form, ids 50162011..61 (spec §9)
		$this->rights = array();
		$r = 0;
		$perms = array(11 => 'read', 21 => 'write', 31 => 'issue', 41 => 'void', 51 => 'override', 61 => 'admin');
		foreach ($perms as $suffix => $code) {
			$this->rights[$r][0] = $this->numero . $suffix;
			$this->rights[$r][1] = 'PrescriptionPerm'.ucfirst($code);
			$this->rights[$r][4] = $code;
			$r++;
		}

		// Left menu under the shared "Clinic" top menu owned by modPatient
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'PrescriptionList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'prescription_list',
			'url' => '/prescription/list.php',
			'langs' => 'prescription@prescription',
			'position' => 1200 + $r,
			'enabled' => 'isModEnabled("prescription")',
			'perms' => '$user->hasRight("prescription", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=prescription_list',
			'type' => 'left',
			'titre' => 'PrescriptionNew',
			'mainmenu' => 'clinic',
			'leftmenu' => 'prescription_new',
			'url' => '/prescription/card.php?action=create',
			'langs' => 'prescription@prescription',
			'position' => 1200 + $r,
			'enabled' => 'isModEnabled("prescription")',
			'perms' => '$user->hasRight("prescription", "write")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/prescription/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Removes constants, permissions, menus, tabs and hooks only.
	 *	Prescriptions, lines, the sequence table, dictionaries and generated
	 *	PDFs are kept (spec §5.1).
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
