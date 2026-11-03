<?php

use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;

/**
 * One request to a Fude route in a process of its own, for the test that
 * needs two requests at the same time (tests/WriteLockTest.php). Not a test
 * itself.
 *
 *   php draft-process.php <fixture dir> <marker dir> <role> <route> <JSON body>
 *
 * Role "hold": creates <marker dir>/hold-held when the draft is about to write
 * its changes version, and waits there until the test creates
 * <marker dir>/go. Role "plain": creates <marker dir>/plain-started and sends
 * the request at once. Prints [status, reply] as JSON.
 */
require __DIR__ . '/bootstrap.php';

[, $tmp, $marks, $role, $route, $body] = $argv;

$options = [];
if ($role === 'hold') {
	$options['test.storage'] = function (string $when, string $operation, string $version, ModelWithContent $model) use ($marks): void {
		if ($when !== 'before' || $operation !== 'write' || $version !== 'changes' || !$model instanceof Page) {
			return;
		}
		touch($marks . '/hold-held');
		$deadline = microtime(true) + 20;
		while (!is_file($marks . '/go')) {
			if (microtime(true) > $deadline) {
				throw new RuntimeException('The test never released this request.');
			}
			usleep(20000);
		}
	};
} else {
	touch($marks . '/' . $role . '-started');
}

[$code, $reply] = fude_call(fude_app($tmp, fude_options()), $route, json_decode($body, true), options: $options);
echo json_encode([$code, $reply]);
