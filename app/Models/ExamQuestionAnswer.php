<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamQuestionAnswer extends Model
{
    use HasFactory;

    protected $table = 'exam_question_answers';
    
     /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'exam_id',
        'question_number',
        'question_sub_part',
        'question',
        'answer',
        'image',
    ];

    public function exam(){
        return $this->belongsTo(Exam::class);
    }
}
