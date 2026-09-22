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
 * \file    htdocs/custom/prescription/core/modules/prescription/doc/pdf_cf_wm.modules.php
 * \ingroup prescription
 * \brief   Western / patent medicine sheet: numbered table
 *          序号 | 药品名称 | 数量 | 用法用量 | 备注, measured rows, paginated
 *          with the header repeated.
 */

dol_include_once('/prescription/core/modules/prescription/modules_prescription.php');

/**
 * Class pdf_cf_wm
 */
class pdf_cf_wm extends ModelePDFPrescription
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->name = 'cf_wm';
		$this->description = 'Western medicine prescription sheet (西药处方笺)';
	}

	/**
	 * Column widths as fractions of the content width.
	 *
	 * @return	array<string,float>
	 */
	protected function columns()
	{
		$w = $this->contentWidth();
		return array('no' => 0.06 * $w, 'drug' => 0.34 * $w, 'qty' => 0.14 * $w, 'sig' => 0.32 * $w, 'note' => 0.14 * $w);
	}

	/**
	 * @param	TCPDF	$pdf	PDF
	 * @param	float	$y		Y
	 * @param	array	$texts	Cell texts keyed by column
	 * @param	bool	$header	Header row
	 * @return	float			Row height
	 */
	protected function drawRow($pdf, $y, array $texts, $header = false)
	{
		$cols = $this->columns();
		$h = 0;
		foreach ($cols as $key => $cw) {
			$h = max($h, $pdf->getStringHeight($cw, isset($texts[$key]) ? $texts[$key] : '', true));
		}
		$x = $this->marge_gauche;
		foreach ($cols as $key => $cw) {
			$align = in_array($key, array('no', 'qty'), true) || $header ? 'C' : 'L';
			$pdf->MultiCell($cw, $h, isset($texts[$key]) ? $texts[$key] : '', 1, $align, false, 1, $x, $y, true, 0, false, true, $h, 'M');
			$x += $cw;
		}
		return $h;
	}

	/**
	 * 正文: Rp. + table.
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

		$pdf->SetFont($this->font, '', $this->fontSize + 2);
		$y = $this->text($pdf, $x, $y, $w, 'Rp.') + 1;
		$pdf->SetFont($this->font, '', $this->fontSize);

		$header = array(
			'no' => $outputlangs->transnoentities('PrescriptionColNo'),
			'drug' => $outputlangs->transnoentities('PrescriptionColDrug'),
			'qty' => $outputlangs->transnoentities('PrescriptionColQty'),
			'sig' => $outputlangs->transnoentities('PrescriptionColSig'),
			'note' => $outputlangs->transnoentities('PrescriptionColNote'),
		);
		$y += $this->drawRow($pdf, $y, $header, true);

		$hasHit = false;
		foreach ((array) $object->lines as $i => $l) {
			$sig = array();
			if ($l['dose'] !== null && $l['dose'] !== '') {
				$sig[] = $outputlangs->transnoentities('PrescriptionSigEach').' '.$this->num($l['dose']).$this->dictLabel('dose_unit', $l['dose_unit']);
			}
			$route = $this->dictLabel('route', $l['route_code']);
			if ($route !== '') {
				$sig[] = $route;
			}
			$freq = $this->dictLabel('freq', $l['freq_code']);
			if ($freq !== '') {
				$sig[] = $freq;
			}
			if ($l['days'] !== null && $l['days'] !== '') {
				$sig[] = $outputlangs->transnoentities('PrescriptionSigDays', (int) $l['days']);
			}
			if (!empty($l['sig_note'])) {
				$sig[] = $l['sig_note'];
			}
			$hit = !empty($l['allergy_hit']);
			$hasHit = $hasHit || $hit;
			$texts = array(
				'no' => (string) ($i + 1),
				'drug' => ($hit ? '※' : '').$l['label'].(!empty($l['product_ref']) ? "\n".$l['product_ref'] : ''),
				'qty' => $this->num($l['qty']).(($l['qty'] !== null && $l['qty'] !== '') ? ' '.(string) $l['qty_unit'] : ''),
				'sig' => implode('，', $sig),
				'note' => $hit ? $outputlangs->transnoentities('PrescriptionAllergyHitShort') : '',
			);
			$cols = $this->columns();
			$h = 0;
			foreach ($cols as $key => $cw) {
				$h = max($h, $pdf->getStringHeight($cw, $texts[$key], true));
			}
			if ($y + $h > $this->bodyBottom()) {
				$y = $this->newPage($pdf, false);
				$pdf->SetFont($this->font, '', $this->fontSize);
				$y += $this->drawRow($pdf, $y, $header, true);
			}
			$y += $this->drawRow($pdf, $y, $texts);
		}
		if (empty($object->lines)) {
			$y += $this->drawRow($pdf, $y, array('no' => '', 'drug' => '—', 'qty' => '', 'sig' => '', 'note' => ''));
		}

		$y += 3;
		if (!empty($object->usage_note) || !empty($object->note)) {
			if ($y + 2 * $this->lineHeight > $this->bodyBottom()) {
				$y = $this->newPage($pdf, false);
			}
			if (!empty($object->usage_note)) {
				$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('PrescriptionUsageShort').': '.$object->usage_note);
			}
			if (!empty($object->note)) {
				$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('PrescriptionNote').': '.$object->note);
			}
		}
		return $y;
	}
}
