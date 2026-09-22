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
 * \file    htdocs/custom/prescription/class/prescriptionsheet.class.php
 * \ingroup prescription
 * \brief   PrescriptionSheet (TCM decoction / western) with lines, allergy
 *          blocking (spec §3.3) and the draft -> issued -> voided state
 *          machine (§3.4). Never deleted. Every write is audited through
 *          modPatient's patient_audit() with PRESCRIPTION_* actions.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/prescription/class/prescriptionnumbering.class.php');
dol_include_once('/prescription/lib/prescription.lib.php');
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/patient/class/patientallergy.class.php');

/**
 * Class PrescriptionSheet
 */
class PrescriptionSheet extends CommonObject
{
	public $element = 'prescription';
	public $table_element = 'prescription';
	public $picto = 'fa-prescription';

	public $id;
	public $entity;
	public $ref;
	public $presc_type = PRESCRIPTION_TYPE_TCM;
	public $fk_patient;
	public $fk_medrecord;
	public $fk_doctor;
	public $fk_department;
	/** @var int Unix timestamp */
	public $date_presc;
	public $diagnosis_text;
	public $doses;
	public $decoct_mode;
	public $usage_note;
	public $note;
	public $allergy_override_reason;
	public $status = PRESCRIPTION_STATUS_DRAFT;
	public $date_issued;
	public $fk_user_issue;
	public $void_reason;
	public $date_void;
	public $fk_user_void;
	public $model_pdf;
	public $last_main_doc;
	public $fk_user_creat;
	public $fk_user_modif;
	public $date_creation;
	public $tms;

	/**
	 * @var array<int,array{fk_product:?int,product_ref:?string,label:string,qty:?float,qty_unit:?string,decoct_code:?string,
	 *      dose:?float,dose_unit:?string,route_code:?string,freq_code:?string,days:?int,sig_note:?string,allergy_hit:int}>
	 */
	public $lines = array();

	/**
	 * Allergy conflicts found by the last save/issue attempt:
	 * [{line: idx, label, allergy: name, severity}]
	 * @var array<int,array{line:int,label:string,allergy:string,severity:int}>
	 */
	public $allergyHits = array();

	/** Error code returned by create/update/issue when allergies block the save */
	const ERR_ALLERGY = -3;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------ permissions

	/**
	 * @param	User	$user	User
	 * @return	bool
	 */
	public function canEdit(User $user)
	{
		if (!$user->hasRight('prescription', 'write') || (int) $this->status !== PRESCRIPTION_STATUS_DRAFT) {
			return false;
		}
		return $user->hasRight('prescription', 'admin') || (int) $this->fk_doctor === (int) $user->id || (int) $this->fk_user_creat === (int) $user->id;
	}

	/**
	 * @param	User	$user	User
	 * @return	bool
	 */
	public function canIssue(User $user)
	{
		return (int) $this->status === PRESCRIPTION_STATUS_DRAFT && $user->hasRight('prescription', 'issue')
			&& ($user->hasRight('prescription', 'admin') || (int) $this->fk_doctor === (int) $user->id);
	}

	/**
	 * Dispensed prescriptions (modPharmacy) cannot be voided here.
	 *
	 * @param	User	$user	User
	 * @return	bool
	 */
	public function canVoid(User $user)
	{
		return in_array((int) $this->status, array(PRESCRIPTION_STATUS_DRAFT, PRESCRIPTION_STATUS_ISSUED), true) && $user->hasRight('prescription', 'void');
	}

	// ------------------------------------------------------------ validation

	/**
	 * Normalize header + lines. Fills $this->error.
	 *
	 * @param	bool	$forIssue	Enforce issue requirements
	 * @return	bool
	 */
	private function validate($forIssue = false)
	{
		$this->presc_type = ($this->presc_type === PRESCRIPTION_TYPE_WM) ? PRESCRIPTION_TYPE_WM : PRESCRIPTION_TYPE_TCM;
		$this->fk_patient = (int) $this->fk_patient;
		$this->fk_doctor = (int) $this->fk_doctor;
		$this->fk_medrecord = (int) $this->fk_medrecord > 0 ? (int) $this->fk_medrecord : null;
		$this->fk_department = (int) $this->fk_department > 0 ? (int) $this->fk_department : null;
		if ($this->fk_patient <= 0) {
			$this->error = 'PrescriptionErrPatientRequired';
			return false;
		}
		$doctors = patient_doctor_options($this->db);
		if ($this->fk_doctor <= 0 || !isset($doctors[$this->fk_doctor])) {
			$this->error = 'PrescriptionErrDoctorRequired';
			return false;
		}
		if (empty($this->date_presc)) {
			$this->date_presc = dol_now();
		}
		foreach (array('diagnosis_text', 'usage_note', 'note', 'allergy_override_reason') as $f) {
			$this->$f = ($this->$f === null) ? null : trim(str_replace(array("\r\n", "\r"), "\n", (string) $this->$f));
		}
		$this->doses = (int) $this->doses > 0 ? (int) $this->doses : null;
		$this->decoct_mode = in_array($this->decoct_mode, array('SELF', 'CLINIC', 'GRANULE'), true) ? $this->decoct_mode : null;

		$allowFree = getDolGlobalInt('PRESCRIPTION_ALLOW_FREE_LINES', 1) > 0;
		$clean = array();
		foreach ((array) $this->lines as $l) {
			$label = isset($l['label']) ? trim((string) $l['label']) : '';
			$fkProduct = !empty($l['fk_product']) ? (int) $l['fk_product'] : null;
			if ($label === '' && $fkProduct === null) {
				continue; // blank editor row
			}
			if ($fkProduct === null && !$allowFree) {
				$this->error = 'PrescriptionErrFreeLineNotAllowed';
				return false;
			}
			if ($label === '') {
				$this->error = 'PrescriptionErrLineLabelRequired';
				return false;
			}
			$row = array(
				'fk_product' => $fkProduct,
				'product_ref' => isset($l['product_ref']) ? dol_substr(trim((string) $l['product_ref']), 0, 128) : null,
				'label' => dol_substr($label, 0, 255),
				'qty' => isset($l['qty']) && $l['qty'] !== '' ? (float) price2num($l['qty']) : null,
				'qty_unit' => isset($l['qty_unit']) ? dol_substr(trim((string) $l['qty_unit']), 0, 16) : null,
				'decoct_code' => isset($l['decoct_code']) && $l['decoct_code'] !== '' && $l['decoct_code'] !== '-1' ? dol_substr((string) $l['decoct_code'], 0, 16) : null,
				'dose' => isset($l['dose']) && $l['dose'] !== '' ? (float) price2num($l['dose']) : null,
				'dose_unit' => isset($l['dose_unit']) && $l['dose_unit'] !== '' && $l['dose_unit'] !== '-1' ? dol_substr((string) $l['dose_unit'], 0, 16) : null,
				'route_code' => isset($l['route_code']) && $l['route_code'] !== '' && $l['route_code'] !== '-1' ? dol_substr((string) $l['route_code'], 0, 16) : null,
				'freq_code' => isset($l['freq_code']) && $l['freq_code'] !== '' && $l['freq_code'] !== '-1' ? dol_substr((string) $l['freq_code'], 0, 16) : null,
				'days' => isset($l['days']) && $l['days'] !== '' ? (int) $l['days'] : null,
				'sig_note' => isset($l['sig_note']) ? dol_substr(trim((string) $l['sig_note']), 0, 255) : null,
				'allergy_hit' => 0,
			);
			if ($this->presc_type === PRESCRIPTION_TYPE_TCM) {
				if ($row['qty_unit'] === null || $row['qty_unit'] === '') {
					$row['qty_unit'] = 'g';
				}
				if ($forIssue && ($row['qty'] === null || $row['qty'] <= 0)) {
					$this->error = 'PrescriptionErrGramsRequired';
					return false;
				}
			} elseif ($forIssue) {
				$hasSig = ($row['dose'] !== null && $row['freq_code'] !== null && $row['days'] !== null) || ($row['sig_note'] !== null && $row['sig_note'] !== '');
				if (!$hasSig) {
					$this->error = 'PrescriptionErrSigRequired';
					return false;
				}
			}
			$clean[] = $row;
		}
		$this->lines = $clean;

		if ($forIssue) {
			if (empty($this->lines)) {
				$this->error = 'PrescriptionErrLinesRequired';
				return false;
			}
			if ($this->presc_type === PRESCRIPTION_TYPE_TCM && (int) $this->doses < 1) {
				$this->error = 'PrescriptionErrDosesRequired';
				return false;
			}
		}
		return true;
	}

	// ------------------------------------------------------------ allergy gate (spec §3.3)

	/**
	 * Check every line against the patient's active allergies. Fills
	 * $this->allergyHits and marks allergy_hit on lines.
	 *
	 * @return	int		Highest severity hit (0 = none)
	 */
	public function checkAllergies()
	{
		$this->allergyHits = array();
		$maxSeverity = 0;
		$dao = new PatientAllergy($this->db);
		foreach ($this->lines as $i => &$l) {
			$l['allergy_hit'] = 0;
			$hits = $dao->findConflicts($this->fk_patient, (int) $l['fk_product'], $l['label']);
			foreach ((array) $hits as $h) {
				$l['allergy_hit'] = 1;
				$this->allergyHits[] = array('line' => $i, 'label' => $l['label'], 'allergy' => $h->name, 'severity' => (int) $h->severity);
				$maxSeverity = max($maxSeverity, (int) $h->severity);
			}
		}
		unset($l);
		return $maxSeverity;
	}

	/**
	 * Apply the allergy rule: no hit -> ok; hit with severity 3 -> blocked;
	 * hit <= 2 -> ok only if the user has 'override' and gave a reason.
	 *
	 * @param	User	$user	Acting user
	 * @return	bool			True when the save may proceed
	 */
	private function allergyGate(User $user)
	{
		$max = $this->checkAllergies();
		if ($max === 0) {
			$this->allergy_override_reason = null;
			return true;
		}
		if ($max >= 3) {
			$this->error = 'PrescriptionAllergySevereNoOverride';
			return false;
		}
		$reason = trim((string) $this->allergy_override_reason);
		if (!$user->hasRight('prescription', 'override') || $reason === '') {
			$this->error = 'PrescriptionErrAllergyBlocked';
			return false;
		}
		return true;
	}

	/**
	 * @param	int		$max	Highest hit severity
	 * @return	bool			Whether the current user could release these hits
	 */
	public function overridePossible(User $user)
	{
		$max = 0;
		foreach ($this->allergyHits as $h) {
			$max = max($max, (int) $h['severity']);
		}
		return $max > 0 && $max < 3 && $user->hasRight('prescription', 'override');
	}

	// ------------------------------------------------------------ create / update

	/**
	 * Create a draft (number + header + lines in one transaction).
	 *
	 * @param	User	$user	Acting user
	 * @return	int				>0 rowid, ERR_ALLERGY when blocked (see allergyHits), <0 other error
	 */
	public function create(User $user)
	{
		global $conf;

		$this->error = '';
		if (!$this->validate(false)) {
			return -1;
		}
		if (!$this->allergyGate($user)) {
			return self::ERR_ALLERGY;
		}

		$this->db->begin();
		try {
			$numbering = new PrescriptionNumbering($this->db);
			$this->ref = $numbering->nextReference(PrescriptionNumbering::prefixFor(dol_now()));
			$this->entity = !empty($conf->entity) ? (int) $conf->entity : 1;
			$this->status = PRESCRIPTION_STATUS_DRAFT;
			$this->fk_user_creat = (int) $user->id;
			$this->date_creation = dol_now();

			$sql = "INSERT INTO ".$this->db->prefix()."prescription (entity, ref, presc_type, fk_patient, fk_medrecord, fk_doctor, fk_department, date_presc,";
			$sql .= " diagnosis_text, doses, decoct_mode, usage_note, note, allergy_override_reason, status, fk_user_creat, date_creation) VALUES (";
			$sql .= $this->entity.", '".$this->db->escape($this->ref)."', '".$this->db->escape($this->presc_type)."', ".$this->fk_patient.",";
			$sql .= " ".($this->fk_medrecord ? $this->fk_medrecord : 'NULL').", ".$this->fk_doctor.", ".($this->fk_department ? $this->fk_department : 'NULL').",";
			$sql .= " '".$this->db->idate($this->date_presc)."', ".$this->nullOrString($this->diagnosis_text).", ".($this->doses ? $this->doses : 'NULL').",";
			$sql .= " ".$this->nullOrString($this->decoct_mode).", ".$this->nullOrString($this->usage_note).", ".$this->nullOrString($this->note).",";
			$sql .= " ".$this->nullOrString($this->allergy_override_reason).", 0, ".$this->fk_user_creat.", '".$this->db->idate($this->date_creation)."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				throw new RuntimeException('insert failed');
			}
			$this->id = (int) $this->db->last_insert_id($this->db->prefix().'prescription');
			$this->saveLines();

			patient_audit($this->db, $this->fk_patient, 'PRESCRIPTION_CREATE', $user, array('ref' => $this->ref, 'prescription' => $this->id, 'type' => $this->presc_type, 'lines' => count($this->lines), 'medrecord' => $this->fk_medrecord));
			if (!empty($this->allergyHits)) {
				patient_audit($this->db, $this->fk_patient, 'PRESCRIPTION_ALLERGY_OVERRIDE', $user, array('ref' => $this->ref, 'prescription' => $this->id, 'hits' => $this->allergyHits, 'reason' => $this->allergy_override_reason));
			}
			$this->db->commit();
			return $this->id;
		} catch (Throwable $e) {
			if (empty($this->error)) {
				$this->error = $e->getMessage();
			}
			dol_syslog(__METHOD__.' failed: '.$this->error, LOG_ERR);
			while ($this->db->transaction_opened > 0) {
				if (!$this->db->rollback()) {
					break;
				}
			}
			$this->id = 0;
			$this->ref = '';
			return -1;
		}
	}

	/**
	 * Save a draft's header + lines. Patient / ref / type never change.
	 *
	 * @param	User	$user	Acting user
	 * @return	int				1 ok, ERR_ALLERGY when blocked, -2 not editable, -1 error
	 */
	public function update(User $user)
	{
		$this->error = '';
		if ($this->id <= 0) {
			return -1;
		}
		if (!$this->canEdit($user)) {
			$this->error = 'PrescriptionErrNotEditable';
			return -2;
		}
		if (!$this->validate(false)) {
			return -1;
		}
		if (!$this->allergyGate($user)) {
			return self::ERR_ALLERGY;
		}

		$old = new PrescriptionSheet($this->db);
		$changes = ($old->fetch($this->id) > 0) ? $this->diffAgainst($old) : array();

		$this->db->begin();
		try {
			$sql = "UPDATE ".$this->db->prefix()."prescription SET";
			$sql .= " fk_medrecord = ".($this->fk_medrecord ? $this->fk_medrecord : 'NULL');
			$sql .= ", fk_doctor = ".$this->fk_doctor;
			$sql .= ", fk_department = ".($this->fk_department ? $this->fk_department : 'NULL');
			$sql .= ", date_presc = '".$this->db->idate($this->date_presc)."'";
			$sql .= ", diagnosis_text = ".$this->nullOrString($this->diagnosis_text);
			$sql .= ", doses = ".($this->doses ? $this->doses : 'NULL');
			$sql .= ", decoct_mode = ".$this->nullOrString($this->decoct_mode);
			$sql .= ", usage_note = ".$this->nullOrString($this->usage_note);
			$sql .= ", note = ".$this->nullOrString($this->note);
			$sql .= ", allergy_override_reason = ".$this->nullOrString($this->allergy_override_reason);
			$sql .= ", fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".PRESCRIPTION_STATUS_DRAFT;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				throw new RuntimeException('update failed');
			}
			$this->saveLines();
			patient_audit($this->db, $this->fk_patient, 'PRESCRIPTION_MODIFY', $user, array('ref' => $this->ref, 'prescription' => $this->id, 'changes' => $changes));
			if (!empty($this->allergyHits)) {
				patient_audit($this->db, $this->fk_patient, 'PRESCRIPTION_ALLERGY_OVERRIDE', $user, array('ref' => $this->ref, 'prescription' => $this->id, 'hits' => $this->allergyHits, 'reason' => $this->allergy_override_reason));
			}
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			if (empty($this->error)) {
				$this->error = $e->getMessage();
			}
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Replace the line rows (inside the caller's transaction). Only drafts
	 * reach this method; the header row is never deleted (spec §5.1).
	 *
	 * @return	void
	 * @throws	RuntimeException
	 */
	private function saveLines()
	{
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."prescription_line WHERE fk_prescription = ".((int) $this->id))) {
			$this->error = $this->db->lasterror();
			throw new RuntimeException('line reset failed');
		}
		$pos = 0;
		foreach ($this->lines as $l) {
			$sql = "INSERT INTO ".$this->db->prefix()."prescription_line (fk_prescription, position, fk_product, product_ref, label, qty, qty_unit, decoct_code, dose, dose_unit, route_code, freq_code, days, sig_note, allergy_hit) VALUES (";
			$sql .= ((int) $this->id).", ".($pos++).", ".($l['fk_product'] ? (int) $l['fk_product'] : 'NULL').", ".$this->nullOrString($l['product_ref']).", '".$this->db->escape($l['label'])."',";
			$sql .= " ".($l['qty'] !== null ? (float) $l['qty'] : 'NULL').", ".$this->nullOrString($l['qty_unit']).", ".$this->nullOrString($l['decoct_code']).",";
			$sql .= " ".($l['dose'] !== null ? (float) $l['dose'] : 'NULL').", ".$this->nullOrString($l['dose_unit']).", ".$this->nullOrString($l['route_code']).", ".$this->nullOrString($l['freq_code']).",";
			$sql .= " ".($l['days'] !== null ? (int) $l['days'] : 'NULL').", ".$this->nullOrString($l['sig_note']).", ".((int) $l['allergy_hit']).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				throw new RuntimeException('line insert failed');
			}
		}
	}

	// ------------------------------------------------------------ fetch

	/**
	 * @param	int		$id		Rowid
	 * @param	string	$ref	Or reference
	 * @return	int				1 found, 0 not found, <0 error
	 */
	public function fetch($id = 0, $ref = '')
	{
		global $conf;

		$sql = "SELECT p.* FROM ".$this->db->prefix()."prescription as p WHERE p.entity = ".((int) $conf->entity);
		if ((int) $id > 0) {
			$sql .= " AND p.rowid = ".((int) $id);
		} elseif ($ref !== '') {
			$sql .= " AND p.ref = '".$this->db->escape($ref)."'";
		} else {
			return -1;
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->presc_type = $obj->presc_type;
		$this->fk_patient = (int) $obj->fk_patient;
		$this->fk_medrecord = $obj->fk_medrecord ? (int) $obj->fk_medrecord : null;
		$this->fk_doctor = (int) $obj->fk_doctor;
		$this->fk_department = $obj->fk_department ? (int) $obj->fk_department : null;
		$this->date_presc = $this->db->jdate($obj->date_presc);
		$this->diagnosis_text = $obj->diagnosis_text;
		$this->doses = $obj->doses !== null ? (int) $obj->doses : null;
		$this->decoct_mode = $obj->decoct_mode;
		$this->usage_note = $obj->usage_note;
		$this->note = $obj->note;
		$this->allergy_override_reason = $obj->allergy_override_reason;
		$this->status = (int) $obj->status;
		$this->date_issued = $obj->date_issued ? $this->db->jdate($obj->date_issued) : null;
		$this->fk_user_issue = $obj->fk_user_issue ? (int) $obj->fk_user_issue : null;
		$this->void_reason = $obj->void_reason;
		$this->date_void = $obj->date_void ? $this->db->jdate($obj->date_void) : null;
		$this->fk_user_void = $obj->fk_user_void ? (int) $obj->fk_user_void : null;
		$this->model_pdf = $obj->model_pdf;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_modif = $obj->fk_user_modif ? (int) $obj->fk_user_modif : null;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->tms = $this->db->jdate($obj->tms);

		$this->lines = array();
		$resql = $this->db->query("SELECT * FROM ".$this->db->prefix()."prescription_line WHERE fk_prescription = ".$this->id." ORDER BY position ASC, rowid ASC");
		if ($resql) {
			while ($l = $this->db->fetch_object($resql)) {
				$this->lines[] = array(
					'fk_product' => $l->fk_product ? (int) $l->fk_product : null, 'product_ref' => $l->product_ref, 'label' => $l->label,
					'qty' => $l->qty !== null ? (float) $l->qty : null, 'qty_unit' => $l->qty_unit, 'decoct_code' => $l->decoct_code,
					'dose' => $l->dose !== null ? (float) $l->dose : null, 'dose_unit' => $l->dose_unit, 'route_code' => $l->route_code, 'freq_code' => $l->freq_code,
					'days' => $l->days !== null ? (int) $l->days : null, 'sig_note' => $l->sig_note, 'allergy_hit' => (int) $l->allergy_hit,
				);
			}
			$this->db->free($resql);
		}
		return 1;
	}

	// ------------------------------------------------------------ state machine

	/**
	 * Draft -> issued. Re-runs validation and the allergy gate; locks the
	 * prescription. PDF generation is chained by the caller (phase 3).
	 *
	 * @param	User	$user	Issuing doctor
	 * @return	int				1 ok, ERR_ALLERGY blocked, -2 not allowed, -1 error
	 */
	public function issue(User $user)
	{
		$this->error = '';
		if ($this->id <= 0 || !$this->canIssue($user)) {
			$this->error = 'PrescriptionErrCannotIssue';
			return -2;
		}
		if (!$this->validate(true)) {
			return -1;
		}
		if (!$this->allergyGate($user)) {
			return self::ERR_ALLERGY;
		}
		$now = dol_now();
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix()."prescription SET status = ".PRESCRIPTION_STATUS_ISSUED.", date_issued = '".$this->db->idate($now)."', fk_user_issue = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".PRESCRIPTION_STATUS_DRAFT;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			$this->error = 'PrescriptionErrCannotIssue';
			$this->db->rollback();
			return -2;
		}
		$this->status = PRESCRIPTION_STATUS_ISSUED;
		$this->date_issued = $now;
		$this->fk_user_issue = (int) $user->id;
		patient_audit($this->db, $this->fk_patient, 'PRESCRIPTION_ISSUE', $user, array('ref' => $this->ref, 'prescription' => $this->id, 'lines' => count($this->lines), 'allergy_hits' => count($this->allergyHits)));
		$this->db->commit();
		return 1;
	}

	/**
	 * Draft/issued -> voided with a mandatory reason. Nothing deleted.
	 *
	 * @param	User	$user	Acting user
	 * @param	string	$reason	Reason (required)
	 * @return	int				1 ok, -2 not allowed, -1 error
	 */
	public function void(User $user, $reason)
	{
		$this->error = '';
		$reason = trim((string) $reason);
		if ($this->id <= 0 || !$this->canVoid($user)) {
			$this->error = 'PrescriptionErrCannotVoid';
			return -2;
		}
		if ($reason === '') {
			$this->error = 'PrescriptionErrVoidReasonRequired';
			return -1;
		}
		$now = dol_now();
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix()."prescription SET status = ".PRESCRIPTION_STATUS_VOIDED.", void_reason = '".$this->db->escape(dol_substr($reason, 0, 255))."',";
		$sql .= " date_void = '".$this->db->idate($now)."', fk_user_void = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status IN (".PRESCRIPTION_STATUS_DRAFT.", ".PRESCRIPTION_STATUS_ISSUED.")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->status = PRESCRIPTION_STATUS_VOIDED;
		$this->void_reason = $reason;
		$this->date_void = $now;
		$this->fk_user_void = (int) $user->id;
		patient_audit($this->db, $this->fk_patient, 'PRESCRIPTION_VOID', $user, array('ref' => $this->ref, 'prescription' => $this->id, 'reason' => dol_substr($reason, 0, 100)));
		$this->db->commit();
		return 1;
	}

	/**
	 * Unsaved draft copied from this prescription (type, lines, doses, usage).
	 *
	 * @return	PrescriptionSheet
	 */
	public function copyAsNew()
	{
		$copy = new PrescriptionSheet($this->db);
		foreach (array('presc_type', 'fk_patient', 'fk_medrecord', 'fk_doctor', 'fk_department', 'diagnosis_text', 'doses', 'decoct_mode', 'usage_note', 'note') as $f) {
			$copy->$f = $this->$f;
		}
		$copy->lines = array();
		foreach ($this->lines as $l) {
			$l['allergy_hit'] = 0;
			$copy->lines[] = $l;
		}
		return $copy;
	}

	// ------------------------------------------------------------ list

	/**
	 * @param	array	$f		Filters: q, patient, medrecord, doctor, type, status (-1 = non-voided), from, to
	 * @param	int		$limit	Page size
	 * @param	int		$offset	Offset
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function search(array $f, $limit = 25, $offset = 0)
	{
		global $conf;

		$from = " FROM ".$this->db->prefix()."prescription as p";
		$from .= " INNER JOIN ".$this->db->prefix()."patient_profile as pp ON pp.rowid = p.fk_patient";
		$from .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = pp.fk_soc";
		$from .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = p.fk_doctor";
		$where = " WHERE p.entity = ".((int) $conf->entity);
		if (!empty($f['q'])) {
			$like = "'%".$this->db->escape(trim($f['q']))."%'";
			$where .= " AND (p.ref LIKE ".$like." OR pp.card_no LIKE ".$like." OR s.nom LIKE ".$like.")";
		}
		foreach (array('patient' => 'p.fk_patient', 'medrecord' => 'p.fk_medrecord', 'doctor' => 'p.fk_doctor') as $key => $col) {
			if (!empty($f[$key])) {
				$where .= " AND ".$col." = ".((int) $f[$key]);
			}
		}
		if (!empty($f['type']) && in_array($f['type'], array(PRESCRIPTION_TYPE_TCM, PRESCRIPTION_TYPE_WM), true)) {
			$where .= " AND p.presc_type = '".$this->db->escape($f['type'])."'";
		}
		if (isset($f['status']) && (int) $f['status'] >= 0) {
			$where .= " AND p.status = ".((int) $f['status']);
		} elseif (empty($f['include_voided'])) {
			$where .= " AND p.status <> ".PRESCRIPTION_STATUS_VOIDED;
		}
		if (!empty($f['from'])) {
			$where .= " AND p.date_presc >= '".$this->db->idate((int) $f['from'])."'";
		}
		if (!empty($f['to'])) {
			$where .= " AND p.date_presc <= '".$this->db->idate((int) $f['to'])."'";
		}

		$resql = $this->db->query("SELECT COUNT(p.rowid) as total".$from.$where);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$total = (int) $this->db->fetch_object($resql)->total;
		$this->db->free($resql);

		$sql = "SELECT p.rowid, p.ref, p.presc_type, p.fk_patient, p.fk_medrecord, p.fk_doctor, p.date_presc, p.status, p.doses, p.diagnosis_text,";
		$sql .= " pp.card_no, s.nom as patient_name, u.lastname, u.firstname,";
		$sql .= " (SELECT COUNT(l.rowid) FROM ".$this->db->prefix()."prescription_line as l WHERE l.fk_prescription = p.rowid) as nb_lines";
		$sql .= $from.$where." ORDER BY p.date_presc DESC, p.rowid DESC".$this->db->plimit((int) $limit, (int) $offset);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);
		return array('total' => $total, 'rows' => $rows);
	}

	// ------------------------------------------------------------ PDF (spec §3.6)

	/** @var array|null Patient summary preloaded for the sheet */
	public $patient;
	/** @var string Doctor full name preloaded for the sheet */
	public $doctor_name = '';
	/** @var string Department label preloaded for the sheet */
	public $department_label = '';
	/** @var array<string,array<string,string>> Dictionaries preloaded for the sheet */
	public $dicts = array();

	/**
	 * Preload everything the sheet needs (patient summary, doctor, department,
	 * dictionaries) so the template does not query on its own.
	 *
	 * @return	void
	 */
	public function preparePdfContext()
	{
		global $langs;

		$this->patient = patient_get_summary($this->db, $this->fk_patient);
		$this->doctor_name = '';
		if ($this->fk_doctor > 0) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
			$u = new User($this->db);
			if ($u->fetch($this->fk_doctor) > 0) {
				$this->doctor_name = trim($u->lastname.' '.$u->firstname);
				if ($this->doctor_name === '') {
					$this->doctor_name = $u->login;
				}
			}
		}
		$departments = patient_dict_rows($this->db, 'c_patient_department');
		$this->department_label = ($this->fk_department && isset($departments[$this->fk_department])) ? $departments[$this->fk_department] : '';
		$this->dicts = array(
			'decoct' => prescription_dict_options($this->db, 'c_prescription_decoct'),
			'route' => prescription_dict_options($this->db, 'c_prescription_route'),
			'freq' => prescription_dict_options($this->db, 'c_prescription_freq'),
			'dose_unit' => prescription_dict_options($this->db, 'c_prescription_dose_unit'),
		);
	}

	/**
	 * Build the sheet with the layout matching the type (cf_tcm / cf_wm)
	 * through the core generator lookup (module_parts['models'] = 1).
	 *
	 * @param	string		$modele			'' = by type
	 * @param	Translate	$outputlangs	Lang
	 * @param	int			$hidedetails	Unused
	 * @param	int			$hidedesc		Unused
	 * @param	int			$hideref		Unused
	 * @param	mixed		$moreparams		Unused
	 * @return	int							1 ok, <0 error
	 */
	public function generateDocument($modele = '', $outputlangs = null, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		global $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if ($modele === '' || $modele === null) {
			$modele = ($this->presc_type === PRESCRIPTION_TYPE_WM) ? 'cf_wm' : 'cf_tcm';
		}
		if (!in_array($modele, array('cf_tcm', 'cf_wm'), true)) {
			$this->error = 'Unknown prescription sheet model';
			return -1;
		}
		$this->preparePdfContext();
		$this->model_pdf = $modele;
		return $this->commonGenerateDocument('core/modules/prescription/doc/', $modele, $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
	}

	/**
	 * @return	string	Absolute path of the sheet file (may not exist yet)
	 */
	public function pdfPath()
	{
		global $conf;
		$ref = dol_sanitizeFileName($this->ref);
		return (empty($conf->prescription->dir_output) ? '' : $conf->prescription->dir_output).'/'.$ref.'/'.$ref.'.pdf';
	}

	// ------------------------------------------------------------ helpers

	/**
	 * Field-level diff for the audit trail (header fields + a line summary).
	 *
	 * @param	PrescriptionSheet	$old	Stored copy
	 * @return	array<string,array{old:string,new:string}>
	 */
	public function diffAgainst(PrescriptionSheet $old)
	{
		$changes = array();
		foreach (array('fk_doctor', 'fk_department', 'fk_medrecord', 'doses') as $f) {
			$a = (int) $old->$f > 0 ? (string) (int) $old->$f : '';
			$b = (int) $this->$f > 0 ? (string) (int) $this->$f : '';
			if ($a !== $b) {
				$changes[$f] = array('old' => $a, 'new' => $b);
			}
		}
		foreach (array('diagnosis_text', 'decoct_mode', 'usage_note', 'note', 'allergy_override_reason') as $f) {
			$a = trim((string) $old->$f);
			$b = trim((string) $this->$f);
			if ($a !== $b) {
				$changes[$f] = array('old' => dol_substr($a, 0, 1000), 'new' => dol_substr($b, 0, 1000));
			}
		}
		if ((int) $old->date_presc !== (int) $this->date_presc) {
			$changes['date_presc'] = array('old' => dol_print_date($old->date_presc, 'dayhour'), 'new' => dol_print_date($this->date_presc, 'dayhour'));
		}
		$a = $this->linesSummary($old->lines);
		$b = $this->linesSummary($this->lines);
		if ($a !== $b) {
			$changes['lines'] = array('old' => $a, 'new' => $b);
		}
		return $changes;
	}

	/**
	 * One line per drug, for diffs and audit.
	 *
	 * @param	array	$lines	Lines
	 * @return	string
	 */
	public function linesSummary($lines)
	{
		$out = array();
		foreach ((array) $lines as $l) {
			$s = $l['label'];
			if ($l['qty'] !== null) {
				$s .= ' '.rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ''), '0'), '.').($l['qty_unit'] ?? '');
			}
			if ($l['decoct_code']) {
				$s .= '('.$l['decoct_code'].')';
			}
			if ($l['dose'] !== null) {
				$s .= ' '.rtrim(rtrim(number_format((float) $l['dose'], 3, '.', ''), '0'), '.').($l['dose_unit'] ?? '');
			}
			foreach (array('route_code', 'freq_code') as $f) {
				if ($l[$f]) {
					$s .= ' '.$l[$f];
				}
			}
			if ($l['days'] !== null) {
				$s .= ' '.$l['days'].'d';
			}
			if ($l['sig_note']) {
				$s .= ' '.$l['sig_note'];
			}
			$out[] = $s;
		}
		return implode("\n", $out);
	}

	/**
	 * @param	int		$withpicto	0/1
	 * @return	string				Link
	 */
	public function getNomUrl($withpicto = 0)
	{
		$url = dol_buildpath('/prescription/card.php', 1).'?id='.((int) $this->id);
		$out = '<a href="'.$url.'">';
		if ($withpicto) {
			$out .= img_picto('', $this->picto, 'class="pictofixedwidth"');
		}
		return $out.dol_escape_htmltag($this->ref).'</a>';
	}

	/**
	 * @param	int		$mode	Unused
	 * @return	string			Status badge
	 */
	public function getLibStatut($mode = 0)
	{
		return prescription_status_badge($this->status);
	}

	/**
	 * @param	mixed	$value	Value
	 * @return	string			SQL literal or NULL
	 */
	private function nullOrString($value)
	{
		if ($value === null || $value === '') {
			return 'NULL';
		}
		return "'".$this->db->escape((string) $value)."'";
	}
}
