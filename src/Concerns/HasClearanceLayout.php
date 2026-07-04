<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Concerns;

use Illuminate\View\View;

/**
 * Applies the configurable `clearance.layout` to full-page Livewire components.
 *
 * Livewire only wraps a rendered view in a layout when ->layout() is explicitly
 * called on it; otherwise it silently falls back to config('livewire.component_layout').
 * This trait bridges that gap so the package's own 'layout' config key takes effect.
 */
trait HasClearanceLayout
{
    /**
     * Applies config('clearance.layout') to the given view, when set.
     * Leaves the view untouched when null, so Livewire's own default layout applies.
     */
    protected function withClearanceLayout(View $view): View
    {
        $layout = config('clearance.layout');

        return $layout ? $view->layout($layout) : $view;
    }
}
