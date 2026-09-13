<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamPaperController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\ScoreController;
use App\Http\Controllers\Api\WeaknessController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\PracticeController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    Route::prefix('questions')->group(function () {
        Route::get('/', [QuestionController::class, 'index']);
        Route::post('/', [QuestionController::class, 'store']);
        Route::get('/categories', [QuestionController::class, 'categories']);
        Route::post('/categories', [QuestionController::class, 'storeCategory']);
        Route::get('/{question}', [QuestionController::class, 'show']);
        Route::put('/{question}', [QuestionController::class, 'update']);
        Route::delete('/{question}', [QuestionController::class, 'destroy']);
    });

    Route::prefix('exam-papers')->group(function () {
        Route::get('/', [ExamPaperController::class, 'index']);
        Route::post('/', [ExamPaperController::class, 'store']);
        Route::get('/{examPaper}', [ExamPaperController::class, 'show']);
        Route::put('/{examPaper}', [ExamPaperController::class, 'update']);
        Route::delete('/{examPaper}', [ExamPaperController::class, 'destroy']);
        Route::post('/{examPaper}/questions', [ExamPaperController::class, 'addQuestions']);
        Route::delete('/{examPaper}/questions/{question}', [ExamPaperController::class, 'removeQuestion']);
    });

    Route::prefix('exams')->group(function () {
        Route::get('/', [ExamController::class, 'index']);
        Route::post('/{examPaper}/start', [ExamController::class, 'start']);
        Route::get('/{examPaper}/questions', [ExamController::class, 'getQuestions']);
        Route::post('/{examPaper}/submit', [ExamController::class, 'submit']);
        Route::get('/records', [ExamController::class, 'myRecords']);
        Route::get('/records/{record}', [ExamController::class, 'showRecord']);
    });


    // 知识点弱项画像（学习反馈，不影响正式成绩）
    Route::prefix('weakness')->group(function () {
        Route::get('/my', [WeaknessController::class, 'myProfile']);
        Route::get('/my/recommendations', [WeaknessController::class, 'myRecommendations']);
        Route::get('/students/{student}', [WeaknessController::class, 'studentProfile']);
        Route::get('/classes/{classRoom}', [WeaknessController::class, 'classProfile']);
    });

    // 班级管理（教师/管理员）
    Route::prefix('classes')->group(function () {
        Route::get('/', [ClassController::class, 'index']);
        Route::post('/', [ClassController::class, 'store']);
        Route::put('/{classRoom}', [ClassController::class, 'update']);
        Route::delete('/{classRoom}', [ClassController::class, 'destroy']);
        Route::get('/{classRoom}/students', [ClassController::class, 'students']);
        Route::post('/{classRoom}/students', [ClassController::class, 'addStudent']);
        Route::delete('/{classRoom}/students/{student}', [ClassController::class, 'removeStudent']);
    });

    // 弱项巩固练习（独立于正式考试，不计分）
    Route::prefix('practice')->group(function () {
        Route::post('/start/recommended', [PracticeController::class, 'startRecommended']);
        Route::post('/start/custom', [PracticeController::class, 'startCustom']);
        Route::post('/{session}/submit', [PracticeController::class, 'submit']);
        Route::get('/history', [PracticeController::class, 'history']);
        Route::get('/{session}', [PracticeController::class, 'show']);
    });

    Route::prefix('scores')->group(function () {
        Route::get('/statistics', [ScoreController::class, 'statistics']);
        Route::get('/ranking/{examPaper}', [ScoreController::class, 'ranking']);
        Route::get('/analysis/{examPaper}', [ScoreController::class, 'analysis']);
    });
});
