<?php

/**
 * Class map for Kirby's F::loadClasses(). Keys are lowercase class names,
 * values are paths relative to the plugin root. tests/AutoloadTest.php checks
 * the map against src/.
 */

return [
	'sayamaapps\\fude\\blueprints' => 'src/Blueprints.php',
	'sayamaapps\\fude\\config' => 'src/Config.php',
	'sayamaapps\\fude\\content' => 'src/Content.php',
	'sayamaapps\\fude\\drafts' => 'src/Drafts.php',
	'sayamaapps\\fude\\fudeerror' => 'src/FudeError.php',
	'sayamaapps\\fude\\i18n' => 'src/I18n.php',
	'sayamaapps\\fude\\images' => 'src/Images.php',
	'sayamaapps\\fude\\log' => 'src/Log.php',
	'sayamaapps\\fude\\options' => 'src/Options.php',
	'sayamaapps\\fude\\service' => 'src/Service.php',
	'sayamaapps\\fude\\validator' => 'src/Validator.php',
];
