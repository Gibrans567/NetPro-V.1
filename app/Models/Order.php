<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    protected $fillable = ['no_hp', 'menu_id'];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }
}
