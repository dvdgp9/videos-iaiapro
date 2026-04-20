<?php
declare(strict_types=1);

namespace App\Render;

/** Thrown when the worker has no record of the job_id we asked about. */
final class WorkerNotFoundException extends \RuntimeException {}
