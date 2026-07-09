<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Row-level distributed lock for a single source file.
 *
 * Ownership is expressed by `locked_by` (the EC2 instance id). The application
 * is solely responsible for setting, respecting and clearing the lock; there
 * is no external lock manager. A unique constraint on `file_name` guarantees a
 * single lock row per file across all instances.
 *
 * @property int         $id
 * @property string      $file_name
 * @property string|null $locked_by
 * @property string      $status
 * @property Carbon|null $locked_at
 * @property Carbon|null $last_processed_at
 * @property Carbon|null $completed_at
 */
final class FileProcessingLock extends Model
{
    protected $table = 'file_processing_locks';

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'file_name',
        'locked_by',
        'status',
        'locked_at',
        'last_processed_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked_at'         => 'datetime',
            'last_processed_at' => 'datetime',
            'completed_at'      => 'datetime',
        ];
    }

    /**
     * Scope: locks currently held (status = processing).
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope: held locks whose owner appears to have died, i.e. they have been
     * in `processing` longer than the stale timeout.
     */
    public function scopeStale(Builder $query, int $timeoutMinutes): Builder
    {
        return $query
            ->where('status', self::STATUS_PROCESSING)
            ->where('locked_at', '<', now()->subMinutes($timeoutMinutes));
    }
}
