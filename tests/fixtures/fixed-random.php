<?php

namespace Sayamaapps\Fude;

/**
 * Test seam: the plugin's own `random_bytes()` calls (a new page's slug
 * suffix, a filename, a draft id) go through here, because a function of the
 * same name in the namespace wins over the global one. A test that sets
 * $GLOBALS['fudeRandom'][<length>] to a string of that length fixes the bytes
 * of every call of that length; any other call stays random. Require this
 * file before the first request of the test process.
 */
function random_bytes(int $length): string
{
	$fixed = $GLOBALS['fudeRandom'][$length] ?? null;
	return is_string($fixed) && strlen($fixed) === $length ? $fixed : \random_bytes($length);
}
