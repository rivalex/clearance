{{-- Wraps Clearance's root .clearance scope in an optional forced .dark ancestor.
     Clearance's own CSS build uses a class-based dark variant (&:where(.dark, .dark *)),
     so it normally follows the host app's <html class="dark">. When the host has no
     class-based dark toggle (or the OS preference doesn't match), this lets Clearance's
     dark theme render regardless, via config('clearance.dark_mode.force') or the
     Settings UI toggle. --}}
@php
    use Rivalex\Clearance\Models\ClearanceSettings;

    $forceDark = filter_var(
        ClearanceSettings::get('dark_mode.force', config('clearance.dark_mode.force', false)),
        FILTER_VALIDATE_BOOLEAN
    );
@endphp
<div @class(['dark' => $forceDark])>
    <div {{ $attributes->class(['clearance']) }}>
        {{ $slot }}
    </div>
</div>
