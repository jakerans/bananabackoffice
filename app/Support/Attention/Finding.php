<?php

namespace App\Support\Attention;

/**
 * One thing a rule found that needs someone, before it is kept as an
 * item for each of its owners (docs/slice-26-needs-attention-plan.md, step
 * 26a; decision 185).
 */
final readonly class Finding
{
    /**
     * @param  string  $subjectKey  What it is about: "project:5", "period:2026-10".
     * @param  list<int>  $userIds  Its owners.
     */
    public function __construct(
        public string $rule,
        public string $subjectKey,
        public ?int $locationId,
        public string $severity,
        public string $title,
        public string $url,
        public array $userIds,
    ) {}
}
