<?php

use App\Http\Controllers\ActivityPhotoUploadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\KardexController;
use App\Http\Middleware\EnsureAccountReady;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
});
Route::middleware(['auth', EnsureAccountReady::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::view('/password', 'auth.password')->name('password.edit');
    Route::post('/password', [AuthController::class, 'password'])->name('password.update')->middleware('throttle:6,1');
    Route::get('/', [KardexController::class, 'dashboard'])->name('dashboard');
    Route::get('/administracion', [KardexController::class, 'administration'])->name('administration');
    Route::post('/clientes', [KardexController::class, 'client'])->name('clients.store');
    Route::post('/usuarios', [KardexController::class, 'user'])->name('users.store');
    Route::patch('/usuarios/{user}', [KardexController::class, 'updateUser'])->name('users.update');
    Route::post('/proyectos', [KardexController::class, 'createProject'])->name('projects.store');
    Route::get('/proyectos', [KardexController::class, 'projects'])->name('projects.index');
    Route::get('/proyectos/{project}', [KardexController::class, 'project'])->name('projects.show');
    Route::post('/proyectos/{project}/fotos-temporales', ActivityPhotoUploadController::class)->middleware('throttle:60,1')->name('projects.photo-upload');
    Route::post('/proyectos/{project}/personal', [KardexController::class, 'members'])->name('projects.members');
    Route::post('/proyectos/{project}/plantillas', [KardexController::class, 'template'])->name('templates.store');
    Route::get('/proyectos/{project}/plantillas/{template}/editar', [KardexController::class, 'editTemplate'])->name('templates.edit');
    Route::patch('/proyectos/{project}/plantillas/{template}', [KardexController::class, 'template'])->name('templates.update');
    Route::post('/proyectos/{project}/copiar-plantilla', [KardexController::class, 'copyTemplate'])->name('templates.copy');
    Route::get('/proyectos/{project}/equipos/nuevo', [KardexController::class, 'assetForm'])->name('assets.create');
    Route::post('/proyectos/{project}/equipos', [KardexController::class, 'asset'])->name('assets.store');
    Route::post('/equipos-proyecto/{asset}/intervenciones', [KardexController::class, 'createIntervention'])->name('interventions.store');
    Route::get('/intervenciones/{intervention}', [KardexController::class, 'intervention'])->name('interventions.show');
    Route::patch('/intervenciones/{intervention}', [KardexController::class, 'save'])->name('interventions.update');
    Route::post('/intervenciones/{intervention}/actividades', [KardexController::class, 'activity'])->name('activities.store');
    Route::patch('/actividades/{activity}', [KardexController::class, 'updateActivity'])->name('activities.update');
    Route::post('/actividades/{activity}/fotos', [KardexController::class, 'photo'])->name('photos.store');
    Route::get('/intervenciones/{intervention}/fotos', [KardexController::class, 'gallery'])->name('interventions.photos');
    Route::get('/fotos/{photo}/optimizada', [KardexController::class, 'optimizedPhoto'])->name('photos.optimized');
    Route::get('/fotos/{photo}/miniatura', [KardexController::class, 'thumbnail'])->name('photos.thumbnail');
    Route::get('/fotos/{photo}', [KardexController::class, 'showPhoto'])->name('photos.show');
    Route::post('/intervenciones/{intervention}/estado', [KardexController::class, 'transition'])->name('interventions.transition');
    Route::get('/intervenciones/{intervention}/imprimir', [KardexController::class, 'print'])->name('interventions.print');
});
