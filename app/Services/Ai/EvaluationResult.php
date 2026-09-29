<?php

namespace App\Services\Ai;

final class EvaluationResult
{
    /**
     * @param  array<int, array{key: string, label: string, score: int, min_score: int, required: bool, passed: bool, reason: string}>  $criteria
     */
    public function __construct(
        public readonly array $criteria,
        public readonly bool $passed,
        public readonly int $overallScore,
        public readonly string $summary,
        public readonly string $raw = '',
    ) {}

    /** Student-facing reasons for every failed required criterion. */
    public function rejectionReasons(): array
    {
        return array_values(array_map(
            fn ($c) => $c['label'].': '.$c['reason'],
            array_filter($this->criteria, fn ($c) => $c['required'] && ! $c['passed']),
        ));
    }
}
