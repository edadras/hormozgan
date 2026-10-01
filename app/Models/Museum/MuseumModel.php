<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for all museum models. Mass assignment is controlled at the request
 * validation / service layer, so models only guard their primary key.
 */
abstract class MuseumModel extends Model
{
    protected $guarded = ['id'];
}
