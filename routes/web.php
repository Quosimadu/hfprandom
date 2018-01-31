<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('csv')->group(function () {
    Route::get('', 'CsvController@index')->name('csvForm');
    Route::post('reformat', 'CsvController@reformat')->name('csvReformat');
});