<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookUber extends Model
{
    use HasFactory;

    protected $fillable = ['data'];

}
