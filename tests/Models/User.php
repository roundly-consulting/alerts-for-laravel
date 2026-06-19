<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class User extends Model
{
    use Notifiable;

    protected $guarded = [];

    public $timestamps = false;
}
