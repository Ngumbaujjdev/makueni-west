<?php

namespace App\Enums;

enum DioceseBranding: string
{
    // === LOGO PATH (Single Main Logo) ===
    case MAIN_LOGO = 'assets/images/logos/logo.png';

    // === COLOR PALETTE (from CCI logo) ===
    case PRIMARY_TEAL = '#2CA4BF';
    case SECONDARY_GOLD = '#F2BE22';
    case ACCENT_RED = '#F23535';
    case TEXT_BLACK = '#0D0D0D';
    case BACKGROUND_WHITE = '#FFFFFF';

    // === UTILITY COLORS ===
    case SUCCESS_GREEN = '#28A745';
    case WARNING_AMBER = '#FFC107';
    case DANGER_RED = '#DC3545';
    case INFO_BLUE = '#17A2B8';
    case LIGHT_GRAY = '#F8F9FA';
    case DARK_GRAY = '#6C757D';

    /**
     * Get the full URL for logo
     */
    public function getUrl(): string
    {
        if ($this === self::MAIN_LOGO) {
            return config('app.url') . '/' . $this->value;
        }

        return $this->value; // Return color code as-is
    }

    /**
     * Get asset path for Blade templates
     */
    public function getAssetPath(): string
    {
        if ($this === self::MAIN_LOGO) {
            return asset($this->value);
        }

        return $this->value; // Return color code as-is
    }

    /**
     * Check if enum value is the logo
     */
    public function isLogo(): bool
    {
        return $this === self::MAIN_LOGO;
    }

    /**
     * Check if enum value is a color
     */
    public function isColor(): bool
    {
        return str_starts_with($this->value, '#');
    }

    /**
     * Get primary color palette
     */
    public static function getPrimaryColors(): array
    {
        return [
            'teal' => self::PRIMARY_TEAL,
            'gold' => self::SECONDARY_GOLD,
            'red' => self::ACCENT_RED,
            'black' => self::TEXT_BLACK,
            'white' => self::BACKGROUND_WHITE,
        ];
    }

    /**
     * Get utility colors
     */
    public static function getUtilityColors(): array
    {
        return [
            'success' => self::SUCCESS_GREEN,
            'warning' => self::WARNING_AMBER,
            'danger' => self::DANGER_RED,
            'info' => self::INFO_BLUE,
            'light' => self::LIGHT_GRAY,
            'dark' => self::DARK_GRAY,
        ];
    }

    /**
     * Get CSS custom properties string
     */
    public static function getCssVariables(): string
    {
        $colors = array_merge(self::getPrimaryColors(), self::getUtilityColors());

        $cssVars = ":root {\n";
        foreach ($colors as $name => $color) {
            $cssVars .= "    --diocese-{$name}: {$color->value};\n";
        }
        $cssVars .= "}";

        return $cssVars;
    }

    /**
     * Get color with opacity
     */
    public function withOpacity(float $opacity): string
    {
        if (!$this->isColor()) {
            return $this->value;
        }

        // Convert hex to RGB with opacity
        $hex = ltrim($this->value, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return "rgba($r, $g, $b, $opacity)";
    }

    /**
     * Get display name for admin interface
     */
    public function getDisplayName(): string
    {
        return match($this) {
            self::MAIN_LOGO => 'CCI Diocese Logo',

            self::PRIMARY_TEAL => 'Primary Teal',
            self::SECONDARY_GOLD => 'Secondary Gold',
            self::ACCENT_RED => 'Accent Red',
            self::TEXT_BLACK => 'Text Black',
            self::BACKGROUND_WHITE => 'Background White',

            self::SUCCESS_GREEN => 'Success Green',
            self::WARNING_AMBER => 'Warning Amber',
            self::DANGER_RED => 'Danger Red',
            self::INFO_BLUE => 'Info Blue',
            self::LIGHT_GRAY => 'Light Gray',
            self::DARK_GRAY => 'Dark Gray',
        };
    }
}
