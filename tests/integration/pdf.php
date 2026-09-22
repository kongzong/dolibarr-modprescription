<?php
/* Copyright (C) 2026 modPrescription contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Render the two prescription sheet layouts with real TCPDF, isolated from
 * the database (stub object, in-memory company). Writes fixtures to
 * temp/pdf-acceptance/ and checks page counts / text presence.
 * Run: php tests/integration/pdf.php
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		return false;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});
date_default_timezone_set('Asia/Shanghai');
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_DOCUMENT_ROOT') ?: dirname(__DIR__, 4));
define('DOL_URL_ROOT', '');
define('DOL_MAIN_URL_ROOT', 'http://localhost');
define('DOL_VERSION', '22.0.4');
define('DOL_DATA_ROOT', dirname(__DIR__, 2).'/temp/pdf-runtime');
define('TCPDF_PATH', DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/');
define('TCPDI_PATH', DOL_DOCUMENT_ROOT.'/includes/tcpdi/');
define('MAIN_DB_PREFIX', 'llx_');

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/conf.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';

$conf = new Conf();
$conf->entity = 1;
$conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT, 'custom' => dirname(__DIR__, 3));
$conf->file->dol_url_root = array('main' => '', 'custom' => '/custom');
$conf->global = (object) array(
	'MAIN_PDF_FORMAT' => 'A4', 'MAIN_DISABLE_TCPDI' => 1, 'TCPDF_THROW_ERRORS_INSTEAD_OF_DIE' => 1,
	'PRESCRIPTION_PDF_FORMAT' => getenv('PRESCRIPTION_PDF_TEST_FORMAT') ?: 'A4', 'PRESCRIPTION_RETENTION_YEARS' => 1,
);
$outputDir = getenv('PRESCRIPTION_PDF_OUTPUT') ?: dirname(__DIR__, 2).'/temp/pdf-acceptance';
$conf->prescription = (object) array('dir_output' => $outputDir, 'enabled' => 1);
if (dol_mkdir($outputDir) < 0) {
	throw new RuntimeException('Cannot create PDF fixture output directory');
}

// dol_include_once() in the templates resolves through $conf->file->dol_document_root; no DB needed
$langs = new Translate('', $conf);
$langs->dir[] = dirname(__DIR__, 2);
$langs->dir[] = dirname(__DIR__, 3).'/patient';
$langs->setDefaultLang('zh_CN');
$langs->load('main', 0, 0, '', 1);
$langs->load('companies', 0, 0, '', 1);
$langs->load('patient', 0, 0, '', 1);
$langs->load('prescription', 0, 0, '', 1);

$mysoc = (object) array('name' => '示例中医馆（测试）', 'country_code' => 'CN', 'address' => '', 'phone' => '');
$user = (object) array('id' => 1, 'login' => 'test');
$db = null;

require_once dirname(__DIR__, 2).'/core/modules/prescription/doc/pdf_cf_tcm.modules.php';
require_once dirname(__DIR__, 2).'/core/modules/prescription/doc/pdf_cf_wm.modules.php';

$dicts = array(
	'decoct' => array('XIANJIAN' => '先煎', 'HOUXIA' => '后下', 'BAOJIAN' => '包煎', 'YANGHUA' => '烊化'),
	'route' => array('PO' => '口服', 'EXT' => '外用'),
	'freq' => array('TID' => '每日三次', 'BID' => '每日两次', 'QN' => '每晚一次'),
	'dose_unit' => array('MG' => 'mg', 'PIAN' => '片', 'ML' => 'ml'),
);
$patient = array('name' => '张三', 'gender_label' => '男', 'age' => 45, 'card_no' => 'HZ-202609-0001');

function fixture($type, $ref, $status, $lines, $extra = array())
{
	global $dicts, $patient;
	$o = (object) array_merge(array(
		'id' => 1, 'ref' => $ref, 'presc_type' => $type, 'status' => $status, 'fk_patient' => 1, 'fk_doctor' => 1,
		'date_presc' => strtotime('2026-09-21 10:00:00'), 'date_issued' => $status >= 1 ? strtotime('2026-09-21 10:05:00') : null,
		'diagnosis_text' => '急性上呼吸道感染；感冒；风寒袭表证', 'doses' => 7, 'decoct_mode' => 'SELF',
		'usage_note' => '水煎服，每日一剂，分早晚两次温服', 'note' => '', 'allergy_override_reason' => null,
		'lines' => $lines, 'patient' => $patient, 'doctor_name' => '李医生', 'department_label' => '中医内科', 'dicts' => $dicts,
	), $extra);
	return $o;
}
function tcmLine($label, $g, $decoct = null, $hit = 0)
{
	return array('fk_product' => null, 'product_ref' => null, 'label' => $label, 'qty' => $g, 'qty_unit' => 'g', 'decoct_code' => $decoct,
		'dose' => null, 'dose_unit' => null, 'route_code' => null, 'freq_code' => null, 'days' => null, 'sig_note' => null, 'allergy_hit' => $hit);
}
function wmLine($label, $ref, $qty, $unit, $dose, $du, $route, $freq, $days, $sig = null, $hit = 0)
{
	return array('fk_product' => 1, 'product_ref' => $ref, 'label' => $label, 'qty' => $qty, 'qty_unit' => $unit, 'decoct_code' => null,
		'dose' => $dose, 'dose_unit' => $du, 'route_code' => $route, 'freq_code' => $freq, 'days' => $days, 'sig_note' => $sig, 'allergy_hit' => $hit);
}

$herbs = array('麻黄', '桂枝', '杏仁', '炙甘草', '生姜', '大枣', '白芍', '细辛', '干姜', '五味子', '半夏', '茯苓', '白术', '陈皮', '党参',
	'黄芪', '当归', '川芎', '熟地黄', '柴胡', '黄芩', '连翘', '金银花', '薄荷', '桔梗', '牛蒡子', '淡豆豉', '荆芥', '防风', '羌活');

$cases = array();
// 1. TCM issued, 12 herbs with decoction notes: single page
$lines = array();
foreach (array_slice($herbs, 0, 12) as $i => $h) {
	$lines[] = tcmLine($h, 6 + $i, $i === 0 ? 'XIANJIAN' : ($i === 3 ? 'HOUXIA' : null));
}
$cases[] = array('name' => 'tcm-issued', 'model' => 'pdf_cf_tcm', 'object' => fixture('TCM', 'CF-20260921-001', 1, $lines), 'pages' => 1, 'must' => array('处方笺', 'CF-20260921-001', '张三', '麻黄', '先煎', '共 7 剂', '李医生', '审核'), 'mustnot' => array('草稿', '作废'));
// 2. TCM draft, 30 herbs + long usage: pagination + draft watermark
$lines = array();
foreach ($herbs as $i => $h) {
	$lines[] = tcmLine($h, 10, $i % 5 === 0 ? 'BAOJIAN' : null);
}
$long = fixture('TCM', 'CF-20260921-002', 0, $lines, array('usage_note' => str_repeat('水煎服，每日一剂，分早晚两次温服。', 6), 'note' => str_repeat('忌生冷油腻。', 20)));
$cases[] = array('name' => 'tcm-draft-long', 'model' => 'pdf_cf_tcm', 'object' => $long, 'pages' => getenv('PRESCRIPTION_PDF_TEST_FORMAT') === 'A5' ? 2 : 1, 'pagesMin' => 1, 'must' => array('草稿', '羌活', '包煎'), 'mustnot' => array());
// 3. WM issued with an allergy-released line
$lines = array(
	wmLine('青霉素V钾片 0.236g×24', 'QMS-001', 24, '片', 2, 'PIAN', 'PO', 'TID', 4, null, 1),
	wmLine('布洛芬缓释胶囊 0.3g×20', 'BLF-001', 20, '粒', 1, 'PIAN', 'PO', 'BID', 5, '饭后服'),
	wmLine('复方甘草口服溶液 100ml', 'FFGC-001', 1, '瓶', 10, 'ML', 'PO', 'TID', 3),
);
$cases[] = array('name' => 'wm-issued-override', 'model' => 'pdf_cf_wm', 'object' => fixture('WM', 'CF-20260921-003', 1, $lines, array('allergy_override_reason' => '既往轻度皮疹，已脱敏，患者知情同意')), 'pages' => 1, 'must' => array('用法用量', '青霉素', '※', '每日三次', '过敏冲突已由医师确认放行', '每次', '布洛芬'), 'mustnot' => array('草稿'));
// 4. WM voided, 40 lines: pagination + voided watermark
$lines = array();
for ($i = 1; $i <= 40; $i++) {
	$lines[] = wmLine('测试药品'.$i.' 规格'.$i.'mg×'.(10 + $i), 'T-'.sprintf('%03d', $i), 10 + $i, '片', $i % 3 + 1, 'PIAN', 'PO', $i % 2 ? 'TID' : 'BID', 7);
}
$cases[] = array('name' => 'wm-voided-long', 'model' => 'pdf_cf_wm', 'object' => fixture('WM', 'CF-20260921-004', 9, $lines, array('void_reason' => '开错患者')), 'pagesMin' => 2, 'must' => array('作废', '测试药品40', '（续）'), 'mustnot' => array());

$passed = 0;
$failed = 0;
$manifest = array();
foreach ($cases as $c) {
	try {
		$modelClass = $c['model'];
		$gen = new $modelClass($db);
		$r = $gen->write_file($c['object'], $langs);
		if ($r <= 0) {
			throw new RuntimeException('write_file returned '.$r.': '.$gen->error);
		}
		$file = $gen->result['fullpath'];
		if (!is_file($file) || filesize($file) < 1000) {
			throw new RuntimeException('PDF missing or too small');
		}
		$raw = file_get_contents($file);
		// TCPDF writes page objects uncompressed: count them
		$pages = preg_match_all('#/Type\s*/Page(?!s)#', $raw);
		if (isset($c['pages']) && $pages !== $c['pages']) {
			throw new RuntimeException('expected '.$c['pages'].' page(s), got '.$pages);
		}
		if (isset($c['pagesMin']) && $pages < $c['pagesMin']) {
			throw new RuntimeException('expected at least '.$c['pagesMin'].' pages, got '.$pages);
		}
		// Text streams are compressed: check content through a decompressed copy
		$text = '';
		if (preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $raw, $m)) {
			foreach ($m[1] as $s) {
				$d = @gzuncompress($s);
				if ($d !== false) {
					$text .= $d;
				}
			}
		}
		// stsongstdlight (CID, UniGB-UCS2-H): TCPDF writes the text as raw UTF-16BE bytes inside
		// (...) string operands, escaping \ ( ) as in PDF string syntax. Match the same bytes.
		$pdfBytes = function ($s) {
			$b = mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
			return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $b);
		};
		foreach ($c['must'] as $needle) {
			if (strpos($text, $pdfBytes($needle)) === false && strpos($text, $needle) === false) {
				throw new RuntimeException('missing text: '.$needle);
			}
		}
		foreach ($c['mustnot'] as $needle) {
			if (strpos($text, $pdfBytes($needle)) !== false) {
				throw new RuntimeException('unexpected text: '.$needle);
			}
		}
		$manifest[] = array('name' => $c['name'], 'file' => $file, 'pages' => $pages);
		echo 'PASS  '.$c['name'].' ('.$pages.' page(s), '.basename($file).")\n";
		$passed++;
	} catch (Throwable $e) {
		echo 'FAIL  '.$c['name'].' - '.$e->getMessage()."\n";
		$failed++;
	}
}
file_put_contents($outputDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nResult: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
