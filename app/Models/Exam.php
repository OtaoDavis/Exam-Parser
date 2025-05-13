<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    use HasFactory;

    protected $table = 'exams';
    
    protected $fillable = [
        'examName',
        'examiner',
        'subject',
        'class',
        'term',
        'year',
        'curriculum',
        'type',
        'processing_time',
    ];

    public function questions(){
    return $this->hasMany(ExamQuestionAnswer::class);
}

}
