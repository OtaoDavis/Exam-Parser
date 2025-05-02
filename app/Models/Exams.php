<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exams extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'examName',
        'examiner',
        'subject',
        'class',
        'term',
        'year',
        'curriculum',
        'type',
        'answers',
        'image',
    ];

}
