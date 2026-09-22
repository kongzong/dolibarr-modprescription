<?php
/* Copyright (C) 2026 modPrescription contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/class/prescriptiondictimport.class.php';

/** Pure parsing tests: no database. */
class PrescriptionDictImportTest extends TestCase
{
	/** @var array<string,string> type => seed sample (code,label,pos) */
	private function sampleRows()
	{
		return array(
			'decoct' => array('HOUXIA', '后下', 20),
			'route' => array('PO', '口服', 10),
			'freq' => array('TID', '每日三次', 30),
			'dose_unit' => array('MG', 'mg', 10),
		);
	}

	public function testParseRowsAllFourTypes()
	{
		foreach ($this->sampleRows() as $type => $row) {
			$parsed = PrescriptionDictImport::parseRow(array($row[0], $row[1], $row[2]), $type);
			$this->assertSame($row[0], $parsed['code'], 'code for '.$type);
			$this->assertSame($row[1], $parsed['label'], 'label for '.$type);
			$this->assertSame((int) $row[2], $parsed['pos'], 'pos for '.$type);
		}
	}

	public function testParseRowColumnOrderFree()
	{
		// Label first, code second, pos third
		$parsed = PrescriptionDictImport::parseRow(array('后下', 'HOUXIA', '20'), 'decoct');
		$this->assertSame('HOUXIA', $parsed['code'], 'code found regardless of order');
		$this->assertSame('后下', $parsed['label']);
		$this->assertSame(20, $parsed['pos']);
	}

	public function testParseRowOmitsPosWhenAbsent()
	{
		$parsed = PrescriptionDictImport::parseRow(array('QD', '每日一次'), 'freq');
		$this->assertSame('QD', $parsed['code']);
		$this->assertSame('每日一次', $parsed['label']);
		$this->assertSame(null, $parsed['pos']);
	}

	public function testParseRowSkipsHeaderAndEmpty()
	{
		$this->assertSame(null, PrescriptionDictImport::parseRow(array('编码', '名称'), 'decoct'), 'chinese header has no code');
		$this->assertSame(null, PrescriptionDictImport::parseRow(array('', ''), 'route'), 'empty row skipped');
		$this->assertSame(null, PrescriptionDictImport::parseRow(array('TID'), 'freq'), 'code without label skipped');
	}

	public function testParseRowSkipsPureNumericAsCode()
	{
		// A pure-numeric token is treated as pos, not code, so "1,先煎" has no code -> skip
		$this->assertSame(null, PrescriptionDictImport::parseRow(array('1', '先煎'), 'decoct'));
	}

	public function testCodeLengthCappedAt16()
	{
		$parsed = PrescriptionDictImport::parseRow(array('ABCDEFGHIJKLMNOP', '名'), 'decoct');
		$this->assertSame('ABCDEFGHIJKLMNOP', $parsed['code'], '16-char code accepted');
		// 17 chars exceeds the column width: skipped
		$this->assertSame(null, PrescriptionDictImport::parseRow(array('ABCDEFGHIJKLMNOPQ', '名'), 'decoct'));
	}

	public function testUtf8AndGbkDecoding()
	{
		$this->assertSame('a,b', PrescriptionDictImport::toUtf8("\xEF\xBB\xBFa,b"), 'BOM stripped');
		$gbk = mb_convert_encoding('后下', 'GB18030', 'UTF-8');
		$this->assertSame('后下', PrescriptionDictImport::toUtf8($gbk), 'GBK converted');
		$this->assertSame('后下', PrescriptionDictImport::toUtf8('后下'), 'UTF-8 untouched');
	}

	public function testTablesMapToModuleTables()
	{
		$this->assertSame(array(
			'decoct' => 'c_prescription_decoct',
			'route' => 'c_prescription_route',
			'freq' => 'c_prescription_freq',
			'dose_unit' => 'c_prescription_dose_unit',
		), PrescriptionDictImport::TABLES);
	}

	public function testImporterIsUpsertOnly()
	{
		$src = file_get_contents(__DIR__.'/../../class/prescriptiondictimport.class.php');
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $src, 'idempotent on the unique code');
		$this->assertStringNotContainsString('DELETE', $src, 'import never deletes dictionary rows');
		$this->assertStringContainsString('active = 1', $src, 're-import re-enables');
	}
}
