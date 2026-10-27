<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use SoftDeletes;

    protected $fillable = ['name', 'email'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function getNameAttribute($value)
    {
        return ucfirst($value);
    }
}