<?php
/* Copyright (C) 2026 modPrescription contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/class/prescriptionnumbering.class.php';

/** Scripted database: no concurrency here (tests/integration/numbering.php does that). */
class PrescriptionNumberingTestDb
{
	public $type = 'mysqli';
	public $transaction_opened;
	public $queries = array();
	public $responses;
	public $rollbacks = 0;
	public $rollbackFails = false;
	public $closed = false;

	public function __construct($depth, $responses)
	{
		$this->transaction_opened = $depth;
		$this->responses = $responses;
	}
	public function prefix() { return 'isolated_'; }
	public function escape($value) { return addslashes($value); }
	public function query($sql)
	{
		$this->queries[] = $sql;
		if (!$this->responses) {
			throw new LogicException('Unexpected extra query');
		}
		$response = array_shift($this->responses);
		if ($response instanceof Throwable) {
			throw $response;
		}
		return is_array($response) ? (object) array('rows' => $response) : $response;
	}
	public function fetch_object($result) { return $result->rows ? (object) $result->rows[0] : false; }
	public function num_rows($result) { return count($result->rows); }
	public function free($result) {}
	public function rollback()
	{
		$this->rollbacks++;
		$this->transaction_opened--;
		return !$this->rollbackFails;
	}
	public function close() { $this->closed = true; $this->transaction_opened = 0; }
}

class PrescriptionNumberingTest extends TestCase
{
	private function database($depth, $counter, $history)
	{
		$responses = array(
			$counter === null ? array() : array(array('last_value' => $counter)),
			array(array('maxseq' => $history)),
		);
		if ($depth > 0) {
			array_unshift($responses, true);
			$responses[] = true;
		}
		return new PrescriptionNumberingTestDb($depth, $responses);
	}

	private function mustFail($db, $prefix = 'CF-20260920-')
	{
		$thrown = null;
		try {
			(new PrescriptionNumbering($db))->nextReference($prefix);
		} catch (RuntimeException $error) {
			$thrown = $error;
		}
		$this->assertTrue($thrown instanceof RuntimeException, 'failure must not return a reference');
		return $thrown;
	}

	public function testPrefixForDay()
	{
		$this->assertSame('CF-20260920-', PrescriptionNumbering::prefixFor(mktime(12, 0, 0, 9, 20, 2026)));
	}

	public function testPreviewDoesNotReserve()
	{
		$db = $this->database(0, null, null);
		$this->assertSame('CF-20260920-001', (new PrescriptionNumbering($db))->nextReference('CF-20260920-'));
		$this->assertCount(2, $db->queries);
		foreach ($db->queries as $sql) {
			$this->assertStringNotContainsString('FOR UPDATE', $sql);
		}
	}

	public function testHistoryAndCounterSeedSequence()
	{
		$db = $this->database(1, '0', '41');
		$this->assertSame('CF-20260920-042', (new PrescriptionNumbering($db))->nextReference('CF-20260920-'));
		$this->assertStringContainsString('isolated_prescription WHERE ref LIKE', $db->queries[2]);
		$db = $this->database(1, '57', '41');
		$this->assertSame('CF-20260920-058', (new PrescriptionNumbering($db))->nextReference('CF-20260920-'));
	}

	public function testReservationKeepsOuterTransactionAndLocks()
	{
		$db = $this->database(3, '8', '8');
		$this->assertSame('CF-20260920-009', (new PrescriptionNumbering($db))->nextReference('CF-20260920-'));
		$this->assertSame(3, $db->transaction_opened);
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $db->queries[0]);
		$this->assertStringContainsString('FOR UPDATE', $db->queries[1]);
		$this->assertStringContainsString('FOR UPDATE', $db->queries[2]);
	}

	public function testEverySqlFailureRollsBackAllLevels()
	{
		for ($stage = 0; $stage < 4; $stage++) {
			$db = $this->database(3, '0', null);
			$db->responses[$stage] = false;
			$this->mustFail($db);
			$this->assertSame(0, $db->transaction_opened);
			$this->assertSame(3, $db->rollbacks);
		}
	}

	public function testInvalidSequenceAndContextFailClosed()
	{
		foreach (array('-1', 'invalid', (string) PHP_INT_MAX) as $value) {
			$db = $this->database(1, '0', $value);
			$this->mustFail($db);
		}
		$db = $this->database(0, null, null);
		$db->type = 'pgsql';
		$this->mustFail($db);
		$this->assertCount(0, $db->queries);
		$db = $this->database(0, null, null);
		$this->mustFail($db, 'JZ-20260920-');
		$this->assertCount(0, $db->queries);
	}
}
