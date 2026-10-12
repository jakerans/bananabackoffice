<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class RemindToCloseOutAppointment
{
    /** How long after an appointment ends its salesperson is reminded, in minutes (decision 126). */
    public const int MINUTES_AFTER_END = 15;

    /**
     * Appointments that ended at least fifteen minutes ago, still have no
     * answer, have a salesperson to remind, and have not been reminded.
     *
     * @return Builder<Appointment>
     */
    public static function due(CarbonInterface $moment): Builder
    {
        return Appointment::query()
            ->where('state', AppointmentState::Booked)
            ->where('ends_at', '<=', $moment->copy()->utc()->subMinutes(self::MINUTES_AFTER_END))
            ->whereNull('close_out_reminded_at')
            ->whereNotNull('salesperson_id')
            ->whereHas('lead', fn (Builder $lead) => $lead->where('status', LeadStatus::AppointmentBooked));
    }

    /**
     * Remind one appointment's salesperson, once, to say whether it was run or
     * was a no-show: a notice inside the app and an email. The aim is the
     * habit of closing an appointment out from the car, straight after it
     * (decision 126; product-architecture.md, 5.6).
     *
     * Neither carries anything about the customer. The notice is saved with
     * the mark that it was sent; the email goes after, and if it cannot be
     * sent the failure is reported and the reminder is not repeated.
     *
     * @return bool Whether the reminder was sent.
     */
    public function handle(int $appointmentId): bool
    {
        $reminded = DB::transaction(function () use ($appointmentId): ?array {
            // The appointment row is locked with a write that changes nothing, so two ticks cannot both remind.
            DB::table('appointments')->where('id', $appointmentId)->update(['id' => DB::raw('id')]);

            $appointment = self::due(now())->find($appointmentId);
            $salesperson = $appointment === null ? null : User::query()->where('is_active', true)->find($appointment->salesperson_id);

            if ($appointment === null || $salesperson === null) {
                return null;
            }

            $appointment->forceFill(['close_out_reminded_at' => now()])->save();
            $salesperson->notify(new AppointmentChanged($appointment->lead_id, AppointmentChanged::CLOSE_OUT_REMINDER, AppointmentChanged::IN_APP, $appointment));

            return [$salesperson, $appointment];
        });

        if ($reminded === null) {
            return false;
        }

        [$salesperson, $appointment] = $reminded;

        try {
            $salesperson->notify(new AppointmentChanged($appointment->lead_id, AppointmentChanged::CLOSE_OUT_REMINDER, AppointmentChanged::EMAIL, $appointment));
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }
}
