<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class SyncError extends Model
{
    use HasUlids;

    protected $table = 'sync_errors';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }
}
