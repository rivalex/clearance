<?php

declare(strict_types=1);

use Rivalex\Clearance\Concerns\HasClearanceLayout;

// --- config('clearance.layout') actually applied to full-page views (bug fix) ---

it('applies config(clearance.layout) to the view when set', function (): void {
    config(['clearance.layout' => 'layouts.custom']);

    $subject = new class {
        use HasClearanceLayout;

        public function call($view)
        {
            return $this->withClearanceLayout($view);
        }
    };

    $view = view('clearance::livewire.dashboard-placeholder');
    $result = $subject->call($view);

    expect($result->layoutConfig->view)->toBe('layouts.custom');
});

it('leaves the view layout untouched when clearance.layout is null', function (): void {
    config(['clearance.layout' => null]);

    $subject = new class {
        use HasClearanceLayout;

        public function call($view)
        {
            return $this->withClearanceLayout($view);
        }
    };

    $view = view('clearance::livewire.dashboard-placeholder');
    $result = $subject->call($view);

    expect(isset($result->layoutConfig))->toBeFalse();
});
