<?php

use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\FudeError;
use Sayamaapps\Fude\I18n;
use Sayamaapps\Fude\Service;
use Sayamaapps\Fude\WriteLock;

/**
 * One Fude write at a time per site: WriteLock::run() holds a lock file in
 * storage/fude/ while the callback runs, waits for another holder and then
 * answers 503, and lets go however the callback ends. Two drafts for the same
 * page at the same moment (two PHP processes) are handled one after the
 * other: the second one finds the first one's changes and is refused (423)
 * instead of saving over them, and the first one's token publishes the first
 * one's content.
 */
#[CoversClass(WriteLock::class)]
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class WriteLockTest extends TestCase
{
	public function testStorageThatCannotTakeALockFileIsAnInternalError(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			// A file where the folder belongs.
			file_put_contents($tmp . '/storage/fude', 'in the way');
			throws(fn () => WriteLock::run($app(), fn () => 'never'), 'Cannot create ' . $tmp . '/storage/fude', 'No folder, no lock', RuntimeException::class);
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Radieschen', 'text' => 'Rot.']]);
			same([500, 'Internal error, nothing changed.'], [$code, $json['error'] ?? null], 'A draft answers 500 before it touches the page');
			same(false, $app()->page('home')->version('changes')->exists(), 'Nothing was stored');
		} finally {
			cleanup($tmp);
		}
	}

	public function testTwoDraftsAtOnceAreHandledOneAfterTheOther(): void
	{
		[$tmp, $app] = fude_fixture(seam: true);
		$marks = $tmp . '/marks';
		mkdir($marks);
		$children = [];
		try {
			$spawn = function (string $role, string $title) use ($tmp, $marks, &$children): array {
				$body = json_encode(['collection' => 'aktuelles', 'action' => 'create', 'fields' => ['titel' => $title, 'text' => 'Text von ' . $title]]);
				$process = proc_open(
					[PHP_BINARY, __DIR__ . '/draft-process.php', $tmp, $marks, $role, 'draft', $body],
					[1 => ['pipe', 'w'], 2 => ['file', $marks . '/' . $role . '.err', 'w']],
					$pipes
				);
				ok(is_resource($process), 'Process "' . $role . '" started');
				return $children[] = [$process, $pipes[1]];
			};
			$waitFor = function (string $marker, array $child) use ($marks): void {
				$deadline = microtime(true) + 30;
				while (!is_file($marks . '/' . $marker) && proc_get_status($child[0])['running'] && microtime(true) < $deadline) {
					usleep(20000);
				}
				ok(is_file($marks . '/' . $marker), 'Marker ' . $marker . ' was set', glob($marks . '/*.err') ? array_map('file_get_contents', glob($marks . '/*.err')) : null);
			};
			$result = function (array $child): array {
				$out = stream_get_contents($child[1]);
				fclose($child[1]);
				proc_close($child[0]);
				$decoded = json_decode((string)$out, true);
				ok(is_array($decoded), 'The process printed its reply', $out);
				return $decoded;
			};

			// A has passed the checks and is about to write its changes version.
			$a = $spawn('hold', 'Proposal Alpha');
			$waitFor('hold-held', $a);
			// B starts now. It must wait for A's lock, not run past A.
			$b = $spawn('plain', 'Proposal Beta');
			$waitFor('plain-started', $b);
			usleep(1500000);
			same(true, proc_get_status($b[0])['running'], 'B is still waiting for the lock while A is in the middle of its write');
			same([], glob($tmp . '/storage/fude/drafts/*.json') ?: [], 'and has stored nothing');
			touch($marks . '/go');

			[$codeA, $jsonA] = $result($a);
			[$codeB, $jsonB] = $result($b);
			same(200, $codeA, 'A is saved', $jsonA);
			same([423, 'The page has unsaved Panel changes. Save or discard them in the Panel first.'], [$codeB, $jsonB['error'] ?? null], 'B waited, found A\'s changes and is refused');
			same(1, count(glob($tmp . '/storage/fude/drafts/*.json') ?: []), 'Only A has a record');

			// A's id and token publish what A's reply promised.
			[$code, $reply] = fude_call($app, 'publish', ['id' => $jsonA['draft']['id'], 'token' => $jsonA['token']]);
			same(200, $code, 'A publishes', $reply);
			$rows = Data::decode($app()->page('home')->version('latest')->read()['meldungen'], 'yaml');
			same(['Proposal Alpha'], array_column($rows, 'titel'), 'The published entry is A\'s');
			same('Text von Proposal Alpha', $rows[0]['text'], 'with A\'s text');
		} finally {
			touch($marks . '/go');
			foreach ($children as [$process, $pipe]) {
				if (is_resource($process)) {
					fclose($pipe);
					proc_terminate($process);
					proc_close($process);
				}
			}
			cleanup($tmp);
		}
	}

	public function testWaitsForTheLockAndAnswers503(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$lock = $tmp . '/storage/fude/write.lock';
			same('first', WriteLock::run($kirby, fn () => 'first'), 'Runs the callback and returns its value; creates storage/fude');
			same(true, is_file($lock), 'The lock file is there');

			$other = fopen($lock, 'c');
			same(true, flock($other, LOCK_EX), 'The test holds the lock with a handle of its own');
			$ran = false;
			$start = microtime(true);
			try {
				WriteLock::run($kirby, function () use (&$ran): void {
					$ran = true;
				}, wait: 0.3);
				$this->fail('The lock was taken twice');
			} catch (FudeError $e) {
				same([503, I18n::t('draft.busy')], [$e->status, $e->getMessage()], 'Busy: 503 with the translated text');
			}
			same(false, $ran, 'The callback did not run');
			ok(microtime(true) - $start >= 0.3, 'It waited for the time it was given');
			ok(microtime(true) - $start < 5, 'and not much longer');
			same('Another change is being saved right now. Try again in a moment.', $e->getMessage(), 'English text');

			// Reading is not held up.
			[$code] = fude_call($app, 'schema');
			same(200, $code, 'The schema route does not wait for the lock');

			// A holder that lets go is followed at once.
			$start = microtime(true);
			flock($other, LOCK_UN);
			same('second', WriteLock::run($kirby, fn () => 'second', wait: 0.3), 'Runs after the other holder let go');
			ok(microtime(true) - $start < 0.3, 'without waiting');
			fclose($other);

			// A callback that throws lets go, too, and its exception comes through.
			try {
				WriteLock::run($kirby, function (): void {
					throw new LogicException('callback failed');
				});
				$this->fail('The exception was swallowed');
			} catch (LogicException $e) {
				same('callback failed', $e->getMessage(), 'The callback\'s exception comes through');
			}
			same('third', WriteLock::run($kirby, fn () => 'third', wait: 0.3), 'The lock was released after the exception');
		} finally {
			cleanup($tmp);
		}
	}
}
