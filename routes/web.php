<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChatController::class, 'index'])->name('chat.index');
Route::get('/chat/attachments/{id}', [ChatController::class, 'attachment'])
    ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->name('chat.attachments.show');
Route::post('/chat', [ChatController::class, 'store'])->middleware('throttle:chat-send')->name('chat.store');
Route::post('/chat/poll', [ChatController::class, 'poll'])->middleware('throttle:chat-poll')->name('chat.poll');
Route::post('/chat/cancel', [ChatController::class, 'cancel'])->name('chat.cancel');
Route::post('/chat/clear', [ChatController::class, 'clear'])->name('chat.clear');
Route::post('/chat/{id}/open', [ChatController::class, 'open'])->whereUuid('id')->name('chat.open');
Route::post('/chat/{id}/delete', [ChatController::class, 'destroy'])->whereUuid('id')->name('chat.delete');
Route::post('/locale', [ChatController::class, 'locale'])->name('locale.update');
