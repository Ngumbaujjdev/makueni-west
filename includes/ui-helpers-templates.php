<?php
/**
 * PHP-side render helpers for static Demographics/Attendance form markup.
 *
 * These generate the same HTML shape as the JS equivalents in
 * assets/js/pages/demographics/ui-helpers.js (numberStepperHtml,
 * renderCompletenessBar) so DemographicsUI.initSteppers()/
 * updateCompletenessBar() work against server-rendered markup at runtime.
 * Used for known, fixed form fields rendered once at page load (values
 * filled in by JS after fetching, for edit mode) - not for dynamic
 * repeating rows, which stay JS-rendered like the rest of this app.
 */

function renderStepper(string $fieldId, array $opts = []): string
{
    $label = $opts['label'] ?? $fieldId;
    $min = $opts['min'] ?? 0;
    $max = $opts['max'] ?? 99999;
    $required = !empty($opts['required']);

    $requiredMark = $required ? ' <span class="text-danger">*</span>' : '';
    $requiredAttr = $required ? 'required' : '';

    return <<<HTML
    <label for="{$fieldId}" class="form-label">{$label}{$requiredMark}</label>
    <div class="input-group stepper-group">
        <button class="btn btn-outline-primary stepper-btn" type="button" data-stepper-target="{$fieldId}" data-stepper-dir="-1">
            <i class="ri-subtract-line"></i>
        </button>
        <input type="number" class="form-control text-center" id="{$fieldId}" name="{$fieldId}"
               min="{$min}" max="{$max}" value="" {$requiredAttr}>
        <button class="btn btn-outline-primary stepper-btn" type="button" data-stepper-target="{$fieldId}" data-stepper-dir="1">
            <i class="ri-add-line"></i>
        </button>
    </div>
    HTML;
}

function renderCompletenessBar(string $containerId): string
{
    return <<<HTML
    <div id="{$containerId}" class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fs-12 fw-semibold text-body">Form completeness</span>
            <span class="fs-12 fw-semibold text-body" data-completeness-label>0%</span>
        </div>
        <div class="progress" style="height: 6px;">
            <div class="progress-bar bg-primary" role="progressbar" style="width: 0%" data-completeness-bar></div>
        </div>
    </div>
    HTML;
}
