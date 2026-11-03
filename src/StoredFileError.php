<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\File;
use RuntimeException;
use Throwable;

/**
 * A file Kirby stored although the call that stored it threw: a Kirby
 * `file.create:after` hook runs after the file and its content file are on
 * disk and before createFile() returns, so the caller never gets the File.
 * This carries it, with the original failure, so that the draft's undo can
 * remove it (Service::rollback() unwraps it).
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class StoredFileError extends RuntimeException
{
	/**
	 * @param $stored the file that is on disk
	 * @param $failure what threw after Kirby stored it
	 */
	public function __construct(public readonly File $stored, public readonly Throwable $failure)
	{
		parent::__construct($failure->getMessage(), 0, $failure);
	}
}
