<?php

namespace App\Support\Proposals;

use App\Models\Objection;
use App\Models\SalesLossReason;
use Illuminate\Validation\ValidationException;

/**
 * What goes with a result once it has been checked: why a sale was lost, to
 * whom, what held the customer back, and the note.
 */
final readonly class ResultDetails
{
    public function __construct(
        public ?SalesLossReason $reason = null,
        public ?string $lostTo = null,
        public ?Objection $objection = null,
        public ?string $note = null,
    ) {}

    /**
     * For something lost (decision 125, answer 7): a reason from Sales Loss
     * Reasons and the primary objection are both needed. A reason that asks
     * for the competitor keeps one if it was given. A reason or an objection
     * that needs a note is refused without one.
     *
     * @param  array<string, mixed>  $input  Any of: lossReasonId, lostTo, objectionId, note.
     * @param  string  $prefix  Put before each field's name in a refusal, for a form with several of these.
     *
     * @throws ValidationException
     */
    public static function forLost(array $input, string $prefix = ''): self
    {
        $reason = self::id($input['lossReasonId'] ?? null) === null ? null : SalesLossReason::query()->find(self::id($input['lossReasonId']));

        if ($reason === null) {
            throw ValidationException::withMessages([$prefix.'lossReasonId' => __('Choose why it was lost.')]);
        }

        if (! $reason->is_active) {
            throw ValidationException::withMessages([
                $prefix.'lossReasonId' => __(':reason is no longer offered. Choose another.', ['reason' => $reason->name]),
            ]);
        }

        $objection = self::objection($input, $prefix, __('Choose the main objection.'));
        $note = self::text($input['note'] ?? null);

        if ($note === null && ($reason->requires_note || $objection->requires_note)) {
            throw ValidationException::withMessages([$prefix.'resultNote' => __('Add a note that says what happened.')]);
        }

        return new self($reason, $reason->asks_for_competitor ? self::text($input['lostTo'] ?? null) : null, $objection, $note);
    }

    /**
     * For a proposal that is still open: the main objection, so the reason it
     * is open is written down.
     *
     * @param  array<string, mixed>  $input  Any of: objectionId, note.
     *
     * @throws ValidationException
     */
    public static function forPending(array $input, string $prefix = ''): self
    {
        $objection = self::objection($input, $prefix, __('Choose the main objection, so the reason it is still open is written down.'));
        $note = self::text($input['note'] ?? null);

        if ($note === null && $objection->requires_note) {
            throw ValidationException::withMessages([$prefix.'resultNote' => __('Add a note that says what the objection is.')]);
        }

        return new self(objection: $objection, note: $note);
    }

    /**
     * For something sold: only the note, which is optional.
     *
     * @param  array<string, mixed>  $input  Any of: note.
     */
    public static function forSold(array $input): self
    {
        return new self(note: self::text($input['note'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private static function objection(array $input, string $prefix, string $missing): Objection
    {
        $objection = self::id($input['objectionId'] ?? null) === null ? null : Objection::query()->find(self::id($input['objectionId']));

        if ($objection === null) {
            throw ValidationException::withMessages([$prefix.'objectionId' => $missing]);
        }

        if (! $objection->is_active) {
            throw ValidationException::withMessages([
                $prefix.'objectionId' => __(':objection is no longer offered. Choose another.', ['objection' => $objection->name]),
            ]);
        }

        return $objection;
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }
}
