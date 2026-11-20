<?php
namespace App\Services;

final class SearchNormalizer
{
    private static ?array $mapping = null;

    public static function tokens(string $token): array
    {
        self::$mapping ??= json_decode(file_get_contents(storage_path('app/factbook/metadata/unicode61.json')), true, flags: JSON_THROW_ON_ERROR);
        $normalized = strtr($token, self::$mapping);
        return preg_split('/ +/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    }
}

