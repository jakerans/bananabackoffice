<?php

namespace App\Support\Addresses;

use RuntimeException;

/**
 * Reported to the log when Mapbox fails. It is never shown to staff: they are
 * offered manual address entry instead. Its message never contains the access token.
 */
final class MapboxLookupFailed extends RuntimeException {}
