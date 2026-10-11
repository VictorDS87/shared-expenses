<?php

namespace App\Support;

final class FormInput
{
    public static function oldText(string $key, string $default = ''): string
    {
        $value = old($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function allocationRows(): array
    {
        $rows = old('allocations');
        if (! is_array($rows) || $rows === []) {
            return [['unit_id' => '', 'percentage' => '100']];
        }

        // Invalid requests still return a usable form, with a bounded number of rows.
        return array_map(fn ($row) => [
            'unit_id' => is_scalar(data_get($row, 'unit_id')) ? (string) data_get($row, 'unit_id') : '',
            'percentage' => is_scalar(data_get($row, 'percentage')) ? (string) data_get($row, 'percentage') : '',
        ], array_values(array_slice($rows, 0, 100)));
    }
}
