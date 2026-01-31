<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhitelistedIp extends Model
{
    protected $fillable = [
        'ip_address',
        'label',
        'added_by',
    ];

    /**
     * Get the user who added this IP to the whitelist.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
