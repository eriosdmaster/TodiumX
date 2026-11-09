<?php

declare(strict_types=1);

namespace raspberry\pack;

use RuntimeException;

/**
 * A behavior pack or one of its files cannot be used. The message says why and is safe to show.
 */
class PackException extends RuntimeException{

}
