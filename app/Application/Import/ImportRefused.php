<?php

declare(strict_types=1);

namespace App\Application\Import;

use RuntimeException;

/**
 * A run that will not start.
 *
 * Refused before anything is written, and the message names what is missing.
 * Running a partial import whose parents are absent would produce thousands of
 * orphans, all recorded as failures — and an operator who has to read twelve
 * thousand identical failures will not read any of them.
 */
final class ImportRefused extends RuntimeException {}
