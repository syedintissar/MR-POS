<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ready-made WhatsApp messages. Nothing is sent by the system: the link opens WhatsApp (phone app or WhatsApp Web) with the
 * text typed in, and the person presses send. It needs internet only at that moment and never blocks the shop's work.
 */
final class WhatsApp
{
    /** The first usable number in international form (Pakistan: 03xx... becomes 923xx...), or null. */
    public static function number(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) $candidate) ?? '';
            if (strlen($digits) < 9) {
                continue;
            }
            if (str_starts_with($digits, '00')) {
                return substr($digits, 2);
            }

            return str_starts_with($digits, '0') ? '92'.substr($digits, 1) : $digits;
        }

        return null;
    }

    /** With no usable number the link still opens WhatsApp, which then asks who to send the text to. */
    public static function link(string $text, ?string ...$candidates): string
    {
        return 'https://wa.me/'.(self::number(...$candidates) ?? '').'?text='.rawurlencode($text);
    }
}
