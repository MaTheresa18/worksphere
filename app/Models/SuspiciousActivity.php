<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuspiciousActivity extends Model
{
    protected $fillable = [
        'ip_address',
        'type',
        'count',
        'country_code',
        'country_name',
        'city',
        'latitude',
        'longitude',
        'last_observed_at',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_observed_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];
}
