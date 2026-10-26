<?php

// Options Fude reads, relative to the plugin: `sayamaapps.fude.<key>` (OPT-1).
// Each value is null on purpose. Options::get() gives the default at every read,
// and a default here would hide the 1.x spelling `fude.<key>` (fallback).
return [
	'active'         => null,
	'config'         => null,
	'roles'          => null,
	'withoutHttps'   => null,
	'trustedProxies' => null,
	'draftLifetime'  => null,
	'maxUploadBytes' => null,
	'maxPixels'      => null,
	'language'       => null,
	'blueprints'     => null,
	'maxCollections' => null,
	'siteId'         => null,
	'timezone'       => null,
];
