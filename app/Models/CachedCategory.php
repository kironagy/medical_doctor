<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CachedCategory — local SQLite cache of merged categories.
 *
 * Stores the merged result of default categories (from config/categories.php)
 * + user custom categories (from user preferences), fetched from the API.
 *
 * This table only exists in the phone's local SQLite. The production MySQL
 * database does NOT have this table — categories there are always computed
 * from config + preferences in real-time.
 *
 * @property int    $id
 * @property int|null $user_id
 * @property string $slug
 * @property string $name
 * @property string|null $icon
 * @property string|null $color
 * @property int    $sort_order
 * @property bool   $is_visible
 * @property string|null $client_updated_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class CachedCategory extends Model
{
    protected $table = 'cached_categories';

    protected $fillable = [
        'user_id',
        'slug',
        'name',
        'icon',
        'color',
        'sort_order',
        'is_visible',
        'client_updated_at',
    ];

    protected $casts = [
        'is_visible'  => 'boolean',
        'sort_order'  => 'integer',
    ];
}
