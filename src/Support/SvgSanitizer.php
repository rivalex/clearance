<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Strips unsafe nodes and attributes from SVG markup.
 * Uses DOMDocument - no extra dependency.
 */
class SvgSanitizer
{
    private const ALLOWED_TAGS = [
        'svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon',
        'ellipse', 'defs', 'use', 'title', 'desc', 'lineargradient',
        'radialgradient', 'stop', 'clippath', 'mask', 'symbol',
    ];

    private const ALLOWED_ATTRS = [
        'viewbox', 'xmlns', 'xmlns:xlink', 'fill', 'stroke', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1',
        'x2', 'y2', 'width', 'height', 'points', 'transform', 'opacity', 'class',
        'aria-hidden', 'role', 'focusable', 'id', 'fill-rule', 'fill-opacity',
        'clip-rule', 'clip-path', 'gradientunits', 'gradienttransform',
        'patternunits', 'offset', 'stop-color', 'stop-opacity', 'preserveaspectratio',
        'style',
    ];

    /**
     * Returns value if it is a valid 6-digit hex colour, otherwise 'currentColor'.
     * Use when injecting a stored colour into a CSS style= attribute.
     */
    public static function safeCssColor(?string $color): string
    {
        if ($color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }

        return 'currentColor';
    }

    public static function sanitize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        // Must begin with <svg to be a valid inline SVG
        if (! str_starts_with(strtolower(ltrim($raw)), '<svg')) {
            return null;
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $raw,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        if (! $loaded) {
            return null;
        }

        $svgList = $doc->getElementsByTagName('svg');
        if ($svgList->length === 0) {
            return null;
        }

        $svg = $svgList->item(0);
        self::cleanAttributes($svg);
        self::cleanNode($svg);

        return $doc->saveXML($svg) ?: null;
    }

    private static function cleanNode(DOMNode $node): void
    {
        $toRemove = [];

        foreach ($node->childNodes as $child) {
            // Strip HTML comments — they cannot execute JS but may carry exfiltration payloads.
            if ($child instanceof \DOMComment) {
                $toRemove[] = $child;
                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->localName ?? $child->tagName);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $toRemove[] = $child;
                continue;
            }

            self::cleanAttributes($child);
            self::cleanNode($child);
        }

        foreach ($toRemove as $el) {
            $node->removeChild($el);
        }
    }

    private static function cleanAttributes(DOMElement $el): void
    {
        $remove = [];

        foreach ($el->attributes as $attr) {
            $name  = strtolower($attr->name);
            $value = trim($attr->value);

            // Block all event handlers
            if (str_starts_with($name, 'on')) {
                $remove[] = $attr->name;
                continue;
            }

            // Block href / xlink:href unless it is a same-document fragment
            if (in_array($name, ['href', 'xlink:href'], true)) {
                if (! str_starts_with($value, '#')) {
                    $remove[] = $attr->name;
                }
                continue;
            }

            // Block any javascript: scheme in any attribute
            if (str_starts_with(strtolower($value), 'javascript:')) {
                $remove[] = $attr->name;
                continue;
            }

            // Block CSS-based XSS vectors in style attributes
            if ($name === 'style' && preg_match('/url\s*\(|expression\s*\(|javascript:|behavior\s*:|binding\s*:/i', $value)) {
                $remove[] = $attr->name;
                continue;
            }

            if (! in_array($name, self::ALLOWED_ATTRS, true)) {
                $remove[] = $attr->name;
            }
        }

        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
    }
}
