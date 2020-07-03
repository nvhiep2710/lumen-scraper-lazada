<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'user';
    protected $fillable = [
        'user_name',
        'email',
        'password',
        'status'
    ];
    public $timestamps = false;
}