<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUsers extends Migration
{
    public function up()
    {
        $this->forge->addField(['id' => ['type' => 'INT', 'auto_increment' => true]]);
        $this->forge->createTable('users');
    }
}