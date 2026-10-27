<?php

namespace App\Controllers;

class Users extends BaseController
{
    public function show(int $id)
    {
        helper('url');

        return view('users/show', ['id' => esc($id), 'back' => site_url('users')]);
    }
}