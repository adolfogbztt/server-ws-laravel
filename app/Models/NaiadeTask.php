<?php

namespace App\Models;

use App\Enums\NaiadeTaskService;
use App\Enums\NaiadeTaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NaiadeTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket',
        'service',
        'message',
        'status',
    ];

    protected $casts = [
        'service' => NaiadeTaskService::class,
        'status' => NaiadeTaskStatus::class,
    ];
}
