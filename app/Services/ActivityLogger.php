<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Submission;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    private const REDACT = ['password', 'api_key', 'token', 'secret', 'refresh_token', 'headers', 'authorization'];

    public static function admin(string $action, ?Submission $submission = null, array $meta = []): ActivityLog
    {
        $user = Auth::user();

        return self::write('admin', $action, $submission, $meta, $user?->id, $user?->email);
    }

    public static function student(string $action, Submission $submission, array $meta = []): ActivityLog
    {
        return self::write('student', $action, $submission, $meta, null, $submission->nim.' '.$submission->nama);
    }

    public static function system(string $action, ?Submission $submission = null, array $meta = []): ActivityLog
    {
        return self::write('system', $action, $submission, $meta, null, 'system');
    }

    private static function write(string $type, string $action, ?Submission $submission, array $meta,
        ?int $actorId, ?string $label): ActivityLog
    {
        $request = app()->runningInConsole() ? null : request();

        return ActivityLog::create([
            'actor_type' => $type,
            'actor_id' => $actorId,
            'actor_label' => $label,
            'action' => $action,
            'submission_id' => $submission?->id,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 250) : null,
            'meta' => $meta ? self::redact($meta) : null,
        ]);
    }

    private static function redact(array $meta): array
    {
        foreach (Arr::dot($meta) as $key => $value) {
            foreach (self::REDACT as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    Arr::set($meta, $key, '***');
                }
            }
        }

        return $meta;
    }
}
