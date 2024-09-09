<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
{
    DB::table('menus')->insert([
        ['name' => 'Menu 1', 'price' => 10000, 'expiry_time' => 60],
        ['name' => 'Menu 2', 'price' => 20000, 'expiry_time' => 120],
    ]);
}

}
