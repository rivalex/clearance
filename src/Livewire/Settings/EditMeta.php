<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Settings;

use Flux\Flux;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Services\MetaService;

/**
 * Modal form for editing display meta (name, description, colour, SVG icon) for a role or guard.
 */
class EditMeta extends Component
{
    #[Locked]
    public string $subjectType;

    #[Locked]
    public string $subjectKey;

    public string $modalName = '';

    public string $displayName = '';

    public string $description = '';

    public string $color = '#3b82f6';

    public string $iconSvg = '';

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->modalName = 'edit-meta-' . $this->subjectType . '-' . $this->subjectKey;

        $meta = ClearanceMeta::forSubject($this->subjectType, $this->subjectKey);

        $this->displayName = $meta->display_name ?? '';
        $this->description = $meta->description ?? '';
        $this->color       = $meta->color ?? '#3b82f6';
        $this->iconSvg     = $meta->icon_svg ?? '';
    }

    public function rules(): array
    {
        return [
            'displayName' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'color'       => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'iconSvg'     => ['nullable', 'string', 'max:8000'],
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'displayName' => __('clearance::ui.settings.meta.display_name'),
            'description' => __('clearance::ui.settings.meta.description'),
            'color'       => __('clearance::ui.settings.meta.color'),
            'iconSvg'     => __('clearance::ui.settings.meta.icon_svg'),
        ];
    }

    public function save(MetaService $metaService): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        $this->errorMessage = null;
        $this->validate();

        $metaService->update($this->subjectType, $this->subjectKey, [
            'display_name' => $this->displayName,
            'description'  => $this->description,
            'color'        => $this->color,
            'icon_svg'     => $this->iconSvg,
        ]);

        Flux::modal($this->modalName)->close();
        $this->dispatch('meta-saved');
    }

    public function render(): View
    {
        return view('clearance::livewire.settings.edit-meta');
    }
}
