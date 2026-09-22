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
 * \file    htdocs/custom/prescription/core/modules/prescription/doc/pdf_cf_tcm.modules.php
 * \ingroup prescription
 * \brief   TCM decoction sheet (饮片方): herbs laid out three per row
 *          "药名 12g（后下）", then doses, decoction mode and usage text.
 */

dol_include_once('/prescription/core/modules/prescription/modules_prescription.php');

/**
 * Class pdf_cf_tcm
 */
class pdf_cf_tcm extends ModelePDFPrescription
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->name = 'cf_tcm';
		$this->description = 'TCM decoction prescription sheet (中药饮片处方笺)';
	}

	/**
	 * 正文: Rp. + herb grid + doses/usage.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			PrescriptionSheet
	 * @param	Translate	$outputlangs	Lang
	 * @param	float		$y				Start Y
	 * @return	float
	 */
	protected function drawBody($pdf, $object, $outputlangs, $y)
	{
		$x = $this->marge_gauche;
		$w = $this->contentWidth();
		$cols = ($this->page_largeur < 200) ? 2 : 3;
		$cw = $w / $cols;

		$pdf->SetFont($this->font, '', $this->fontSize + 2);
		$y = $this->text($pdf, $x, $y, $w, 'Rp.') + 1;
		$pdf->SetFont($this->font, '', $this->fontSize + 1);

		$cells = array();
		foreach ((array) $object->lines as $l) {
			$s = (!empty($l['allergy_hit']) ? '※' : '').$l['label'];
			if ($l['qty'] !== null && $l['qty'] !== '') {
				$s .= ' '.$this->num($l['qty']).($l['qty_unit'] !== null && $l['qty_unit'] !== '' ? $l['qty_unit'] : 'g');
			}
			$decoct = $this->dictLabel('decoct', $l['decoct_code']);
			if ($decoct !== '') {
				$s .= '（'.$decoct.'）';
			}
			$cells[] = $s;
		}

		$rows = array_chunk($cells, $cols);
		foreach ($rows as $row) {
			$h = 0;
			foreach ($row as $c) {
				$h = max($h, $pdf->getStringHeight($cw, $c, true));
			}
			if ($y + $h > $this->bodyBottom()) {
				$y = $this->newPage($pdf, false);
				$pdf->SetFont($this->font, '', $this->fontSize + 1);
			}
			foreach ($row as $i => $c) {
				$pdf->MultiCell($cw, $h, $c, 0, 'L', false, 1, $x + $i * $cw, $y, true);
			}
			$y += $h;
		}
		if (empty($rows)) {
			$y = $this->text($pdf, $x, $y, $w, '—');
		}

		$y += 3;
		if ($y + 3 * $this->lineHeight > $this->bodyBottom()) {
			$y = $this->newPage($pdf, false);
		}
		$pdf->SetFont($this->font, '', $this->fontSize);
		$modes = array('SELF' => 'PrescriptionDecoctSelf', 'CLINIC' => 'PrescriptionDecoctClinic', 'GRANULE' => 'PrescriptionDecoctGranule');
		$parts = array();
		$parts[] = $outputlangs->transnoentities('PrescriptionTotalDoses', (int) $object->doses);
		if (!empty($object->decoct_mode) && isset($modes[$object->decoct_mode])) {
			$parts[] = $outputlangs->transnoentities($modes[$object->decoct_mode]);
		}
		$y = $this->text($pdf, $x, $y, $w, implode('    ', $parts));
		if (!empty($object->usage_note)) {
			$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('PrescriptionUsageShort').': '.$object->usage_note);
		}
		if (!empty($object->note)) {
			$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('PrescriptionNote').': '.$object->note);
		}
		return $y;
	}
}
