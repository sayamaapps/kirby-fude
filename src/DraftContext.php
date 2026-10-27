<?php

namespace Sayamaapps\Fude;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * What one draft request is about: the collection, its page and rows, the
 * checked fields and the time. Service::draft() builds it once and hands it
 * to the draft method of the collection's kind.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class DraftContext
{
	/** The list a replace sets (null for the other actions). */
	public array|null $entries = null;

	/** The entry the draft names; the caller's array, filled in by the draft methods. */
	public array $entry;
	public string|null $format = null;
	public string|null $imageField = null;

	/** The values a touch collection sets with the draft. */
	public array $touched = [];

	/** The uploaded image, its checked format and the image field (null without an image). */
	public array|null $upload = null;

	/**
	 * @param list<array<string, mixed>> $rows the published rows
	 * @param array<string, mixed> $clean the checked fields
	 * @param array<string, mixed> $entry the caller's entry, shared by reference
	 */
	public function __construct(
		public readonly App $kirby,
		public readonly array $collection,
		public readonly Page $page,
		public readonly array $rows,
		public readonly int|null $index,
		public readonly array $clean,
		public readonly string $action,
		public readonly DateTimeImmutable $now,
		array &$entry
	) {
		$this->entry = &$entry;
	}
}
