<?php

declare(strict_types=1);

namespace raspberry\pack\script;

/**
 * Raised when script content cannot be loaded (missing entry, size limits, ...).
 */
final class ScriptException extends \RuntimeException{
}
