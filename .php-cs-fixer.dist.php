<?php

$config = Kirby\PhpCs\Config::create();

// Class DocBlocks keep `@package`, which the Kirby style would remove. The
// override comes after the set, because a set would otherwise win.
$rules = $config->getRules();
$rules['phpdoc_no_package'] = false;

return $config
	->setRules($rules)
	->setFinder(
		PhpCsFixer\Finder::create()
			->exclude('vendor')
			->in(__DIR__)
	);
