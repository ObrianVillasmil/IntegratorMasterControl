<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    use HasFactory;

    private $fillable=[
        'id_user',
        'ip',
        'end_point',
        'id_company'
    ];
}
