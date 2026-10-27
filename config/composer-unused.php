<?php

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// Requirements without PHP symbols in the code: the PHP version, the
// extensions for mb_* and ctype_*/filter_* calls (not seen by the tool) and the
// installer that places the plugin in site/plugins.
return static function (Configuration $configuration): Configuration {
	return $configuration
		->addNamedFilter(NamedFilter::fromString('php'))
		->addNamedFilter(NamedFilter::fromString('ext-mbstring'))
		->addNamedFilter(NamedFilter::fromString('ext-ctype'))
		->addNamedFilter(NamedFilter::fromString('ext-filter'))
		->addNamedFilter(NamedFilter::fromString('getkirby/composer-installer'));
};
