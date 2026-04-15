<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OcrController;

Route::post('/ocr', [OcrController::class, 'ocr']);
Route::post('/ocr-json', [lOcrControler::class, 'ocrFromJson']);