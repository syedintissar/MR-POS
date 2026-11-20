<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The fonts printed receipts and invoices can use. All of them come with Windows, so printing never needs the internet;
 * each choice falls back to a similar font if one is missing. (PDF downloads always use DejaVu Sans, the font built into the PDF maker.)
 */
final class PrintFont
{
    /** key => [label, CSS font stack] */
    public const FONTS = [
        'consolas' => ['Consolas (typewriter, even columns)', "Consolas, 'Lucida Console', 'Courier New', monospace"],
        'courier' => ['Courier New (classic typewriter)', "'Courier New', Courier, monospace"],
        'lucida_console' => ['Lucida Console (typewriter, darker)', "'Lucida Console', Consolas, monospace"],
        'segoe' => ['Segoe UI (modern, clean)', "'Segoe UI', Tahoma, Arial, sans-serif"],
        'arial' => ['Arial (plain)', 'Arial, Helvetica, sans-serif'],
        'tahoma' => ['Tahoma (narrow, sharp)', 'Tahoma, Verdana, sans-serif'],
        'verdana' => ['Verdana (wide, easy to read)', 'Verdana, Tahoma, sans-serif'],
        'calibri' => ['Calibri (soft, compact)', "Calibri, 'Segoe UI', Arial, sans-serif"],
        'bahnschrift' => ['Bahnschrift (tall, modern)', "Bahnschrift, 'Segoe UI', Arial, sans-serif"],
        'trebuchet' => ['Trebuchet MS (friendly)', "'Trebuchet MS', 'Segoe UI', sans-serif"],
        'franklin' => ['Franklin Gothic (bold headings)', "'Franklin Gothic Medium', 'Arial Narrow', Arial, sans-serif"],
        'georgia' => ['Georgia (elegant serif)', "Georgia, 'Times New Roman', serif"],
        'times' => ['Times New Roman (formal)', "'Times New Roman', Times, serif"],
        'cambria' => ['Cambria (formal, modern serif)', 'Cambria, Georgia, serif'],
        'palatino' => ['Palatino Linotype (classic book)', "'Palatino Linotype', 'Book Antiqua', Palatino, serif"],
        'comic' => ['Comic Sans MS (casual)', "'Comic Sans MS', 'Segoe Print', cursive"],
    ];

    /** @return array<string, string> key => label, for the settings drop-downs */
    public static function options(): array
    {
        return array_map(fn (array $f) => $f[0], self::FONTS);
    }

    public static function stack(?string $key, string $fallback = 'consolas'): string
    {
        return (self::FONTS[$key] ?? self::FONTS[$fallback])[1];
    }

    /** The CSS font-family for thermal receipts (80 / 72 / 58 mm). */
    public static function thermal(): string
    {
        return self::stack(app(SettingsService::class)->string('printing.thermal_font'), 'consolas');
    }

    /** The CSS font-family for A4 invoices, quotations and other A4 prints. */
    public static function a4(): string
    {
        return self::stack(app(SettingsService::class)->string('printing.a4_font'), 'segoe');
    }

    /** "font-weight: 700" when the owner asked for darker (bold) printing, else nothing. */
    public static function weight(): string
    {
        return app(SettingsService::class)->bool('printing.bold_text') ? 'font-weight: 700;' : '';
    }
}
