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
 * \file    htdocs/custom/prescription/tests/unit/PrescriptionTest.php
 * \ingroup prescription
 * \brief   modPrescription structural tests, phase 1 (no DB needed).
 */

use PHPUnit\Framework\TestCase;

/**
 * Class PrescriptionTest
 */
class PrescriptionTest extends TestCase
{
	/**
	 * Descriptor: ID 501620, depends on patient + medrecord, models scalar 1,
	 * hook context, patient tab, six one-level permissions, constants, no drop.
	 */
	public function testDescriptor()
	{
		$content = file_get_contents(__DIR__.'/../../core/modules/modPrescription.class.php');
		$this->assertStringContainsString('$this->numero = 501620;', $content);
		$this->assertStringContainsString("rights_class = 'prescription'", $content);
		$this->assertStringContainsString("depends = array('modPatient', 'modMedRecord')", $content);
		$this->assertStringContainsString("'models' => 1", $content, 'scalar 1: array form breaks dol_buildpath (chinadoc probe)');
		$this->assertStringContainsString("'hooks' => array('medrecordcard')", $content);
		$this->assertStringContainsString("patient:+prescription:", $content);
		foreach (array("'read'", "'write'", "'issue'", "'void'", "'override'", "'admin'") as $perm) {
			$this->assertStringContainsString("=> ".$perm, $content, 'one-level permission '.$perm);
		}
		$this->assertStringNotContainsString('[5] = ', $content);
		$this->assertStringContainsString("'fk_menu' => 'fk_mainmenu=clinic'", $content);
		$this->assertStringNotContainsString("'type' => 'top'", $content);
		foreach (array('PRESCRIPTION_RETENTION_YEARS', 'PRESCRIPTION_ALLOW_FREE_LINES', 'PRESCRIPTION_PDF_FORMAT', 'PRESCRIPTION_TCM_DEFAULT_USAGE') as $c) {
			$this->assertStringContainsString("'".$c."'", $content);
		}
		$this->assertStringContainsString("'PRESCRIPTION_PDF_FORMAT', 'chaine', 'A4'", $content, 'A4 by user decision');
		$this->assertStringNotContainsString('DROP TABLE', $content);
		$this->assertStringContainsString('_load_tables(\'/prescription/sql/\')', $content);
	}

	/**
	 * SQL: header + line tables with the spec columns, sequence table, four
	 * dictionaries without auto increment, seeds, file names accepted by _load_tables.
	 */
	public function testSqlSchema()
	{
		$sqlDir = __DIR__.'/../../sql/';
		$p = file_get_contents($sqlDir.'llx_prescription.sql');
		foreach (array('presc_type', 'fk_patient', 'fk_medrecord', 'fk_doctor', 'doses', 'decoct_mode', 'usage_note', 'allergy_override_reason',
			'status', 'date_issued', 'fk_user_issue', 'void_reason', 'date_void', 'model_pdf', 'entity') as $col) {
			$this->assertStringContainsString($col, $p, 'column '.$col);
		}
		$this->assertStringNotContainsString('id_number', $p);
		$this->assertStringContainsString('uk_prescription_ref', file_get_contents($sqlDir.'llx_prescription.key.sql'));

		$l = file_get_contents($sqlDir.'llx_prescription_line.sql');
		foreach (array('fk_product', 'product_ref', 'label', 'qty', 'qty_unit', 'decoct_code', 'dose', 'dose_unit', 'route_code', 'freq_code', 'days', 'sig_note', 'allergy_hit') as $col) {
			$this->assertStringContainsString($col, $l, 'line column '.$col);
		}

		$seq = file_get_contents($sqlDir.'llx_prescription_sequence.sql');
		$this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $seq);
		$this->assertStringContainsString('ENGINE=innodb', $seq);

		$dict = file_get_contents($sqlDir.'llx_c_prescription_dictionaries.sql');
		$this->assertStringNotContainsString('AUTO_INCREMENT', $dict);
		foreach (array('c_prescription_decoct', 'c_prescription_route', 'c_prescription_freq', 'c_prescription_dose_unit') as $t) {
			$this->assertStringContainsString('CREATE TABLE llx_'.$t, $dict);
			$this->assertStringContainsString('uk_'.$t.'_code', file_get_contents($sqlDir.'llx_c_prescription_dictionaries.key.sql'));
		}
		$data = file_get_contents($sqlDir.'data_prescription_dictionaries.sql');
		foreach (array("'HOUXIA'", "'PO'", "'TID'", "'MG'") as $code) {
			$this->assertStringContainsString($code, $data);
		}

		foreach (glob($sqlDir.'*.sql') as $file) {
			$base = basename($file);
			$this->assertTrue(strpos($base, 'llx_') === 0 || strpos($base, 'data') === 0, $base.' would be ignored by _load_tables');
		}
	}

	/**
	 * Hook class: naming rule, context check with ':' separator, resprints,
	 * read permission, no new prescription on voided records.
	 */
	public function testHookClass()
	{
		$file = __DIR__.'/../../class/actions_prescription.class.php';
		$this->assertFileExists($file);
		$content = file_get_contents($file);
		$this->assertStringContainsString('class ActionsPrescription', $content, 'HookManager expects Actions<ucfirst(module)>');
		$this->assertStringContainsString('public function printMedRecordCard($parameters, &$object, &$action, $hookmanager)', $content);
		$this->assertStringContainsString("explode(':', (string) \$parameters['context'])", $content, 'context is colon separated (DEV.md §二.12)');
		$this->assertStringContainsString("hasRight('prescription', 'read')", $content);
		$this->assertStringContainsString("hasRight('prescription', 'write')", $content);
		$this->assertStringContainsString('$this->resprints = $out;', $content);
		$this->assertStringContainsString('(int) $object->status !== 9', $content, 'no new prescription on a voided record');
	}

	/**
	 * Every page checks a prescription permission; patient tab also needs patient read.
	 */
	public function testPagePermissions()
	{
		$root = __DIR__.'/../../';
		$pages = array(
			'list.php' => "hasRight('prescription', 'read')",
			'card.php' => "hasRight('prescription', 'read')",
			'patient_tab.php' => "hasRight('prescription', 'read')",
			'admin/setup.php' => "hasRight('prescription', 'admin')",
		);
		foreach ($pages as $page => $needle) {
			$content = file_get_contents($root.$page);
			$this->assertStringContainsString($needle, $content, $page);
			$this->assertStringContainsString('accessforbidden(', $content, $page);
		}
		$tab = file_get_contents($root.'patient_tab.php');
		$this->assertStringContainsString("hasRight('patient', 'read')", $tab);
		$this->assertStringContainsString('patient_prepare_head(', $tab);
		// Design §5.1: fiche-style header inside patient tabs, no summary banner.
		$this->assertStringContainsString('class="arearef', $tab, 'fiche-style patient header');
		$this->assertStringNotContainsString('patient_summary_banner(', $tab, 'no summary banner inside patient tabs');
		$this->assertStringContainsString('MedRecordBelonging', $tab, 'visit column on the patient tab list');
		$setup = file_get_contents($root.'admin/setup.php');
		$this->assertStringContainsString('newToken()', $setup);
		$this->assertStringContainsString("=== 'A5' ? 'A5' : 'A4'", $setup, 'paper format allowlist');
	}

	/**
	 * Lib: status constants incl. reserved dispensed, table-name allowlist, audit via modPatient.
	 */
	public function testLib()
	{
		$lib = file_get_contents(__DIR__.'/../../lib/prescription.lib.php');
		$this->assertStringContainsString("define('PRESCRIPTION_STATUS_DISPENSED', 2)", $lib, 'reserved for modPharmacy');
		$this->assertStringContainsString("define('PRESCRIPTION_STATUS_VOIDED', 9)", $lib);
		$this->assertStringContainsString("dol_include_once('/patient/lib/patient.lib.php')", $lib);
		$this->assertStringContainsString("preg_match('/^c_prescription_[a-z_]+$/', \$table)", $lib);
		$this->assertStringNotContainsString('DELETE FROM', $lib);
		foreach (array('function prescription_list_by_medrecord', 'function prescription_list_by_patient', 'function prescription_status_badge') as $fn) {
			$this->assertStringContainsString($fn, $lib);
		}
	}

	/**
	 * Phase 2: PrescriptionSheet class enforces the allergy gate inside the class
	 * (not only in pages), severe never releasable, issue re-checks, no
	 * header DELETE, audit actions, override audited; pages gated; AJAX
	 * product picker needs prescription read + product read.
	 */
	public function testPrescriptionClassAndPages()
	{
		$cls = file_get_contents(__DIR__.'/../../class/prescriptionsheet.class.php');
		$this->assertStringContainsString('class PrescriptionSheet extends CommonObject', $cls);
		$this->assertStringContainsString('new PrescriptionNumbering($this->db)', $cls);
		$this->assertStringContainsString('findConflicts($this->fk_patient', $cls, 'allergy check via modPatient');
		$this->assertStringContainsString('if ($max >= 3) {', $cls, 'severity 3 blocks unconditionally');
		$this->assertStringContainsString("hasRight('prescription', 'override')", $cls);
		// allergyGate runs in create, update and issue
		$this->assertSame(3, substr_count($cls, 'if (!$this->allergyGate($user)) {'), 'gate in create/update/issue');
		$this->assertStringContainsString("const ERR_ALLERGY = -3", $cls);
		foreach (array("'PRESCRIPTION_CREATE'", "'PRESCRIPTION_MODIFY'", "'PRESCRIPTION_ISSUE'", "'PRESCRIPTION_VOID'", "'PRESCRIPTION_ALLERGY_OVERRIDE'") as $a) {
			$this->assertStringContainsString($a, $cls, 'audit action '.$a);
		}
		$this->assertSame(1, substr_count($cls, 'DELETE FROM'), 'only draft lines are replaced');
		$this->assertStringContainsString('prescription_line WHERE fk_prescription = ', $cls);
		$this->assertStringContainsString('AND status = '.'".PRESCRIPTION_STATUS_DRAFT', $cls, 'update/issue only touch drafts');
		$this->assertStringContainsString('PRESCRIPTION_STATUS_DRAFT, PRESCRIPTION_STATUS_ISSUED', $cls, 'dispensed cannot be voided here');
		$this->assertStringContainsString("'PrescriptionErrFreeLineNotAllowed'", $cls);
		foreach (array('function canEdit', 'function canIssue', 'function canVoid', 'function copyAsNew', 'function checkAllergies', 'function diffAgainst', 'function markDispensed', 'function markDispenseUndone') as $fn) {
			$this->assertStringContainsString($fn, $cls);
		}
		// Pharmacy bridge: only pharmacy drives these, both use a conditional
		// status update as the idempotent gate (0.1.1, spec-pharmacy §2)
		$this->assertStringContainsString("'PRESCRIPTION_DISPENSE'", $cls);
		$this->assertStringContainsString("'PRESCRIPTION_DISPENSE_UNDONE'", $cls);
		$this->assertSame(1, substr_count($cls, 'AND status = ".PRESCRIPTION_STATUS_ISSUED'), 'markDispensed gates on the exact source status');
		$this->assertSame(1, substr_count($cls, 'AND status = ".PRESCRIPTION_STATUS_DISPENSED'), 'markDispenseUndone gates on the exact source status');
		$this->assertStringContainsString('date_dispensed = NULL', $cls, 'undo clears the dispense stamp');

		$card = file_get_contents(__DIR__.'/../../card.php');
		$this->assertStringContainsString("'PRESCRIPTION_READ'", $card);
		$this->assertStringContainsString("'PRESCRIPTION_COPY'", $card);
		$this->assertStringContainsString('PrescriptionSheet::ERR_ALLERGY', $card, 'blocked save re-renders with the allergy panel');
		$this->assertStringContainsString('function prescription_print_allergy_panel', $card);
		$this->assertStringContainsString('$object->canIssue($user)', $card);
		$this->assertStringContainsString('$object->canVoid($user)', $card);
		$this->assertStringContainsString('patient_select_html(', $card);
		$this->assertStringContainsString("'prescription');", $card, 'context bar with breadcrumb back to record / patient');
		$this->assertStringContainsString('newToken()', $card);

		$list = file_get_contents(__DIR__.'/../../list.php');
		$this->assertTrue(strpos($list, '<form method="GET"') < strpos($list, 'print_barre_liste('), 'form before print_barre_liste');
		$this->assertStringContainsString("(int) GETPOST('page', 'int')", $list);

		$ajax = file_get_contents(__DIR__.'/../../ajax/product.php');
		$this->assertStringContainsString("hasRight('prescription', 'read')", $ajax);
		$this->assertStringContainsString("hasRight('produit', 'lire')", $ajax);
		$this->assertStringContainsString('403 Forbidden', $ajax);
		$this->assertStringContainsString('categorie_product', $ajax, 'category filter');
	}

	/**
	 * Phase 3: sheet generators follow the core lookup convention, Chinese
	 * font, watermark by status, shared header/footer, download audited.
	 */
	public function testPdfSheets()
	{
		$base = file_get_contents(__DIR__.'/../../core/modules/prescription/modules_prescription.php');
		$this->assertStringContainsString('abstract class ModelePDFPrescription extends CommonDocGenerator', $base);
		$this->assertStringContainsString("protected \$font = 'stsongstdlight'", $base, 'CJK font (chinadoc probe)');
		$this->assertStringContainsString('function drawHeader', $base);
		$this->assertStringContainsString('function drawFooter', $base);
		$this->assertStringContainsString('function watermark', $base);
		foreach (array('PrescriptionSlotReview', 'PrescriptionSlotDispense', 'PrescriptionSlotCheck', 'PrescriptionSlotHandout') as $slot) {
			$this->assertStringContainsString($slot, $base, 'pharmacist signature slot '.$slot);
		}
		$this->assertStringContainsString("'PrescriptionPayerSelf'", $base, 'self-pay payer field (spec §3.6)');
		$this->assertStringContainsString("=== 'A5' ? array(148, 210) : array(210, 297)", $base, 'A4 default, A5 optional');
		foreach (array('id_number', 'getIdNumberPlain') as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $base);
		}

		foreach (array('cf_tcm', 'cf_wm') as $model) {
			$f = __DIR__.'/../../core/modules/prescription/doc/pdf_'.$model.'.modules.php';
			$this->assertFileExists($f, 'commonGenerateDocument looks for pdf_<modele>.modules.php');
			$src = file_get_contents($f);
			$this->assertStringContainsString('class pdf_'.$model.' extends ModelePDFPrescription', $src);
			$this->assertStringContainsString("\$this->name = '".$model."'", $src);
			$this->assertStringContainsString('function drawBody', $src);
		}
		$this->assertStringContainsString("'Rp.'", file_get_contents(__DIR__.'/../../core/modules/prescription/doc/pdf_cf_tcm.modules.php'));

		$cls = file_get_contents(__DIR__.'/../../class/prescriptionsheet.class.php');
		$this->assertStringContainsString("commonGenerateDocument('core/modules/prescription/doc/'", $cls);
		$this->assertStringContainsString("'cf_wm' : 'cf_tcm'", $cls, 'layout chosen by type');

		$pdf = file_get_contents(__DIR__.'/../../pdf.php');
		$this->assertStringContainsString("hasRight('prescription', 'read')", $pdf);
		$this->assertStringContainsString("'PRESCRIPTION_PRINT'", $pdf, 'every download is audited');
		$this->assertStringContainsString('PRESCRIPTION_STATUS_DRAFT', $pdf, 'drafts regenerate on each download');

		$card = file_get_contents(__DIR__.'/../../card.php');
		$this->assertStringContainsString("generateDocument('', \$langs)", $card, 'sheet generated on issue');
	}

	/**
	 * Language files: both locales define the same keys.
	 */
	public function testLangFilesInSync()
	{
		$zh = $this->langKeys(__DIR__.'/../../langs/zh_CN/prescription.lang');
		$en = $this->langKeys(__DIR__.'/../../langs/en_US/prescription.lang');
		$this->assertSame(array(), array_values(array_diff($zh, $en)), 'keys missing in en_US');
		$this->assertSame(array(), array_values(array_diff($en, $zh)), 'keys missing in zh_CN');
		foreach (array('ModulePrescriptionName', 'PrescriptionTab', 'PrescriptionPermOverride', 'PrescriptionAllergySevereNoOverride', 'PrescriptionNewTCM') as $key) {
			$this->assertTrue(in_array($key, $zh, true), 'lang key '.$key);
		}
	}

	/**
	 * @param string $file lang file path
	 * @return string[] keys
	 */
	private function langKeys($file)
	{
		$keys = array();
		foreach (file($file) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
				continue;
			}
			$keys[] = trim(substr($line, 0, strpos($line, '=')));
		}
		return $keys;
	}
}
