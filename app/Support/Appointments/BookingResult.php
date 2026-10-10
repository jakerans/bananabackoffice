<?php

namespace App\Support\Appointments;

use App\Models\Appointment;

/**
 * What a booking produced: the appointment, and whether the salesperson's
 * email went out. The appointment is saved either way; the notice inside the
 * app is saved with it.
 */
final readonly class BookingResult
{
    public function __construct(
        public Appointment $appointment,
        public bool $emailSent,
    ) {}
}
