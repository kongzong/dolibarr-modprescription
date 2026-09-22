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
 * \file    htdocs/custom/prescription/core/modules/prescription/modules_prescription.php
 * \ingroup prescription
 * \brief   Parent class of the prescription sheet generators. Renders the
 *          parts shared by both layouts (spec §3.6): header (前记), footer
 *          (后记 with signature slots), page numbers, draft / voided
 *          watermark, pagination with a repeated "(续)" header. Subclasses
 *          only draw the body (正文). Chinese text uses TCPDF's built-in
 *          stsongstdlight font, as validated by modChinaDoc.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commondocgenerator.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Parent class for prescription sheet models
 */
abstract class ModelePDFPrescription extends CommonDocGenerator
{
	public $db;
	public $name;
	public $description;
	public $type = 'pdf';
	public $marge_gauche;
	public $marge_droite;
	public $marge_haute;
	public $marge_basse;
	public $page_largeur;
	public $page_hauteur;
	public $emetteur;
	protected $font = 'stsongstdlight';
	protected $fontSize = 10;
	protected $lineHeight;

	/** @var array<string,array<string,string>> Dictionaries preloaded by PrescriptionSheet::generateDocument() */
	protected $dicts = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $mysoc;

		$this->db = $db;
		$this->update_main_doc_field = 1;
		$fmt = self::paperFormat();
		$this->page_largeur = $fmt[0];
		$this->page_hauteur = $fmt[1];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = 12;
		$this->marge_droite = 12;
		$this->marge_haute = 10;
		$this->marge_basse = 10;
		$this->emetteur = $mysoc;
		if (is_object($this->emetteur) && empty($this->emetteur->country_code) && is_object($langs)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2);
		}
	}

	/**
	 * Paper size from PRESCRIPTION_PDF_FORMAT (A4 default, A5 optional).
	 *
	 * @return	array{0:float,1:float}	width, height in mm
	 */
	public static function paperFormat()
	{
		return getDolGlobalString('PRESCRIPTION_PDF_FORMAT', 'A4') === 'A5' ? array(148, 210) : array(210, 297);
	}

	/**
	 * Available models (both layouts; the object type decides which is used).
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	int		$maxfilenamelength	Unused
	 * @return	array<string,string>
	 */
	public static function liste_modeles($db, $maxfilenamelength = 0)
	{
		return array('cf_tcm' => 'cf_tcm', 'cf_wm' => 'cf_wm');
	}

	/**
	 * Body renderer implemented by each layout. Must call $this->newPage()
	 * when $y would pass $this->bodyBottom().
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			PrescriptionSheet (with ->lines, ->patient, ->doctor_name...)
	 * @param	Translate	$outputlangs	Lang
	 * @param	float		$y				Start Y
	 * @return	float						Y after the body
	 */
	abstract protected function drawBody($pdf, $object, $outputlangs, $y);

	/**
	 * Build the sheet: DOL_DATA_ROOT/prescription/<ref>/<ref>.pdf
	 *
	 * @param	object		$object				PrescriptionSheet
	 * @param	Translate	$outputlangs		Lang
	 * @param	string		$srctemplatepath	Unused
	 * @param	int			$hidedetails		Unused
	 * @param	int			$hidedesc			Unused
	 * @param	int			$hideref			Unused
	 * @return	int								1 ok, <=0 error
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $user;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'companies', 'patient@patient', 'prescription@prescription'));
		if (empty($conf->prescription->dir_output)) {
			$this->error = 'PRESCRIPTION_OUTPUTDIR undefined (module not enabled?)';
			return 0;
		}
		if (!empty($object->dicts)) {
			$this->dicts = $object->dicts;
		}
		$ref = dol_sanitizeFileName($object->ref);
		$dir = $conf->prescription->dir_output.'/'.$ref;
		$file = $dir.'/'.$ref.'.pdf';
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', $dir);
			return -1;
		}

		$pdf = pdf_getInstance($this->format);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetAutoPageBreak(false, 0);
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->setCellPaddings(0.8, 0.6, 0.8, 0.6);
		$pdf->setCellHeightRatio(1.3);
		$pdf->SetLineWidth(0.2);
		$this->fontSize = ($this->page_largeur < 200) ? 9 : 10.5;
		$pdf->SetFont($this->font, '', $this->fontSize);
		$this->lineHeight = $pdf->getStringHeight(100, 'X', true, false);
		$pdf->SetTitle($object->ref);
		$pdf->SetSubject($outputlangs->transnoentities('PrescriptionSheet'));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		if (is_object($user) && method_exists($user, 'getFullName')) {
			$pdf->SetAuthor($user->getFullName($outputlangs));
		}

		$this->pdfObject = $object;
		$this->pdfLangs = $outputlangs;
		$y = $this->newPage($pdf, true);
		$y = $this->drawBody($pdf, $object, $outputlangs, $y);
		$this->drawFooter($pdf, $object, $outputlangs, $y);

		// Page numbers + watermark on every page
		$pages = $pdf->getNumPages();
		for ($p = 1; $p <= $pages; $p++) {
			$pdf->setPage($p);
			$pdf->SetFont($this->font, '', 7);
			$pdf->SetTextColor(100, 100, 100);
			$this->text($pdf, $this->page_largeur - $this->marge_droite - 30, $this->page_hauteur - $this->marge_basse - 3, 30, $outputlangs->transnoentities('PrescriptionPage', $p, $pages), 'R');
			$pdf->SetTextColor(0, 0, 0);
			$this->watermark($pdf, $object, $outputlangs);
		}

		$pdf->Close();
		$pdf->Output($file, 'F');
		dolChmod($file);
		$this->result = array('fullpath' => $file);
		return 1;
	}

	/** @var object */
	protected $pdfObject;
	/** @var Translate */
	protected $pdfLangs;

	// ------------------------------------------------------------ shared drawing

	/**
	 * @return	float	Usable width
	 */
	protected function contentWidth()
	{
		return $this->page_largeur - $this->marge_gauche - $this->marge_droite;
	}

	/**
	 * @return	float	Lowest Y the body may use on a page (footer reserved on the last page by drawFooter)
	 */
	protected function bodyBottom()
	{
		return $this->page_hauteur - $this->marge_basse - 8;
	}

	/**
	 * Height reserved by the footer block (signatures + amount + notes).
	 *
	 * @return	float
	 */
	protected function footerHeight()
	{
		return 34;
	}

	/**
	 * Draw a plain text block and return its bottom Y.
	 *
	 * @param	TCPDF	$pdf	PDF
	 * @param	float	$x		X
	 * @param	float	$y		Y
	 * @param	float	$w		Width
	 * @param	string	$text	Text
	 * @param	string	$align	L/C/R
	 * @return	float
	 */
	protected function text($pdf, $x, $y, $w, $text, $align = 'L')
	{
		$h = $pdf->getStringHeight($w, $text, true);
		$pdf->MultiCell($w, $h, $text, 0, $align, false, 1, $x, $y, true);
		return $y + $h;
	}

	/**
	 * New page with the header (前记); continuation pages get a shorter header.
	 *
	 * @param	TCPDF	$pdf	PDF
	 * @param	bool	$first	First page
	 * @return	float			Y where the body starts
	 */
	protected function newPage($pdf, $first = false)
	{
		$pdf->AddPage();
		return $this->drawHeader($pdf, $this->pdfObject, $this->pdfLangs, $first);
	}

	/**
	 * 前记: institution, title, number / payer / date, patient line, department, diagnosis.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			PrescriptionSheet
	 * @param	Translate	$outputlangs	Lang
	 * @param	bool		$first			First page (full header) or continuation
	 * @return	float						Y after the header
	 */
	protected function drawHeader($pdf, $object, $outputlangs, $first)
	{
		$x = $this->marge_gauche;
		$w = $this->contentWidth();
		$pdf->SetTextColor(0, 0, 0);

		$pdf->SetFont($this->font, '', $first ? 15 : 12);
		$y = $this->text($pdf, $x, $this->marge_haute, $w, (is_object($this->emetteur) ? $this->emetteur->name : ''), 'C');
		$pdf->SetFont($this->font, '', $first ? 13 : 11);
		$title = $outputlangs->transnoentities('PrescriptionSheet').($first ? '' : $outputlangs->transnoentities('PrescriptionContinued'));
		$y = $this->text($pdf, $x, $y + 1, $w, $title, 'C') + 1;

		$pdf->SetFont($this->font, '', $this->fontSize);
		$third = $w / 3;
		$this->text($pdf, $x, $y, $third, $outputlangs->transnoentities('PrescriptionRef').': '.$object->ref);
		$this->text($pdf, $x + $third, $y, $third, $outputlangs->transnoentities('PrescriptionPayer').': '.$outputlangs->transnoentities('PrescriptionPayerSelf'), 'C');
		$y = $this->text($pdf, $x + 2 * $third, $y, $third, $outputlangs->transnoentities('PrescriptionDate').': '.dol_print_date($object->date_presc, 'day', false, $outputlangs, true), 'R');

		$patient = isset($object->patient) && is_array($object->patient) ? $object->patient : array();
		$line = array();
		$line[] = $outputlangs->transnoentities('Name').': '.(isset($patient['name']) ? $patient['name'] : '');
		$line[] = $outputlangs->transnoentities('PatientGender').': '.(isset($patient['gender_label']) ? $patient['gender_label'] : '');
		$line[] = $outputlangs->transnoentities('PatientAge').': '.(isset($patient['age']) && $patient['age'] !== null ? $patient['age'] : '');
		$line[] = $outputlangs->transnoentities('PatientCardNo').': '.(isset($patient['card_no']) ? $patient['card_no'] : '');
		$y = $this->text($pdf, $x, $y + 0.5, $w, implode('    ', $line));
		if ($first) {
			$dept = isset($object->department_label) ? $object->department_label : '';
			$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('PrescriptionDepartment').': '.$dept.'    '.$outputlangs->transnoentities('PrescriptionDiagnosis').': '.(string) $object->diagnosis_text);
		}
		$y += 1;
		$pdf->Line($x, $y, $x + $w, $y);
		return $y + 2;
	}

	/**
	 * 后记: doctor signature, four pharmacist slots, amount (blank), override
	 * note, retention hint. Starts a new page when it does not fit.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			PrescriptionSheet
	 * @param	Translate	$outputlangs	Lang
	 * @param	float		$y				Y after the body
	 * @return	void
	 */
	protected function drawFooter($pdf, $object, $outputlangs, $y)
	{
		if ($y + $this->footerHeight() > $this->bodyBottom()) {
			$y = $this->newPage($pdf, false);
		}
		$x = $this->marge_gauche;
		$w = $this->contentWidth();
		$y = max($y + 4, $this->bodyBottom() - $this->footerHeight());
		$pdf->Line($x, $y, $x + $w, $y);
		$y += 2;
		$pdf->SetFont($this->font, '', $this->fontSize);

		$doctor = isset($object->doctor_name) ? $object->doctor_name : '';
		$issued = !empty($object->date_issued) ? dol_print_date($object->date_issued, 'day', false, $outputlangs, true) : '';
		$this->text($pdf, $x, $y, $w / 2, $outputlangs->transnoentities('PrescriptionDoctorSign').': '.$doctor.'  ________________');
		$y = $this->text($pdf, $x + $w / 2, $y, $w / 2, $outputlangs->transnoentities('PrescriptionAmount').': ________________    '.($issued ? $outputlangs->transnoentities('PrescriptionIssuedBy').': '.$issued : ''), 'R');

		$slots = array('PrescriptionSlotReview', 'PrescriptionSlotDispense', 'PrescriptionSlotCheck', 'PrescriptionSlotHandout');
		$sw = $w / 4;
		$y += 2;
		foreach ($slots as $i => $key) {
			$this->text($pdf, $x + $i * $sw, $y, $sw, $outputlangs->transnoentities($key).': ________');
		}
		$y += $this->lineHeight + 2;

		$pdf->SetFont($this->font, '', 7.5);
		$pdf->SetTextColor(90, 90, 90);
		$notes = array();
		if (!empty($object->allergy_override_reason)) {
			$notes[] = $outputlangs->transnoentities('PrescriptionAllergyReleased').'：'.$object->allergy_override_reason;
		}
		$notes[] = $outputlangs->transnoentities('PrescriptionRetentionNote', getDolGlobalInt('PRESCRIPTION_RETENTION_YEARS', 1));
		$this->text($pdf, $x, $y, $w - 32, implode("\n", $notes));
		$pdf->SetTextColor(0, 0, 0);
	}

	/**
	 * Diagonal watermark: 草稿 (grey) for drafts, 作废 (red) for voided.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			PrescriptionSheet
	 * @param	Translate	$outputlangs	Lang
	 * @return	void
	 */
	protected function watermark($pdf, $object, $outputlangs)
	{
		$status = (int) $object->status;
		if ($status === 0) {
			$text = $outputlangs->transnoentities('PrescriptionStatusDraft');
			$pdf->SetTextColor(150, 150, 150);
		} elseif ($status === 9) {
			$text = $outputlangs->transnoentities('PrescriptionStatusVoided');
			$pdf->SetTextColor(200, 0, 0);
		} else {
			return;
		}
		$pdf->StartTransform();
		$cx = $this->page_largeur / 2;
		$cy = $this->page_hauteur / 2;
		$pdf->Rotate(35, $cx, $cy);
		$pdf->setAlpha(0.18);
		$pdf->SetFont($this->font, '', $this->page_largeur < 200 ? 48 : 70);
		$pdf->Text($cx - 40, $cy - 12, $text);
		$pdf->setAlpha(1);
		$pdf->StopTransform();
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont($this->font, '', $this->fontSize);
	}

	/**
	 * Dictionary label with fallback to the code.
	 *
	 * @param	string	$dict	decoct | route | freq | dose_unit
	 * @param	string	$code	Code
	 * @return	string
	 */
	protected function dictLabel($dict, $code)
	{
		$code = (string) $code;
		if ($code === '') {
			return '';
		}
		return isset($this->dicts[$dict][$code]) ? $this->dicts[$dict][$code] : $code;
	}

	/**
	 * Format a decimal without trailing zeros.
	 *
	 * @param	mixed	$n	Number
	 * @return	string
	 */
	protected function num($n)
	{
		if ($n === null || $n === '') {
			return '';
		}
		return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
	}
}
