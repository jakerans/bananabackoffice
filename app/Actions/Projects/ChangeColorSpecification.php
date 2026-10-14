<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ColorReadiness;
use App\Enums\Sheen;
use App\Models\ColorSpecification;
use App\Models\ColorSpecificationLine;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeColorSpecification
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Add a line to a Project's Color Specification: what goes on one surface
     * of one area (docs/slice-10-pre-production-plan.md, step 10b;
     * product-architecture.md, 6.4). The Project's Production Manager, or a
     * manager where it is (decision 141, questions 4 and 7).
     *
     * The first line starts version 1. A line added to colors the customer
     * has already approved starts the next version, which needs approval
     * again; the approved one is kept exactly as it was (question 5).
     *
     * @param  array<string, mixed>  $line  area, surface, manufacturer, colorName, colorCode, sheen, product, note.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function addLine(Project $project, array $line, User $actor): ColorSpecificationLine
    {
        return $this->change($project, $actor, function (ColorSpecification $working) use ($line): array {
            $added = new ColorSpecificationLine;
            $added->forceFill([
                'color_specification_id' => $working->id,
                'position' => ((int) $working->lines()->max('position')) + 1,
                ...self::read($line),
            ])->save();

            return [$added, AuditAction::ProjectColorLineAdded, [], ['line' => $added->summary()]];
        });
    }

    /**
     * Change one line. $lineId is a line of the version shown; when that
     * version is approved, the change is made to its copy in the next one.
     *
     * @param  array<string, mixed>  $line
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function changeLine(Project $project, int $lineId, array $line, User $actor): ColorSpecificationLine
    {
        return $this->change($project, $actor, function (ColorSpecification $working, array $copies) use ($lineId, $line): array {
            $existing = self::lineOf($working, $copies[$lineId] ?? $lineId);
            $before = ['line' => $existing->summary(), 'note' => $existing->note];

            $existing->forceFill(self::read($line))->save();

            return [$existing, AuditAction::ProjectColorLineChanged, $before, ['line' => $existing->summary(), 'note' => $existing->note]];
        }, unchanged: function (?ColorSpecification $latest) use ($lineId, $line): ?ColorSpecificationLine {
            $values = self::read($line);
            $existing = $latest === null ? null : self::lineOf($latest, $lineId);

            return $existing !== null && ! $existing->forceFill($values)->isDirty() ? $existing : null;
        });
    }

    /**
     * Take one line out. When the version shown is approved, it is left out
     * of the next version; the approved one keeps it.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function removeLine(Project $project, int $lineId, User $actor): void
    {
        $this->change($project, $actor, function (ColorSpecification $working, array $copies) use ($lineId): array {
            $existing = self::lineOf($working, $copies[$lineId] ?? $lineId);
            $before = $existing->summary();

            $existing->delete();

            return [$existing, AuditAction::ProjectColorLineRemoved, ['line' => $before], []];
        });
    }

    /**
     * Say the colors are with the customer to approve. Backoffice sends the
     * customer nothing; this records that somebody has. Changing a line
     * afterwards takes it back, because the customer would be approving
     * something else.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function sendForApproval(Project $project, User $actor): void
    {
        DB::transaction(function () use ($project, $actor): void {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $working = $project->colorSpecifications()->first();

            if ($working === null || $working->isApproved() || $working->lines()->doesntExist()) {
                throw ValidationException::withMessages(['colors' => __('Write the colors down before they go to the customer.')]);
            }

            if ($working->sent_for_approval_at !== null) {
                return;
            }

            $working->forceFill(['sent_for_approval_at' => now(), 'sent_for_approval_by' => $actor->id, 'sent_for_approval_by_name' => $actor->name])->save();
            $project->forceFill(['color_readiness' => ColorReadiness::AwaitingApproval])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectColorsSentForApproval,
                $project,
                actor: $actor,
                before: ['color_readiness' => ColorReadiness::InProgress->value],
                after: ['color_readiness' => ColorReadiness::AwaitingApproval->value, 'version' => $working->version],
                location: $project->location,
            );
        });
    }

    /**
     * Where a Project's colors stand, from what is written down.
     */
    public static function readiness(Project $project): ColorReadiness
    {
        $latest = $project->colorSpecifications()->first();

        return match (true) {
            $latest === null => ColorReadiness::NotStarted,
            $latest->isApproved() => ColorReadiness::Approved,
            $latest->sent_for_approval_at !== null => ColorReadiness::AwaitingApproval,
            // A first version with every line taken out again is as if nothing was written.
            $latest->version === 1 && $latest->lines()->doesntExist() => ColorReadiness::NotStarted,
            default => ColorReadiness::InProgress,
        };
    }

    /**
     * Make one change to the version being written, starting it first if the
     * colors are approved or there are none, and keep the Project's Color
     * Readiness in step.
     *
     * @param  Closure(ColorSpecification, array<int, int>): array{0: ColorSpecificationLine, 1: AuditAction, 2: array<string, mixed>, 3: array<string, mixed>}  $apply  Given the version being written and, when it was just copied from an approved one, each old line's ID with its copy's.
     * @param  (Closure(ColorSpecification|null): (ColorSpecificationLine|null))|null  $unchanged  Run before a new version is started: it refuses what cannot be saved, and gives back the line when saving would change nothing.
     */
    private function change(Project $project, User $actor, Closure $apply, ?Closure $unchanged = null): ColorSpecificationLine
    {
        return DB::transaction(function () use ($project, $actor, $apply, $unchanged): ColorSpecificationLine {
            // Read as it is now, and held so two changes cannot cross or start two versions.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $was = $project->color_readiness;
            $latest = $project->colorSpecifications()->first();
            $same = $unchanged === null ? null : $unchanged($latest);

            if ($same !== null) {
                return $same;
            }
            $copies = [];
            $working = $latest;

            if ($latest === null || $latest->isApproved()) {
                $working = new ColorSpecification;
                $working->forceFill([
                    'project_id' => $project->id,
                    'version' => ($latest->version ?? 0) + 1,
                    'created_by' => $actor->id,
                    'created_by_name' => $actor->name,
                ])->save();

                // The next version starts as a copy of the approved one, which stays as it was.
                foreach ($latest->lines ?? [] as $line) {
                    $copy = $line->replicate(['color_specification_id']);
                    $copy->forceFill(['color_specification_id' => $working->id])->save();
                    $copies[$line->id] = $copy->id;
                }

                $this->recordAuditEvent->handle(
                    $latest === null ? AuditAction::ProjectColorsStarted : AuditAction::ProjectColorsNewVersion,
                    $project,
                    actor: $actor,
                    before: $latest === null ? [] : ['version' => $latest->version, 'color_readiness' => $was->value],
                    after: ['version' => $working->version],
                    location: $project->location,
                );
            }

            [$line, $action, $before, $after] = $apply($working, $copies);

            // Changed after it went to the customer, it is no longer what they were asked to approve.
            if ($working->sent_for_approval_at !== null) {
                $working->forceFill(['sent_for_approval_at' => null, 'sent_for_approval_by' => null, 'sent_for_approval_by_name' => null])->save();
            }

            $working->touch();
            $project->forceFill(['color_readiness' => self::readiness($project)])->save();

            $this->recordAuditEvent->handle(
                $action,
                $project,
                actor: $actor,
                before: $before,
                after: [...$after, 'version' => $working->version],
                location: $project->location,
            );

            return $line;
        });
    }

    /**
     * A line of the version being written, or a refusal.
     *
     * @throws ValidationException
     */
    private static function lineOf(ColorSpecification $working, int $lineId): ColorSpecificationLine
    {
        return $working->lines()->whereKey($lineId)->first()
            ?? throw ValidationException::withMessages(['colors' => __('This line is no longer here. Reload the page.')]);
    }

    /**
     * Read a line as a person typed it.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function read(array $line): array
    {
        $text = fn (string $key): string => trim(is_scalar($line[$key] ?? null) ? (string) $line[$key] : '');
        $errors = [];

        $required = [
            'area' => __('Say which room or area.'),
            'surface' => __('Say which surface: walls, trim, ceiling...'),
            'manufacturer' => __('Say who makes the paint.'),
            'colorName' => __('Give the color\'s name.'),
        ];

        foreach ($required as $key => $message) {
            if ($text($key) === '') {
                $errors['line.'.$key] = $message;
            }
        }

        foreach (['area', 'surface', 'manufacturer', 'colorName', 'product'] as $key) {
            if (mb_strlen($text($key)) > ColorSpecificationLine::TEXT_LENGTH) {
                $errors['line.'.$key] = __('Keep this to :max characters or fewer.', ['max' => ColorSpecificationLine::TEXT_LENGTH]);
            }
        }

        if (mb_strlen($text('colorCode')) > ColorSpecificationLine::CODE_LENGTH) {
            $errors['line.colorCode'] = __('Keep this to :max characters or fewer.', ['max' => ColorSpecificationLine::CODE_LENGTH]);
        }

        if (mb_strlen($text('note')) > ColorSpecificationLine::NOTE_LENGTH) {
            $errors['line.note'] = __('Keep this to :max characters or fewer.', ['max' => ColorSpecificationLine::NOTE_LENGTH]);
        }

        $sheen = Sheen::tryFrom($text('sheen'));

        if ($sheen === null) {
            $errors['line.sheen'] = __('Choose the sheen.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'area' => $text('area'),
            'surface' => $text('surface'),
            'manufacturer' => $text('manufacturer'),
            'color_name' => $text('colorName'),
            'color_code' => $text('colorCode') === '' ? null : $text('colorCode'),
            'sheen' => $sheen,
            'product' => $text('product') === '' ? null : $text('product'),
            'note' => $text('note') === '' ? null : $text('note'),
        ];
    }
}
