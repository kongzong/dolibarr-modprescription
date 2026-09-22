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

use Luracast\Restler\RestException;

/**
 * \file    htdocs/custom/prescription/class/api_prescription.class.php
 * \ingroup prescription
 * \brief   REST API for prescriptions (spec §3.7). The URL resource is
 *          "prescriptions" (plural, per the spec) while the class name is
 *          "Prescriptions" (plural, distinct from the business class
 *          "PrescriptionSheet" which PHP treats as the same identifier).
 *
 *          The allergy gate, state machine and audit trail are the same as
 *          the UI: every endpoint calls the PrescriptionSheet class, so a hit is
 *          reported as 409 with the matched lines and whether the caller
 *          may override (spec §3.3). No endpoint exposes the patient's
 *          identity document.
 */

dol_include_once('/prescription/class/prescriptionsheet.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * API class for Prescriptions module
 *
 * @url     GET /prescriptions
 * @access  protected
 * @class   DolibarrApiAccess {@requires user,external}
 */
class Prescription extends DolibarrApi
{
	/**
	 * @var DoliDB $db Database object
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @url GET /
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * List prescriptions.
	 *
	 * @url	GET prescriptions
	 *
	 * @param	string	$q			Ref / card no. / patient name
	 * @param	int		$patient	Patient rowid
	 * @param	int		$medrecord	Medical record rowid
	 * @param	int		$doctor		Doctor user id
	 * @param	string	$type		TCM | WM
	 * @param	int		$status		-1 all non-voided (default), 0 draft, 1 issued, 2 dispensed, 9 voided
	 * @param	string	$from		PrescriptionSheet date from (YYYY-MM-DD)
	 * @param	string	$to			PrescriptionSheet date to (YYYY-MM-DD)
	 * @param	int		$limit		Page size (max 100)
	 * @param	int		$page		Page (0-based)
	 * @return	array		Paginated list: total + rows
	 * @throws RestException 403 Not allowed
	 */
	public function index($q = '', $patient = 0, $medrecord = 0, $doctor = 0, $type = '', $status = -1, $from = '', $to = '', $limit = 25, $page = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('prescription', 'read')) {
			throw new RestException(403);
		}
		$limit = max(1, min(100, (int) $limit));
		$page = max(0, (int) $page);
		$filters = array(
			'q' => (string) $q,
			'patient' => (int) $patient,
			'medrecord' => (int) $medrecord,
			'doctor' => (int) $doctor,
			'type' => (string) $type,
			'status' => (int) $status,
			'from' => $from !== '' ? $this->dateToTs($from, false) : 0,
			'to' => $to !== '' ? $this->dateToTs($to, true) : 0,
		);
		$dao = new PrescriptionSheet($this->db);
		$result = $dao->search($filters, $limit, $limit * $page);
		if ($result === null) {
			throw new RestException(500, 'Search failed: '.$dao->error);
		}
		$rows = array();
		foreach ($result['rows'] as $r) {
			$rows[] = $this->listRow($r);
		}
		return array('total' => (int) $result['total'], 'rows' => $rows);
	}

	/**
	 * Get one prescription with its lines (medical data: audited as
	 * PRESCRIPTION_READ).
	 *
	 * @url	GET prescriptions/{id}
	 *
	 * @param	int		$id		PrescriptionSheet rowid
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function get($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('prescription', 'read')) {
			throw new RestException(403);
		}
		$presc = $this->load($id);
		patient_audit($this->db, $presc->fk_patient, 'PRESCRIPTION_READ', DolibarrApiAccess::$user, array('ref' => $presc->ref, 'prescription' => $presc->id, 'via' => 'api'));
		return $this->fields($presc);
	}

	/**
	 * Create a draft.
	 *
	 * Body: { "fk_patient": 1, "presc_type": "TCM"|"WM", "fk_medrecord": 0, "fk_doctor": 2, "fk_department": 0,
	 *         "date_presc": "YYYY-MM-DD HH:MM", "diagnosis_text": "...", "doses": 7, "decoct_mode": "SELF",
	 *         "usage_note": "...", "note": "...", "allergy_override_reason": "...",
	 *         "lines": [ { "label": "...", "fk_product": 0, "product_ref": "...", "qty": 12, "qty_unit": "g",
	 *                       "decoct_code": "HOUXIA", "dose": 0.5, "dose_unit": "ML", "route_code": "PO",
	 *                       "freq_code": "TID", "days": 7, "sig_note": "..." } ] }
	 *
	 * @url	POST prescriptions
	 *
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 400 Bad parameters
	 * @throws RestException 409 Allergy conflict blocked the save (see allergy_hits)
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('prescription', 'write')) {
			throw new RestException(403);
		}
		$data = is_array($request_data) ? $request_data : array();
		$presc = new PrescriptionSheet($this->db);
		$presc->presc_type = isset($data['presc_type']) ? (string) $data['presc_type'] : PRESCRIPTION_TYPE_TCM;
		$presc->fk_patient = isset($data['fk_patient']) ? (int) $data['fk_patient'] : 0;
		$this->apply($presc, $data);
		$result = $presc->create(DolibarrApiAccess::$user);
		if ($result > 0) {
			return $this->fields($presc);
		}
		if ($result === PrescriptionSheet::ERR_ALLERGY) {
			throw $this->allergyException($presc);
		}
		throw new RestException(400, 'Creation failed: '.$presc->error);
	}

	/**
	 * Update a draft. Patient / ref / type never change.
	 * Body: same fields as POST except fk_patient / presc_type.
	 *
	 * @url	PUT prescriptions/{id}
	 *
	 * @param	int		$id				PrescriptionSheet rowid
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed / not editable
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Bad parameters
	 * @throws RestException 409 Allergy conflict blocked the save
	 */
	public function put($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('prescription', 'write')) {
			throw new RestException(403);
		}
		$presc = $this->load($id);
		if (!$presc->canEdit(DolibarrApiAccess::$user)) {
			throw new RestException(403, 'PrescriptionSheet is not editable');
		}
		$this->apply($presc, is_array($request_data) ? $request_data : array());
		$result = $presc->update(DolibarrApiAccess::$user);
		if ($result > 0) {
			return $this->fields($presc);
		}
		if ($result === PrescriptionSheet::ERR_ALLERGY) {
			throw $this->allergyException($presc);
		}
		if ($result === -2) {
			throw new RestException(403, $presc->error);
		}
		throw new RestException(400, 'Update failed: '.$presc->error);
	}

	/**
	 * Issue a draft (attending doctor or admin with 'issue'). The sheet PDF
	 * is generated right after, as on the UI.
	 *
	 * Body: { "allergy_override_reason": "..." } optional, required to
	 * release a light/moderate allergy hit.
	 *
	 * @url	POST prescriptions/{id}/issue
	 *
	 * @param	int		$id				PrescriptionSheet rowid
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Missing lines / incomplete sig
	 * @throws RestException 409 Allergy conflict blocked the issue
	 */
	public function issue($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('prescription', 'issue')) {
			throw new RestException(403);
		}
		$presc = $this->load($id);
		if (is_array($request_data) && !empty($request_data['allergy_override_reason'])) {
			$presc->allergy_override_reason = (string) $request_data['allergy_override_reason'];
		}
		$result = $presc->issue(DolibarrApiAccess::$user);
		if ($result > 0) {
			$presc->generateDocument('', null);
			$this->reload($presc);
			return $this->fields($presc);
		}
		if ($result === PrescriptionSheet::ERR_ALLERGY) {
			throw $this->allergyException($presc);
		}
		if ($result === -2) {
			throw new RestException(403, $presc->error);
		}
		throw new RestException(400, 'Issue failed: '.$presc->error);
	}

	/**
	 * Void a prescription (draft or issued; dispensed are handled by
	 * modPharmacy). Body: { "reason": "..." } (required). Nothing is deleted.
	 *
	 * @url	POST prescriptions/{id}/void
	 *
	 * @param	int		$id				PrescriptionSheet rowid
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Reason missing
	 */
	public function void($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('prescription', 'void')) {
			throw new RestException(403);
		}
		$presc = $this->load($id);
		$reason = is_array($request_data) && isset($request_data['reason']) ? (string) $request_data['reason'] : '';
		$result = $presc->void(DolibarrApiAccess::$user, $reason);
		if ($result > 0) {
			$this->reload($presc);
			return $this->fields($presc);
		}
		if ($result === -2) {
			throw new RestException(403, $presc->error);
		}
		throw new RestException(400, 'Void failed: '.$presc->error);
	}

	/**
	 * Copy a prescription into a new unsaved draft shape (the caller creates
	 * it with POST prescriptions and passes the same lines). The source is
	 * returned with its lines; a PRESCRIPTION_COPY audit row is written.
	 *
	 * @url	POST prescriptions/{id}/copy
	 *
	 * @param	int		$id		PrescriptionSheet rowid
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function copy($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('prescription', 'write')) {
			throw new RestException(403);
		}
		$presc = $this->load($id);
		patient_audit($this->db, $presc->fk_patient, 'PRESCRIPTION_COPY', DolibarrApiAccess::$user, array('ref' => $presc->ref, 'prescription' => $presc->id, 'via' => 'api'));
		$out = $this->fields($presc);
		$out['copy'] = true;
		unset($out['id'], $out['ref'], $out['status'], $out['date_issued'], $out['fk_user_issue']);
		return $out;
	}

	// ------------------------------------------------------------ helpers

	/**
	 * @param	int		$id		PrescriptionSheet rowid
	 * @return	PrescriptionSheet
	 * @throws	RestException 404
	 */
	private function load($id)
	{
		$presc = new PrescriptionSheet($this->db);
		if ((int) $id <= 0 || $presc->fetch((int) $id) <= 0) {
			throw new RestException(404, 'PrescriptionSheet not found');
		}
		return $presc;
	}

	/**
	 * Re-fetch after a state change so the response reflects the stored row.
	 *
	 * @param	PrescriptionSheet	$p	Object
	 * @return	void
	 */
	private function reload(PrescriptionSheet $p)
	{
		$p->fetch($p->id);
	}

	/**
	 * Build the 409 allergy response.
	 *
	 * @param	PrescriptionSheet	$p	Object with allergyHits filled
	 * @return	RestException		409
	 */
	private function allergyException(PrescriptionSheet $p)
	{
		$override = $p->overridePossible(DolibarrApiAccess::$user);
		return new RestException(409, 'Allergy conflict: '.$p->error, array(
			'allergy_hits' => $p->allergyHits,
			'override_possible' => $override,
			'override_required_reason' => $override,
		));
	}

	/**
	 * Copy body fields onto the object (header + lines).
	 *
	 * @param	PrescriptionSheet	$presc	Target
	 * @param	array			$data	Body
	 * @return	void
	 */
	private function apply(PrescriptionSheet $presc, array $data)
	{
		if (isset($data['fk_medrecord'])) {
			$presc->fk_medrecord = (int) $data['fk_medrecord'] > 0 ? (int) $data['fk_medrecord'] : null;
		}
		if (isset($data['fk_doctor'])) {
			$presc->fk_doctor = (int) $data['fk_doctor'];
		}
		if (isset($data['fk_department'])) {
			$presc->fk_department = (int) $data['fk_department'] > 0 ? (int) $data['fk_department'] : null;
		}
		if (!empty($data['date_presc'])) {
			$ts = $this->dateToTs((string) $data['date_presc'], false);
			if ($ts > 0) {
				$presc->date_presc = $ts;
			}
		}
		foreach (array('diagnosis_text', 'usage_note', 'note', 'allergy_override_reason', 'decoct_mode') as $f) {
			if (array_key_exists($f, $data) && $data[$f] !== null) {
				$presc->$f = (string) $data[$f];
			}
		}
		if (isset($data['doses'])) {
			$presc->doses = (int) $data['doses'];
		}
		if (isset($data['lines']) && is_array($data['lines'])) {
			$presc->lines = array();
			foreach ($data['lines'] as $l) {
				if (!is_array($l) || empty($l['label'])) {
					continue;
				}
				$row = array('label' => (string) $l['label'], 'allergy_hit' => 0);
				foreach (array('product_ref') as $f) {
					$row[$f] = isset($l[$f]) ? (string) $l[$f] : null;
				}
				$row['fk_product'] = !empty($l['fk_product']) ? (int) $l['fk_product'] : null;
				foreach (array('qty', 'dose') as $f) {
					$row[$f] = isset($l[$f]) && $l[$f] !== '' ? price2num($l[$f]) : null;
				}
				foreach (array('qty_unit', 'decoct_code', 'dose_unit', 'route_code', 'freq_code', 'sig_note') as $f) {
					$row[$f] = isset($l[$f]) && $l[$f] !== '' ? (string) $l[$f] : null;
				}
				$row['days'] = isset($l['days']) && $l['days'] !== '' ? (int) $l['days'] : null;
				$presc->lines[] = $row;
			}
		}
	}

	/**
	 * @param	string	$s		YYYY-MM-DD[ HH:MM[:SS]]
	 * @param	bool	$endOfDay	Use 23:59:59 when no time given
	 * @return	int				Timestamp or 0
	 */
	private function dateToTs($s, $endOfDay)
	{
		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[ T]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?)?$/', trim($s), $m)) {
			return 0;
		}
		$h = isset($m[4]) ? (int) $m[4] : ($endOfDay ? 23 : 0);
		$i = isset($m[5]) ? (int) $m[5] : ($endOfDay ? 59 : 0);
		$sec = isset($m[6]) ? (int) $m[6] : ($endOfDay ? 59 : 0);
		return (int) dol_mktime($h, $i, $sec, (int) $m[2], (int) $m[3], (int) $m[1]);
	}

	/**
	 * One list row. No patient identity document.
	 *
	 * @param	object	$r	Row
	 * @return	array
	 */
	private function listRow($r)
	{
		$summary = patient_get_summary($this->db, $r->fk_patient);
		$doctor = trim($r->lastname.' '.$r->firstname);
		return array(
			'id' => (int) $r->rowid,
			'ref' => $r->ref,
			'presc_type' => $r->presc_type,
			'fk_patient' => (int) $r->fk_patient,
			'card_no' => $summary ? $summary['card_no'] : null,
			'patient_name' => $summary ? $summary['name'] : null,
			'fk_medrecord' => $r->fk_medrecord ? (int) $r->fk_medrecord : null,
			'fk_doctor' => (int) $r->fk_doctor,
			'doctor_name' => $doctor === '' ? null : $doctor,
			'date_presc' => dol_print_date($this->db->jdate($r->date_presc), 'dayhourrfc'),
			'status' => (int) $r->status,
			'doses' => $r->doses !== null ? (int) $r->doses : null,
			'diagnosis_text' => $r->diagnosis_text,
			'nb_lines' => (int) $r->nb_lines,
		);
	}

	/**
	 * Full fields. No patient identity document (card no. / name only,
	 * through patient_get_summary).
	 *
	 * @param	PrescriptionSheet	$p	Loaded object
	 * @return	array
	 */
	private function fields(PrescriptionSheet $p)
	{
		$summary = patient_get_summary($this->db, $p->fk_patient);
		$out = array(
			'id' => (int) $p->id,
			'ref' => $p->ref,
			'presc_type' => $p->presc_type,
			'fk_patient' => (int) $p->fk_patient,
			'card_no' => $summary ? $summary['card_no'] : null,
			'patient_name' => $summary ? $summary['name'] : null,
			'fk_medrecord' => $p->fk_medrecord,
			'fk_doctor' => (int) $p->fk_doctor,
			'fk_department' => $p->fk_department,
			'date_presc' => dol_print_date($p->date_presc, 'dayhourrfc'),
			'diagnosis_text' => $p->diagnosis_text,
			'doses' => $p->doses,
			'decoct_mode' => $p->decoct_mode,
			'usage_note' => $p->usage_note,
			'note' => $p->note,
			'allergy_override_reason' => $p->allergy_override_reason,
			'status' => (int) $p->status,
			'date_issued' => $p->date_issued ? dol_print_date($p->date_issued, 'dayhourrfc') : null,
			'fk_user_issue' => $p->fk_user_issue,
			'void_reason' => $p->void_reason,
			'date_void' => $p->date_void ? dol_print_date($p->date_void, 'dayhourrfc') : null,
			'date_creation' => $p->date_creation ? dol_print_date($p->date_creation, 'dayhourrfc') : null,
			'lines' => $p->lines,
		);
		return $out;
	}
}
