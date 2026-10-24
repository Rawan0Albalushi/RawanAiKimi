<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChatController::class, 'index'])->name('chat.index');
Route::post('/chat', [ChatController::class, 'store'])->middleware('throttle:chat-send')->name('chat.store');
Route::post('/chat/poll', [ChatController::class, 'poll'])->middleware('throttle:chat-poll')->name('chat.poll');
Route::post('/chat/cancel', [ChatController::class, 'cancel'])->name('chat.cancel');
Route::post('/chat/clear', [ChatController::class, 'clear'])->name('chat.clear');
Route::post('/locale', [ChatController::class, 'locale'])->name('locale.update');
