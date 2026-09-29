<?php

namespace App\Services;

use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;

/** Shared filter logic for the admin list and the CSV export. */
class SubmissionQuery
{
    /** Pseudo-status grouping several real statuses. */
    public const GROUPS = [
        'queue' => [Submission::AI_ACCEPTED],
        'rejected' => Submission::REJECTED,
        'accepted' => [Submission::AI_ACCEPTED, Submission::APPROVED, Submission::DEPOSITED, Submission::DEPOSIT_FAILED],
    ];

    public static function filtered(array $filters): Builder
    {
        $query = Submission::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(nim) LIKE ?', [$like])
                ->orWhereRaw('LOWER(nama) LIKE ?', [$like])
                ->orWhereHas('faculty', fn (Builder $f) => $f->whereRaw('LOWER(name) LIKE ?', [$like])));
        }

        $status = (string) ($filters['status'] ?? '');
        if (isset(self::GROUPS[$status])) {
            $query->whereIn('status', self::GROUPS[$status]);
        } elseif (isset(Submission::ADMIN_LABELS[$status])) {
            $query->where('status', $status);
        }

        if (! empty($filters['faculty'])) {
            $query->where('faculty_id', (int) $filters['faculty']);
        }
        if (! empty($filters['year'])) {
            $query->where('academic_year', (string) $filters['year']);
        }

        return $query;
    }
}
