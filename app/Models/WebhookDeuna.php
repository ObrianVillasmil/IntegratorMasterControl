<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDeuna extends Model
{
    use HasFactory;

    protected $fillable = ['data','connection'];
}
