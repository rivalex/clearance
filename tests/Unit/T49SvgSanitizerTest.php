<?php

declare(strict_types=1);

use Rivalex\Clearance\Support\SvgSanitizer;

// --- safeCssColor ---

it('safeCssColor accepts a valid 6-digit hex color', function (): void {
    expect(SvgSanitizer::safeCssColor('#1a2b3c'))->toBe('#1a2b3c')
        ->and(SvgSanitizer::safeCssColor('#ABCDEF'))->toBe('#ABCDEF');
});

it('safeCssColor falls back to currentColor for null, empty, or invalid input', function (): void {
    expect(SvgSanitizer::safeCssColor(null))->toBe('currentColor')
        ->and(SvgSanitizer::safeCssColor(''))->toBe('currentColor')
        ->and(SvgSanitizer::safeCssColor('#fff'))->toBe('currentColor') // 3-digit shorthand not allowed
        ->and(SvgSanitizer::safeCssColor('red'))->toBe('currentColor')
        ->and(SvgSanitizer::safeCssColor('javascript:alert(1)'))->toBe('currentColor')
        ->and(SvgSanitizer::safeCssColor('#gggggg'))->toBe('currentColor');
});

// --- sanitize: input gating ---

it('sanitize returns null for null or blank input', function (): void {
    expect(SvgSanitizer::sanitize(null))->toBeNull()
        ->and(SvgSanitizer::sanitize(''))->toBeNull()
        ->and(SvgSanitizer::sanitize('   '))->toBeNull();
});

it('sanitize returns null when markup does not start with <svg', function (): void {
    expect(SvgSanitizer::sanitize('<div>not svg</div>'))->toBeNull()
        ->and(SvgSanitizer::sanitize('<script>alert(1)</script><svg></svg>'))->toBeNull();
});

it('sanitize returns null for markup with no svg element after parsing', function (): void {
    expect(SvgSanitizer::sanitize('<svgnotreally></svgnotreally>'))->toBeNull();
});

// --- sanitize: allowed content passes through ---

it('sanitize keeps allowed tags and attributes', function (): void {
    $svg = '<svg viewBox="0 0 24 24" fill="none"><path d="M0 0h24v24H0z" fill="#123456" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->toContain('<svg')
        ->and($result)->toContain('viewbox="0 0 24 24"')
        ->and($result)->toContain('<path')
        ->and($result)->toContain('d="M0 0h24v24H0z"')
        ->and($result)->toContain('fill="#123456"');
});

it('sanitize keeps a same-document fragment href', function (): void {
    $svg = '<svg><use href="#icon-check" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->toContain('href="#icon-check"');
});

// --- sanitize: strips disallowed tags ---

it('sanitize strips script tags nested inside svg', function (): void {
    $svg = '<svg><script>alert(document.cookie)</script><path d="M0 0" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('script')
        ->not->toContain('alert')
        ->and($result)->toContain('<path');
});

it('sanitize strips foreignObject and other disallowed tags', function (): void {
    $svg = '<svg><foreignObject><body onload="alert(1)"></body></foreignObject><circle cx="1" cy="1" r="1" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('foreignObject')
        ->not->toContain('onload')
        ->and($result)->toContain('<circle');
});

it('sanitize strips HTML comments', function (): void {
    $svg = '<svg><!-- leaked-data --><path d="M0 0" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('leaked-data');
});

// --- sanitize: strips disallowed / dangerous attributes ---

it('sanitize strips event handler attributes', function (): void {
    $svg = '<svg><path d="M0 0" onclick="alert(1)" onmouseover="steal()" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('onclick')
        ->not->toContain('onmouseover')
        ->and($result)->toContain('d="M0 0"');
});

it('sanitize strips href that is not a same-document fragment', function (): void {
    $svg = '<svg><use href="https://evil.example/x.svg#icon" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('href=');
});

it('sanitize strips javascript: scheme in any attribute', function (): void {
    $svg = '<svg><a xlink:href="javascript:alert(1)"><path d="M0 0" /></a></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('javascript:');
});

it('sanitize strips CSS-based XSS vectors in style attribute', function (): void {
    $svg = '<svg><path d="M0 0" style="background:url(javascript:alert(1))" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('style=');
});

it('sanitize keeps a safe style attribute', function (): void {
    $svg = '<svg><path d="M0 0" style="fill: #123456;" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->toContain('style="fill: #123456;"');
});

it('sanitize strips attributes not on the allowlist', function (): void {
    $svg = '<svg data-evil="x" foo="bar"><path d="M0 0" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('data-evil')
        ->not->toContain('foo="bar"');
});

it('sanitize ignores non-element child nodes such as text', function (): void {
    $svg = '<svg>  <path d="M0 0" />  </svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->toContain('<path');
});

it('sanitize strips javascript: scheme from a non-href attribute', function (): void {
    $svg = '<svg><path d="M0 0" fill="javascript:alert(1)" /></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('javascript:')
        ->and($result)->toContain('d="M0 0"');
});

it('sanitize recurses into nested groups cleaning attributes at every level', function (): void {
    $svg = '<svg><g onclick="x()"><g><path d="M0 0" onmouseover="y()" /></g></g></svg>';

    $result = SvgSanitizer::sanitize($svg);

    expect($result)->not->toContain('onclick')
        ->not->toContain('onmouseover')
        ->and($result)->toContain('<path');
});
