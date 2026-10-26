<?php

namespace App\Support\QuickBooks;

use RuntimeException;

/**
 * QuickBooks could not be reached, refused, or answered with something
 * Backoffice cannot read (docs/slice-28-quickbooks-plan.md). Its message is
 * plain words for staff; it never carries a key.
 */
class QuickBooksUnavailable extends RuntimeException {}
