<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserMeta extends Model
{
    protected $table = 'user_meta';
    protected $guarded = [];

    public $timestamps = true; // your table has created_at/updated_at

    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    /**
     * Upsert an associative array of meta name => value for a user.
     */
    public static function upsertPairs(int $userId, array $pairs): void
    {
        foreach ($pairs as $name => $val) {
            static::updateOrCreate(
                ['user_id' => $userId, 'name' => $name],
                ['val'     => $val]
            );
        }
    }

    /**
     * Fetch a single meta value (or null).
     */
    public static function getValue(int $userId, string $name): ?string
    {
        $row = static::where('user_id', $userId)->where('name', $name)->first();
        return $row?->val;
    }
}