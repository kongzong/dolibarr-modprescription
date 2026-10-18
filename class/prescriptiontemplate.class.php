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
 * \file    htdocs/custom/prescription/class/prescriptiontemplate.class.php
 * \brief   Reusable prescription template (head + rows). Mirrors the
 *          prescription_line snapshot columns so applying a template to the
 *          prescribing form needs no product lookup at apply time.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class PrescriptionTemplate
 */
class PrescriptionTemplate extends CommonObject
{
	/** @var string Module element name */
	public $element = 'prescription_template';
	/** @var string Table without prefix */
	public $table_element = 'prescription_template';

	/** @var int */
	public $rowid;
	/** @var int */
	public $entity;
	/** @var string */
	public $name;
	/** @var string 'TCM' | 'WM' */
	public $presc_type;
	/** @var int|null TCM doses to prefill */
	public $doses;
	/** @var string|null Optional default diagnosis */
	public $diagnosis_text;
	/** @var string|null */
	public $note;
	/** @var int|null */
	public $fk_user_creat;
	/** @var string|int */
	public $date_creation;
	/** @var array<int,array> Rows (same keys as prescription_line snapshot) */
	public $lines = array();

	/** @var string Last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Fetch head + ordered lines.
	 *
	 * @param	int		$id		Rowid
	 * @return	int				1 ok, 0 not found, <0 error
	 */
	public function fetch($id)
	{
		$prefix = $this->db->prefix();
		$sql = "SELECT rowid, entity, name, presc_type, doses, diagnosis_text, note, fk_user_creat, date_creation";
		$sql .= " FROM ".$prefix."prescription_template WHERE rowid = ".((int) $id)." AND status <> 9";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$o = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$o) {
			return 0;
		}
		$this->rowid = (int) $o->rowid;
		$this->entity = (int) $o->entity;
		$this->name = (string) $o->name;
		$this->presc_type = (string) $o->presc_type;
		$this->doses = $o->doses !== null ? (int) $o->doses : null;
		$this->diagnosis_text = $o->diagnosis_text !== null ? (string) $o->diagnosis_text : null;
		$this->note = $o->note !== null ? (string) $o->note : null;
		$this->fk_user_creat = $o->fk_user_creat !== null ? (int) $o->fk_user_creat : null;
		$this->date_creation = $o->date_creation;
		return $this->fetchLines();
	}

	/**
	 * Load ordered rows into $this->lines.
	 *
	 * @return	int		1 ok, <0 error
	 */
	private function fetchLines()
	{
		$this->lines = array();
		$prefix = $this->db->prefix();
		$sql = "SELECT rowid, position, fk_product, product_ref, label, qty, qty_unit, decoct_code, dose, dose_unit, route_code, freq_code, days, sig_note";
		$sql .= " FROM ".$prefix."prescription_template_line WHERE fk_template = ".((int) $this->rowid);
		$sql .= " ORDER BY position ASC, rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($o = $this->db->fetch_object($resql)) {
			$this->lines[] = array(
				'id' => (int) $o->rowid,
				'position' => (int) $o->position,
				'fk_product' => $o->fk_product !== null ? (int) $o->fk_product : 0,
				'product_ref' => (string) $o->product_ref,
				'label' => (string) $o->label,
				'qty' => $o->qty !== null ? (float) $o->qty : null,
				'qty_unit' => (string) $o->qty_unit,
				'decoct_code' => (string) $o->decoct_code,
				'dose' => $o->dose !== null ? (float) $o->dose : null,
				'dose_unit' => (string) $o->dose_unit,
				'route_code' => (string) $o->route_code,
				'freq_code' => (string) $o->freq_code,
				'days' => $o->days !== null ? (int) $o->days : null,
				'sig_note' => (string) $o->sig_note,
			);
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Create head + lines from the current properties. Read the form via
	 * prescription_template_read_form() before calling.
	 *
	 * @return	int		New rowid, <0 error
	 */
	public function create($user)
	{
		if (trim((string) $this->name) === '' || !in_array($this->presc_type, array('TCM', 'WM'), true)) {
			$this->error = 'BadValueForNameOrType';
			return -1;
		}
		$prefix = $this->db->prefix();
		$this->db->begin();

		$sql = "INSERT INTO ".$prefix."prescription_template (entity, name, presc_type, doses, diagnosis_text, note, fk_user_creat, date_creation)";
		$sql .= " VALUES (".getDolGlobalInt('entity', 1).", '".$this->db->escape($this->name)."', '".$this->db->escape($this->presc_type)."', ";
		$sql .= ($this->doses !== null && (int) $this->doses > 0 ? (int) $this->doses : 'NULL').", ";
		$sql .= ($this->diagnosis_text !== null && $this->diagnosis_text !== '' ? "'".$this->db->escape($this->diagnosis_text)."'" : 'NULL').", ";
		$sql .= ($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : 'NULL').", ";
		$sql .= (!empty($user->id) ? (int) $user->id : 'NULL').", '".$this->db->idate(dol_now())."')";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->rowid = (int) $this->db->last_insert_id($prefix.'prescription_template');

		$lineRes = $this->saveLines();
		if ($lineRes < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return $this->rowid;
	}

	/**
	 * Update head + replace all lines with the current $this->lines.
	 *
	 * @return	int		1 ok, <0 error
	 */
	public function update($user = null)
	{
		if ($this->rowid <= 0 || trim((string) $this->name) === '' || !in_array($this->presc_type, array('TCM', 'WM'), true)) {
			$this->error = 'BadValueForIdNameOrType';
			return -1;
		}
		$prefix = $this->db->prefix();
		$this->db->begin();

		$sql = "UPDATE ".$prefix."prescription_template SET";
		$sql .= " name = '".$this->db->escape($this->name)."'";
		$sql .= ", presc_type = '".$this->db->escape($this->presc_type)."'";
		$sql .= ", doses = ".($this->doses !== null && (int) $this->doses > 0 ? (int) $this->doses : 'NULL');
		$sql .= ", diagnosis_text = ".($this->diagnosis_text !== null && $this->diagnosis_text !== '' ? "'".$this->db->escape($this->diagnosis_text)."'" : 'NULL');
		$sql .= ", note = ".($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : 'NULL');
		$sql .= " WHERE rowid = ".((int) $this->rowid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		if (!$this->db->query("DELETE FROM ".$prefix."prescription_template_line WHERE fk_template = ".((int) $this->rowid))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->saveLines() < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Soft delete: keep head + lines, mark status 9 so the trace survives.
	 *
	 * @return	int		1 ok, <0 error
	 */
	public function delete()
	{
		if ($this->rowid <= 0) {
			return -1;
		}
		$prefix = $this->db->prefix();
		if (!$this->db->query("UPDATE ".$prefix."prescription_template SET status = 9 WHERE rowid = ".((int) $this->rowid))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Insert $this->lines (no transaction; caller owns it).
	 *
	 * @return	int		1 ok, <0 error
	 */
	private function saveLines()
	{
		$prefix = $this->db->prefix();
		foreach ($this->lines as $i => $l) {
			$label = trim((string) ($l['label'] ?? ''));
			if ($label === '') {
				continue;
			}
			$sql = "INSERT INTO ".$prefix."prescription_template_line";
			$sql .= " (fk_template, position, fk_product, product_ref, label, qty, qty_unit, decoct_code, dose, dose_unit, route_code, freq_code, days, sig_note)";
			$sql .= " VALUES (".((int) $this->rowid).", ".((int) $i).", ".(int) ($l['fk_product'] ?? 0);
			$sql .= ", '".(isset($l['product_ref']) ? $this->db->escape((string) $l['product_ref']) : '')."'";
			$sql .= ", '".$this->db->escape($label)."'";
			$sql .= ", ".($l['qty'] !== null && $l['qty'] !== '' ? "'".$this->db->escape((string) $l['qty'])."'" : 'NULL');
			$sql .= ", '".(isset($l['qty_unit']) ? $this->db->escape((string) $l['qty_unit']) : '')."'";
			$sql .= ", '".(isset($l['decoct_code']) ? $this->db->escape((string) $l['decoct_code']) : '')."'";
			$sql .= ", ".($l['dose'] !== null && $l['dose'] !== '' ? "'".$this->db->escape((string) $l['dose'])."'" : 'NULL');
			$sql .= ", '".(isset($l['dose_unit']) ? $this->db->escape((string) $l['dose_unit']) : '')."'";
			$sql .= ", '".(isset($l['route_code']) ? $this->db->escape((string) $l['route_code']) : '')."'";
			$sql .= ", '".(isset($l['freq_code']) ? $this->db->escape((string) $l['freq_code']) : '')."'";
			$sql .= ", ".($l['days'] !== null && $l['days'] !== '' ? (int) $l['days'] : 'NULL');
			$sql .= ", '".(isset($l['sig_note']) ? $this->db->escape((string) $l['sig_note']) : '')."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * List heads (id + name + type + line count) optionally filtered by type.
	 *
	 * @param	DoliDB		$db
	 * @param	string|null	$type	'TCM' | 'WM' | null (all)
	 * @return	array<int,array>	Empty array on error
	 */
	public static function listAll($db, $type = null)
	{
		$prefix = $db->prefix();
		$sql = "SELECT t.rowid, t.name, t.presc_type, t.doses, t.diagnosis_text, COUNT(l.rowid) AS nlines";
		$sql .= " FROM ".$prefix."prescription_template t";
		$sql .= " LEFT JOIN ".$prefix."prescription_template_line l ON l.fk_template = t.rowid";
		$sql .= " WHERE t.entity = ".getDolGlobalInt('entity', 1)." AND t.status <> 9";
		if ($type === 'TCM' || $type === 'WM') {
			$sql .= " AND t.presc_type = '".$db->escape($type)."'";
		}
		$sql .= " GROUP BY t.rowid, t.name, t.presc_type, t.doses, t.diagnosis_text";
		$sql .= " ORDER BY t.presc_type ASC, t.name ASC";
		$resql = $db->query($sql);
		$out = array();
		if (!$resql) {
			return $out;
		}
		while ($o = $db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $o->rowid,
				'name' => (string) $o->name,
				'presc_type' => (string) $o->presc_type,
				'doses' => $o->doses !== null ? (int) $o->doses : null,
				'diagnosis_text' => $o->diagnosis_text !== null ? (string) $o->diagnosis_text : null,
				'nlines' => (int) $o->nlines,
			);
		}
		$db->free($resql);
		return $out;
	}
}
