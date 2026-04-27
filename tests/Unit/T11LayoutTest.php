<?php

declare(strict_types=1);

it('clearance::layouts.app view exists and is resolvable', function (): void {
    expect(view()->exists('clearance::layouts.app'))->toBeTrue();
});

it('layout file contains required structural elements', function (): void {
    $path = realpath(__DIR__.'/../../resources/views/layouts/app.blade.php');

    expect($path)->not->toBeFalse();

    $content = file_get_contents($path);

    expect($content)->toContain('<!DOCTYPE html>');
    expect($content)->toContain('{{ $slot }}');
    expect($content)->toContain('@livewireStyles');
    expect($content)->toContain('@livewireScripts');
});

it('layout does not extend host app layout (self-contained)', function (): void {
    $path = realpath(__DIR__.'/../../resources/views/layouts/app.blade.php');
    $content = file_get_contents($path);

    expect($content)->not->toContain("@extends('layouts.");
    expect($content)->not->toContain('@extends("layouts.');
});
