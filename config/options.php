<?php

// Options Fude reads, relative to the plugin: `sayamaapps.fude.<key>`.
// Each value is null on purpose. Options::get() gives the default at every read,
// and a default here would hide the 1.x spelling `fude.<key>` (fallback).
return [
	'active'         => null,
	'blueprints'     => null,
	'config'         => null,
	'draftLifetime'  => null,
	'language'       => null,
	'maxCollections' => null,
	'maxPixels'      => null,
	'maxUploadBytes' => null,
	'roles'          => null,
	'siteId'         => null,
	'timezone'       => null,
	'trustedProxies' => null,
	'withoutHttps'   => null,
];
