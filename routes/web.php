<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ParsingController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [ParsingController::class, 'create'])->name('exams.index');
Route::get('/upload', [ParsingController::class, 'create'])->name('exams.upload');
Route::post('/store', [ParsingController::class, 'store'])->name('exams.store');

