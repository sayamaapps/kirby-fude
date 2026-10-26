<?php

namespace Fude;

/**
 * An error the caller can act on. The message is already translated
 * (I18n::t()), the status becomes the HTTP status, `data` is merged into the
 * JSON body (for example the current hash on a conflict).
 */
final class FudeError extends \RuntimeException
{
	public function __construct(string $message, public readonly int $status = 422, public readonly array $data = [])
	{
		parent::__construct($message);
	}

	/** Translated error with placeholders. */
	public static function t(string $key, array $values = [], int $status = 422, array $data = []): self
	{
		return new self(I18n::t($key, $values), $status, $data);
	}
}
