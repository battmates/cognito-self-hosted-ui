<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesEmailEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
