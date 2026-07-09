<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Eloquent model representing persistent database cooldowns.
 *
 * @property int $id
 * @property string $key
 * @property string $action
 * @property string|null $target_type
 * @property int|string|null $target_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @method static Builder|static active()
 * @method static Builder|static expired()
 * @method static Builder|static forKey(string $key)
 */
class Cooldown extends Model
{
    use Prunable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'action',
        'target_type',
        'target_id',
        'expires_at',
    ];

    /**
     * Get the table associated with the model dynamically from config.
     */
    public function getTable(): string
    {
        return config('cooldowns.drivers.database.table', parent::getTable());
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnectionName(): ?string
    {
        return config('cooldowns.drivers.database.connection', parent::getConnectionName());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Get the target model associated with the cooldown, if polymorphic.
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include active (unexpired) cooldowns.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', CarbonImmutable::now());
    }

    /**
     * Scope a query to only include expired cooldowns.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', CarbonImmutable::now());
    }

    /**
     * Scope a query to filter by the unique storage key.
     */
    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    /**
     * Get the prunable model query for automated database cleanup.
     */
    public function prunable(): Builder
    {
        $days = (int) config('cooldowns.drivers.database.prune_after_days', 7);

        return static::query()->where('expires_at', '<=', CarbonImmutable::now()->subDays($days));
    }
}
