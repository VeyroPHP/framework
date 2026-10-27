<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class UserController extends Controller
{
    public function show(?int $id = null)
    {
        $remote = Http::get('https://example.com/users/' . $id);
        $count = Cache::remember('users', 60, fn () => DB::table('users')->count());

        return view('users.show', ['users' => collect([$remote]), 'count' => $count, 'home' => route('home')]);
    }
}