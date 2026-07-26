<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property int $user_id
 * @property int $response_code
 * @property array<string, mixed> $response_body
 * @property Carbon $created_at
 * @property-read User $user
 */
final class IdempotencyKey extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'idempotency_keys';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'user_id',
        'response_code',
        'response_body',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'response_code' => 'integer',
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
