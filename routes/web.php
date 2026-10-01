<?php

use App\Http\Controllers\Museum\Admin\AdminController as A;
use App\Http\Controllers\Museum\Admin\AuthController;
use App\Http\Controllers\Museum\MuseumController as M;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/museum');

// ---------------------------------------------------------------- public museum
Route::prefix('museum')->name('museum.')->group(function () {
    Route::get('/', [M::class, 'home'])->name('home');
    Route::get('search', [M::class, 'search'])->name('search');
    Route::match(['get', 'post'], 'ask', [M::class, 'ask'])->middleware('throttle:museum-ask')->name('ask');
    Route::get('explore', [M::class, 'explore'])->name('explore');
    Route::get('kids', [M::class, 'kids'])->name('kids');
    Route::get('e/{slug}', [M::class, 'entity'])->name('entity');
    Route::get('facts/{uuid}', [M::class, 'fact'])->name('fact');
    Route::get('sources/{uuid}', [M::class, 'source'])->name('source');
    Route::get('files/{uuid}', [M::class, 'file'])->name('file');
    Route::get('language', [M::class, 'language'])->name('language');
    Route::get('language/compare', [M::class, 'compare'])->name('compare');
    Route::get('language/grammar', [M::class, 'grammar'])->name('grammar');
    Route::get('history', [M::class, 'history'])->name('history');
    Route::get('history/map', [M::class, 'timeMap'])->name('history.map');
    Route::get('sea/lenj', [M::class, 'lenj'])->name('lenj');
    Route::get('contribute', [M::class, 'contribute'])->name('contribute');
    Route::post('contribute', [M::class, 'contributeStore'])->middleware('throttle:museum-submit')->name('contribute.store');
    Route::get('about/crawler', [M::class, 'crawlerInfo'])->name('crawler');
    Route::get('{section}', [M::class, 'section'])->whereIn('section', array_keys(M::SECTIONS))->name('section');
});

// ---------------------------------------------------------------- admin
Route::prefix('museum/admin')->name('museum.admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.post');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware(['auth', 'can:museum-admin-panel'])->group(function () {
        Route::get('/', [A::class, 'dashboard'])->name('dashboard');
        Route::get('verification', [A::class, 'verification'])->name('verification');
        Route::post('facts/{fact:uuid}/status', [A::class, 'verifyFact'])->name('facts.status');
        Route::post('facts/{fact:uuid}/value', [A::class, 'factUpdate'])->name('facts.update');
        Route::get('conflicts', [A::class, 'conflicts'])->name('conflicts');
        Route::post('conflicts/{conflict}', [A::class, 'resolveConflict'])->name('conflicts.resolve');
        Route::get('duplicates', [A::class, 'duplicates'])->name('duplicates');
        Route::post('duplicates/{dup}', [A::class, 'decideDuplicate'])->name('duplicates.decide');
        Route::get('candidates', [A::class, 'candidates'])->name('candidates');
        Route::post('candidates/{candidate}', [A::class, 'decideCandidate'])->name('candidates.decide');
        Route::get('sources', [A::class, 'sources'])->name('sources');
        Route::post('sources', [A::class, 'sourceStore'])->name('sources.store');
        Route::get('sources/{source:uuid}', [A::class, 'sourceEdit'])->name('sources.edit');
        Route::post('sources/{source:uuid}', [A::class, 'sourceUpdate'])->name('sources.update');
        Route::post('crawlers/{crawler}', [A::class, 'crawlerUpdate'])->name('crawlers.update');
        Route::get('imports', [A::class, 'imports'])->name('imports');
        Route::get('imports/{batch:uuid}', [A::class, 'importShow'])->name('imports.show');
        Route::get('ai-jobs', [A::class, 'aiJobs'])->name('ai-jobs');
        Route::get('submissions', [A::class, 'submissions'])->name('submissions');
        Route::post('submissions/{submission:uuid}', [A::class, 'submissionTransition'])->name('submissions.transition');
        Route::get('entities', [A::class, 'entities'])->name('entities');
        Route::post('entities', [A::class, 'entityCreate'])->name('entities.create');
        Route::get('entities/{entity:slug}', [A::class, 'entityEdit'])->name('entities.edit');
        Route::post('entities/{entity:slug}', [A::class, 'entityUpdate'])->name('entities.update');
        Route::post('entities/{entity:slug}/publish', [A::class, 'entityPublish'])->name('entities.publish');
        Route::post('entities/{entity:slug}/facts', [A::class, 'factStore'])->name('entities.facts.store');
        Route::post('entities/{entity:slug}/historical-names', [A::class, 'historicalNameStore'])->name('entities.historical-names.store');
        Route::get('media', [A::class, 'media'])->name('media');
        Route::post('media', [A::class, 'mediaStore'])->name('media.store');
        Route::post('media/{media:uuid}', [A::class, 'mediaPublish'])->name('media.publish');
        Route::get('research', [A::class, 'research'])->name('research');
        Route::post('research', [A::class, 'researchStore'])->name('research.store');
    });
});
