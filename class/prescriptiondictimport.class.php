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
 * \file    htdocs/custom/prescription/class/prescriptiondictimport.class.php
 * \ingroup prescription
 * \brief   CSV import into the four module dictionaries (decoction method,
 *          route, frequency, dose unit). Idempotent upsert on the unique code
 *          (spec §3.1 / §8); column shape "code,label[,pos]" with any order,
 *          same convention as modMedRecord's dictionary import. UTF-8
 *          (with/without BOM) and GBK input accepted. Never deletes rows.
 */
class PrescriptionDictImport
{
	/** Dictionary type => table without prefix */
	const TABLES = array(
		'decoct' => 'c_prescription_decoct',
		'route' => 'c_prescription_route',
		'freq' => 'c_prescription_freq',
		'dose_unit' => 'c_prescription_dose_unit',
	);

	/** Module / local code shape for these four tables: HOUXIA, TID, MG, PO …
	 * (16 chars max, matching the NOT NULL 16-char code column) */
	const DICT_CODE = '/^[A-Za-z0-9][A-Za-z0-9._\-\/]{0,15}$/';

	/** @var DoliDB */
	private $db;
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Interpret one CSV row. Pure function (no DB), unit-tested.
	 *
	 * @param	string[]	$cells	Raw cells
	 * @param	string		$type		decoct | route | freq | dose_unit
	 * @return	array{code:string,label:string,pos:?int}|null	null = skip (header, empty, unparseable)
	 */
	public static function parseRow(array $cells, $type)
	{
		$cells = array_map(function ($c) {
			return trim((string) $c);
		}, $cells);
		$cells = array_values(array_filter($cells, function ($c) {
			return $c !== '';
		}));
		if (count($cells) < 2) {
			return null;
		}

		$code = null;
		$label = null;
		$pos = null;
		foreach ($cells as $c) {
			if ($code === null && preg_match(self::DICT_CODE, $c) && !preg_match('/^[0-9]+$/', $c) && !preg_match('/[\x{4e00}-\x{9fff}]/u', $c)) {
				$code = $c;
			} elseif ($pos === null && preg_match('/^[0-9]{1,5}$/', $c)) {
				$pos = (int) $c;
			} elseif ($label === null) {
				$label = $c;
			}
		}
		if ($code === null || $label === null) {
			return null;
		}
		return array('code' => mb_substr($code, 0, 16), 'label' => mb_substr($label, 0, 64), 'pos' => $pos);
	}

	/**
	 * Decode file content to UTF-8 (BOM stripped, GBK converted).
	 *
	 * @param	string	$raw	File bytes
	 * @return	string			UTF-8 text
	 */
	public static function toUtf8($raw)
	{
		if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
			$raw = substr($raw, 3);
		}
		if (mb_check_encoding($raw, 'UTF-8')) {
			return $raw;
		}
		$converted = @mb_convert_encoding($raw, 'UTF-8', 'GB18030');
		return $converted !== false ? $converted : $raw;
	}

	/**
	 * Import a CSV file into a dictionary. Idempotent upsert on code.
	 *
	 * @param	string	$path	Local file path (already validated as an upload)
	 * @param	string	$type		decoct | route | freq | dose_unit
	 * @return	array{read:int,inserted:int,updated:int,skipped:int}|null	null on error (this->error)
	 */
	public function importFile($path, $type)
	{
		if (!isset(self::TABLES[$type])) {
			$this->error = 'PrescriptionImportBadType';
			return null;
		}
		$raw = @file_get_contents($path);
		if ($raw === false) {
			$this->error = 'PrescriptionImportReadFailed';
			return null;
		}
		$text = self::toUtf8($raw);
		$lines = preg_split('/\r\n|\r|\n/', $text);
		$rows = array();
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			$parsed = self::parseRow(str_getcsv($line), $type);
			if ($parsed !== null) {
				$rows[$parsed['code']] = $parsed; // last occurrence wins, one row per code
			}
		}
		return $this->upsertRows($rows, $type, count($lines));
	}

	/**
	 * Upsert parsed rows in batches. rowid has no auto increment (dict.php
	 * convention) so ids are allocated from MAX(rowid) in PHP.
	 *
	 * @param	array<string,array>	$rows	code => parsed row
	 * @param	string				$type	Dictionary type
	 * @param	int					$read	Lines read (for stats)
	 * @return	array{read:int,inserted:int,updated:int,skipped:int}|null
	 */
	public function upsertRows(array $rows, $type, $read = 0)
	{
		$table = $this->db->prefix().self::TABLES[$type];

		$resql = $this->db->query("SELECT MAX(rowid) as m FROM ".$table);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$nextId = (int) $this->db->fetch_object($resql)->m + 1;
		$this->db->free($resql);

		$existing = array();
		$resql = $this->db->query("SELECT code FROM ".$table);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$existing[$o->code] = true;
			}
			$this->db->free($resql);
		}

		$inserted = 0;
		$updated = 0;
		$batch = 0;
		$this->db->begin();
		foreach ($rows as $code => $r) {
			$code = $this->db->escape($r['code']);
			$label = $this->db->escape($r['label']);
			$pos = $r['pos'] !== null ? (int) $r['pos'] : 0;
			$sql = "INSERT INTO ".$table." (rowid, pos, code, label, active) VALUES (".$nextId.", ".$pos.", '".$code."', '".$label."', 1)";
			$sql .= " ON DUPLICATE KEY UPDATE label = VALUES(label), ".($r['pos'] !== null ? "pos = VALUES(pos), " : "")."active = 1";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return null;
			}
			if (isset($existing[$r['code']])) {
				$updated++;
			} else {
				$inserted++;
				$existing[$r['code']] = true;
				$nextId++;
			}
			if (++$batch >= 500) {
				$this->db->commit();
				$this->db->begin();
				$batch = 0;
			}
		}
		$this->db->commit();
		return array('read' => (int) $read, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => max(0, (int) $read - count($rows)));
	}
}
