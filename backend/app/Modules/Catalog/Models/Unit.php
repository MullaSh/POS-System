<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Unit extends Model
{
    protected $fillable = ['code', 'name'];
}
