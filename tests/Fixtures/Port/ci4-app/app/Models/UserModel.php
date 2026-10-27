<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $allowedFields = ['name', 'email'];
    protected $useSoftDeletes = true;
    protected $beforeInsert = ['hashPassword'];
    protected $validationRules = ['email' => 'required|valid_email'];
}