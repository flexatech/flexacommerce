<?php

declare(strict_types=1);

namespace Flexa\Commerce\Downloads;

defined( 'ABSPATH' ) || exit;

/** A download link that cannot be used; the code is the HTTP status to send. */
final class DownloadDenied extends \RuntimeException {}
