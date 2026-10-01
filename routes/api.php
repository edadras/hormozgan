<?php

use App\Http\Controllers\Museum\Api\AskController;
use App\Http\Controllers\Museum\Api\EntityController;
use App\Http\Controllers\Museum\Api\ExploreController;
use App\Http\Controllers\Museum\Api\HistoryController;
use App\Http\Controllers\Museum\Api\KnowledgeController;
use App\Http\Controllers\Museum\Api\LanguageController;
use App\Http\Controllers\Museum\Api\MapController;
use App\Http\Controllers\Museum\Api\PlaceController;
use App\Http\Controllers\Museum\Api\SearchController;
use App\Http\Controllers\Museum\Api\SubmissionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', fn (Request $request) => $request->user())->middleware('auth:sanctum');

// ---- Hormozgan Digital Museum public API (read-only except /ask and /submissions)
Route::prefix('museum')->name('api.museum.')->middleware('throttle:museum-api')->group(function () {
    Route::get('places', [PlaceController::class, 'index'])->name('places.index');
    Route::get('places/{slug}', [PlaceController::class, 'show'])->name('places.show');

    Route::get('entities', [EntityController::class, 'index'])->name('entities.index');
    Route::get('entities/{slug}', [EntityController::class, 'show'])->name('entities.show');

    Route::get('words', [LanguageController::class, 'words'])->name('words.index');
    Route::get('words/{slug}', [LanguageController::class, 'show'])->name('words.show');
    Route::get('dialects', [LanguageController::class, 'dialects'])->name('dialects.index');
    Route::get('dialects/compare', [LanguageController::class, 'compare'])->name('dialects.compare');
    Route::get('dialects/{slug}', [LanguageController::class, 'show'])->name('dialects.show');

    Route::get('foods', [EntityController::class, 'foods'])->name('foods.index');
    Route::get('plants', [EntityController::class, 'plants'])->name('plants.index');

    Route::get('history', [HistoryController::class, 'timeline'])->name('history.timeline');
    Route::get('history/names', [HistoryController::class, 'historicalNames'])->name('history.names');
    Route::get('history/layers', [HistoryController::class, 'layers'])->name('history.layers');

    Route::get('search', [SearchController::class, 'search'])->name('search');
    Route::get('map', [MapController::class, 'geojson'])->name('map');
    Route::get('map/regions/{slug}', [MapController::class, 'region'])->name('map.region');

    Route::get('knowledge/facts/{uuid}', [KnowledgeController::class, 'fact'])->name('knowledge.fact');
    Route::get('knowledge/sources/{uuid}', [KnowledgeController::class, 'source'])->name('knowledge.source');
    Route::get('knowledge/graph/{slug}', [KnowledgeController::class, 'graph'])->name('knowledge.graph');
    Route::get('knowledge/stats', [KnowledgeController::class, 'stats'])->name('knowledge.stats');

    Route::get('explore', [ExploreController::class, 'index'])->name('explore');

    Route::post('ask', [AskController::class, 'ask'])->middleware('throttle:museum-ask')->name('ask');
    Route::post('submissions', [SubmissionController::class, 'store'])->middleware('throttle:museum-submit')->name('submissions.store');
});
