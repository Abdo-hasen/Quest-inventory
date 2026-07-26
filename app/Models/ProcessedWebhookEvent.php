<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property Carbon $processed_at
 */
final class ProcessedWebhookEvent extends Model
{
    protected $table = 'processed_webhook_events';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
