<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A generated report and its lifecycle: queued -> running -> ready | failed.
 * Bound by uuid so run URLs can't be guessed. See docs/specs/reports-spec.md.
 */
class ReportRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** How long a finished file is kept before reports:prune removes it (the default; see keepDays()). */
    public const KEEP_DAYS = 7;

    /** Days a finished file is kept: Settings > Documents & PDF. */
    public static function keepDays(): int
    {
        return max(1, (int) (app(\App\Services\Settings\Settings::class)->system('documents.keep_days') ?? self::KEEP_DAYS));
    }

    protected $fillable = [
        'uuid', 'user_id', 'territory_id', 'report_key', 'format', 'params', 'status',
        'progress', 'stage', 'title', 'period_label', 'scope_label', 'file_path', 'file_name',
        'file_size', 'verification_code', 'file_hash', 'error', 'started_at', 'finished_at', 'expires_at',
    ];

    protected $casts = [
        'params' => 'array',
        'progress' => 'integer',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ReportRun $run) {
            $run->uuid ??= (string) Str::uuid();
            $run->verification_code ??= self::newVerificationCode($run->report_key);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /** "MWD-DEM-7K4Q-92XF": module prefix + 8 characters that are hard to misread. */
    public static function newVerificationCode(?string $reportKey): string
    {
        $module = strtoupper(substr(explode('.', (string) $reportKey)[0] ?: 'rep', 0, 3));
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $chars = '';
            for ($i = 0; $i < 8; $i++) {
                $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = sprintf('MWD-%s-%s-%s', $module, substr($chars, 0, 4), substr($chars, 4));
        } while (self::where('verification_code', $code)->exists());

        return $code;
    }

    public function stage(string $stage, int $progress): void
    {
        $this->forceFill(['stage' => $stage, 'progress' => min(99, max($this->progress, $progress))])->save();
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** What the frontend monitor and modal need - never the file path. */
    public function toApi(): array
    {
        return [
            'uuid' => $this->uuid,
            'report_key' => $this->report_key,
            'format' => $this->format,
            'status' => $this->status,
            'progress' => $this->progress,
            'stage' => $this->stage,
            'title' => $this->title,
            'period_label' => $this->period_label,
            'scope_label' => $this->scope_label,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'verification_code' => $this->status === self::STATUS_READY ? $this->verification_code : null,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expired' => $this->isReady() && $this->expires_at && $this->expires_at->isPast(),
        ];
    }
}
