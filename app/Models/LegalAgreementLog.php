<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalAgreementLog extends Model
{
    protected $fillable = [
        'user_id',
        'document_type',
        'version',
        'ip_address',
        'user_agent',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
