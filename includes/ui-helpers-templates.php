<?php
/**
 * PHP-side render helpers for static Demographics form markup.
 *
 * renderNumberTile() is the intake form's number field: label (with an
 * optional icon tile), a big input between -/+ buttons, a "Last time" line
 * and a slot for the field's error. The -/+ buttons keep the .stepper-btn /
 * data-stepper-* hooks DemographicsUI.initSteppers() wires up, and the JS
 * fills the [data-last-for] / [data-error-for] slots.
 */

function renderNumberTile(string $fieldId, array $opts = []): string
{
    $label = htmlspecialchars($opts['label'] ?? $fieldId);
    $min = (int) ($opts['min'] ?? 0);
    $max = (int) ($opts['max'] ?? 99999);
    $required = !empty($opts['required']);
    $icon = $opts['icon'] ?? null;
    $color = $opts['color'] ?? 'primary';
    $hint = isset($opts['hint']) ? htmlspecialchars($opts['hint']) : '';

    $iconText = in_array($color, ['secondary', 'warning'], true) ? 'text-dark' : 'text-white';
    $iconHtml = $icon ? "<span class=\"num-tile-icon bg-{$color} {$iconText}\"><i class=\"{$icon}\"></i></span>" : '';
    $requiredMark = $required ? ' <span class="text-danger">*</span>' : '';
    $requiredAttr = $required ? 'required' : '';

    return <<<HTML
    <div class="num-tile" data-tile="{$fieldId}">
        <label class="num-tile-label" for="{$fieldId}">{$iconHtml}<span>{$label}{$requiredMark}</span></label>
        <div class="num-tile-control">
            <button class="num-tile-btn stepper-btn" type="button" data-stepper-target="{$fieldId}" data-stepper-dir="-1" aria-label="Decrease {$label}">
                <i class="ri-subtract-line"></i>
            </button>
            <input type="number" class="form-control num-tile-input" id="{$fieldId}" name="{$fieldId}"
                   min="{$min}" max="{$max}" inputmode="numeric" placeholder="0" {$requiredAttr}>
            <button class="num-tile-btn stepper-btn" type="button" data-stepper-target="{$fieldId}" data-stepper-dir="1" aria-label="Increase {$label}">
                <i class="ri-add-line"></i>
            </button>
        </div>
        <div class="num-tile-foot">
            <span class="num-tile-last" data-last-for="{$fieldId}" data-hint="{$hint}">{$hint}</span>
        </div>
        <div class="num-tile-error" data-error-for="{$fieldId}" role="alert"></div>
    </div>
    HTML;
}
