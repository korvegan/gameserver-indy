<?php
declare(strict_types=1);

namespace App\Runtime;

/** A native call may have taken effect. The command must never be replayed. */
final class OutcomeUnknown extends \RuntimeException {}
