<?php

namespace App\Support;

class Phone
{
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (str_starts_with($digits, '254')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '254' . $digits;
        }

        return $digits;
    }

    public static function normalizeWithPlus(?string $phone): string
    {
        return '+' . self::normalize($phone);
    }

    public static function normalizeLocal(?string $phone): string
    {
        $normalized = self::normalize($phone);

        if (str_starts_with($normalized, '254')) {
            return '0' . substr($normalized, 3);
        }

        return $normalized;
    }

    public static function extractFromNarration(string $narration): ?string
    {
        if (preg_match('/(\+?254[1-9]\d{8})/', $narration, $matches)) {
            return self::normalize($matches[1]);
        }

        if (preg_match('/(07\d{8})/', $narration, $matches)) {
            return self::normalize($matches[1]);
        }

        if (preg_match('/(\d{3}[.\-]?\d{3}[.\-]?\d{4})/', $narration, $matches)) {
            $digits = preg_replace('/\D/', '', $matches[1]);
            if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
                return self::normalize($digits);
            }
        }

        if (preg_match('/(\d{9})/', $narration, $matches)) {
            $digits = preg_replace('/\D/', '', $matches[1]);
            if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
                return self::normalize($digits);
            }
        }

        return null;
    }

    public static function findMemberByPhone(string $phone): ?\App\Models\Member
    {
        $normalized = self::normalize($phone);

        return \App\Models\Member::where(function ($query) use ($normalized) {
            $query->where('phone', $normalized)
                  ->orWhere('phone', '+' . $normalized)
                  ->orWhere('phone', '0' . substr($normalized, 3));
        })->first();
    }
}
