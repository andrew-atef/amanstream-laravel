<?php

namespace App\Services;

/**
 * Smart semantic diff engine for deep scrape payloads.
 *
 * Compares a freshly scraped Amazon payload against the previously stored
 * `deep_data_json` snapshot and produces stable, human-readable Arabic change
 * descriptions grouped by category — the exact shape the Filament admin alert
 * and the `spec_diff_json` column consume:
 *
 *     [{"category": "الخدمات والتركيب", "change": "تغيّر سعر التركيب من 500 إلى 547.2 ج.م"}]
 */
class DeepScrapeDiffService
{
    /**
     * Arabic category labels used in every emitted diff entry.
     */
    private const CATEGORY_LABELS = [
        'pricing' => 'السعر',
        'availability' => 'التوفر',
        'quick_specs' => 'المواصفات السريعة',
        'detailed_specifications' => 'المواصفات التفصيلية',
        'warranty_addons' => 'عروض الضمان الإضافية',
        'additional_services' => 'الخدمات والتركيب',
        'about_this_item' => 'نبذة عن هذا المنتج',
        'product_description' => 'وصف المنتج',
    ];

    /**
     * Row subject keys treated as the stable identity of a spec row, so a row
     * whose value changed is matched against its previous instance instead of
     * being reported as removed + added.
     */
    private const IDENTITY_KEYS = ['name', 'label', 'feature', 'title', 'بيان', 'البند', 'العنصر', 'الميزة', 'خاصية', 'اسم'];

    /**
     * Spec fields that describe the payload detail of a row rather than the
     * row itself; only their value changes are diffed against the row subject.
     */
    private const VALUE_FIELDS = ['value', 'detail', 'details', 'القيمة', 'التفاصيل'];

    /**
     * Detect every semantically significant difference between the previously
     * stored payload and the freshly scraped one.
     *
     * @param  array<string, mixed>|null  $old  Previously stored `deep_data_json`.
     * @param  array<string, mixed>  $new  Freshly submitted scraper payload.
     *
     * @return array<int, array{category: string, change: string}>
     */
    public function diff(?array $old, array $new): array
    {
        $old ??= [];

        // The first captured payload is the baseline, not a diff: every field
        // would otherwise report "changed from غير محدد", drowning the admin
        // alert in noise on the very first deep scrape.
        if ($old === []) {
            return [];
        }

        $diffs = array_merge(
            $this->diffPricing($old['pricing'] ?? null, $new['pricing'] ?? null),
            $this->diffAvailability($old['availability'] ?? null, $new['availability'] ?? null)
        );

        foreach (['warranty_addons', 'additional_services', 'quick_specs', 'detailed_specifications'] as $group) {
            $diffs = array_merge($diffs, $this->diffItemList($group, $old[$group] ?? null, $new[$group] ?? null));
        }

        foreach (['about_this_item', 'product_description'] as $group) {
            $diffs = array_merge($diffs, $this->diffTextual($group, $old[$group] ?? null, $new[$group] ?? null));
        }

        return $diffs;
    }

    /**
     * @return array<int, array{category: string, change: string}>
     */
    private function diffPricing(mixed $old, mixed $new): array
    {
        if (! is_array($new)) {
            return [];
        }

        $old = is_array($old) ? $old : [];
        $diffs = [];

        foreach ([
            'live_price' => 'السعر الحالي',
            'list_price' => 'السعر القديم المشطوب',
        ] as $key => $label) {
            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            if (! $this->looselyEquals($oldValue, $newValue)) {
                $diffs[] = $this->entry(
                    'pricing',
                    sprintf(
                        'تغيّر %s من %s إلى %s',
                        $label,
                        $this->formatValue($oldValue, $key),
                        $this->formatValue($newValue, $key)
                    )
                );
            }
        }

        return $diffs;
    }

    /**
     * @return array<int, array{category: string, change: string}>
     */
    private function diffAvailability(mixed $old, mixed $new): array
    {
        if (! is_array($new) || ! array_key_exists('in_stock', $new)) {
            return [];
        }

        $old = is_array($old) ? $old : [];

        if ($this->looselyEquals($old['in_stock'] ?? null, $new['in_stock'])) {
            return [];
        }

        return [
            $this->entry(
                'availability',
                sprintf(
                    'تغيّر التوفر من %s إلى %s',
                    $this->formatValue($old['in_stock'] ?? null),
                    $this->formatValue($new['in_stock'])
                )
            ),
        ];
    }

    /**
     * Diff structured spec rows (warranty add-ons, installation services,
     * quick/detailed specs) with identity-based row matching then per-field
     * comparison, plus added/removed row reports.
     *
     * @return array<int, array{category: string, change: string}>
     */
    private function diffItemList(string $group, mixed $oldItems, mixed $newItems): array
    {
        $oldRows = $this->asList($oldItems);
        $newRows = $this->asList($newItems);

        if ($oldRows === [] && $newRows === []) {
            return [];
        }

        $identityKey = $this->detectIdentityKey($oldRows, $newRows);
        $paired = $this->matchRows($oldRows, $newRows, $identityKey);

        $diffs = [];

        foreach ($paired as $newIndex => $oldIndex) {
            $oldRow = $oldRows[$oldIndex];
            $newRow = $newRows[$newIndex];

            if (is_array($oldRow) && is_array($newRow)) {
                $diffs = array_merge($diffs, $this->diffRowFields($group, $oldRow, $newRow, $identityKey));
            }
        }

        $matchedOld = array_values($paired);
        foreach ($oldRows as $index => $oldRow) {
            if (in_array($index, $matchedOld, true)) {
                continue;
            }

            $diffs[] = $this->entry($group, 'تمت إزالة '.$this->describeItem($oldRow));
        }

        foreach ($newRows as $index => $newRow) {
            if (array_key_exists($index, $paired)) {
                continue;
            }

            $diffs[] = $this->entry($group, 'تمت إضافة '.$this->describeItem($newRow));
        }

        return $diffs;
    }

    /**
     * Diff a text group: bullet lists (about_this_item) go through row
     * matching; a single long string (product_description) gets one entry.
     *
     * @return array<int, array{category: string, change: string}>
     */
    private function diffTextual(string $group, mixed $old, mixed $new): array
    {
        if ($group === 'about_this_item') {
            return $this->diffItemList($group, $old, $new);
        }

        if ($this->looselyEquals($old, $new)) {
            return [];
        }

        return [$this->entry($group, 'تغيّر محتوى وصف المنتج')];
    }

    /**
     * Field-by-field comparison of two spec rows that share the same identity.
     *
     * @return array<int, array{category: string, change: string}>
     */
    private function diffRowFields(string $group, array $oldRow, array $newRow, ?string $identityKey): array
    {
        $diffs = [];
        $subject = $this->rowName($oldRow, $identityKey);

        foreach ($this->mergedFieldNames($oldRow, $newRow) as $field) {
            if ($identityKey !== null && $field === $identityKey) {
                continue;
            }

            $oldValue = $oldRow[$field] ?? null;
            $newValue = $newRow[$field] ?? null;

            if ($this->looselyEquals($oldValue, $newValue)) {
                continue;
            }

            $label = in_array($field, self::VALUE_FIELDS, true)
                ? $subject
                : $subject.' — '.$this->translateField($field);

            $diffs[] = $this->entry(
                $group,
                sprintf(
                    'تغيّر %s من %s إلى %s',
                    $label,
                    $this->formatValue($oldValue, (string) $field),
                    $this->formatValue($newValue, (string) $field)
                )
            );
        }

        return $diffs;
    }

    /**
     * @param  array<int, mixed>  $oldRows
     * @param  array<int, mixed>  $newRows
     *
     * @return array<int, int> New-row index → old-row index pairs.
     */
    private function matchRows(array $oldRows, array $newRows, ?string $identityKey): array
    {
        $oldByRowId = [];

        foreach ($oldRows as $index => $row) {
            $oldByRowId[$this->rowIdentityKey($row, $identityKey)][] = $index;
        }

        $usedOld = [];
        $paired = [];

        foreach ($newRows as $index => $row) {
            $rowId = $this->rowIdentityKey($row, $identityKey);
            $candidates = $oldByRowId[$rowId] ?? [];

            foreach ($candidates as $oldIndex) {
                if (isset($usedOld[$oldIndex])) {
                    continue;
                }

                $usedOld[$oldIndex] = true;
                $paired[$index] = $oldIndex;

                break;
            }
        }

        return $paired;
    }

    /**
     * Pick the most stable identity key shared by the spec rows, so value-only
     * updates stay matched to their previous row.
     *
     * @param  array<int, mixed>  $oldRows
     * @param  array<int, mixed>  $newRows
     */
    private function detectIdentityKey(array $oldRows, array $newRows): ?string
    {
        foreach (self::IDENTITY_KEYS as $key) {
            foreach ([$oldRows, $newRows] as $rows) {
                foreach ($rows as $row) {
                    if (is_array($row) && array_key_exists($key, $row)) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Canonical identity for the row matching algorithm: label-style keys win,
     * otherwise the whole normalized row (scalars and arrays alike).
     */
    private function rowIdentityKey(mixed $row, ?string $identityKey): string
    {
        if (is_array($row)) {
            $keys = array_merge([$identityKey], self::IDENTITY_KEYS);

            foreach ($keys as $key) {
                if ($key !== null && array_key_exists($key, $row)) {
                    return $this->canonicalIdentity($row[$key]);
                }
            }

            return $this->canonicalIdentity($row);
        }

        return $this->canonicalIdentity($row);
    }

    /**
     * The stable human subject of a row: its identity value, else the first
     * label-ish key, else a generic fallback.
     */
    private function rowName(array $row, ?string $identityKey): string
    {
        $keys = array_merge([$identityKey], self::IDENTITY_KEYS);

        foreach ($keys as $key) {
            if ($key !== null && filled($row[$key] ?? null)) {
                return (string) $row[$key];
            }
        }

        return 'البند';
    }

    /**
     * @return array<int, string>
     */
    private function mergedFieldNames(array $oldRow, array $newRow): array
    {
        return array_values(array_unique(array_merge(array_keys($oldRow), array_keys($newRow))));
    }

    /**
     * Wrap assorted scraper list shapes into a plain list of rows.
     *
     * @return array<int, mixed>
     */
    private function asList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [$value];
    }

    /**
     * Human description of an added/removed item.
     */
    private function describeItem(mixed $item): string
    {
        if (is_array($item)) {
            $name = $this->rowName($item, null);

            if ($name !== 'البند') {
                return $this->quoted($name);
            }

            foreach ($item as $value) {
                if (is_scalar($value) || $value === null) {
                    return $this->quoted($this->trimText((string) $value, 80));
                }
            }

            return 'بند جديد';
        }

        return $this->quoted($this->formatValue($item));
    }

    /**
     * Compare two mixed values with canonical normalization so "1,5" vs
     * "1.50" and "متوفر" vs " متوفر " never produce false diffs.
     */
    private function looselyEquals(mixed $a, mixed $b): bool
    {
        return $this->canonical($a) === $this->canonical($b);
    }

    /**
     * Canonical comparable form: numbers rounded to cents, booleans as-is,
     * strings trimmed/lowercased, arrays JSON-serialized after normalization.
     */
    private function canonical(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($this->normalize($value), JSON_UNESCAPED_UNICODE) ?: null;
        }

        return mb_strtolower(trim((string) $value));
    }

    /**
     * Identity used by the row matcher — a compact stable string.
     */
    private function canonicalIdentity(mixed $value): string
    {
        $canonical = $this->canonical($value);

        if (is_bool($canonical)) {
            return $canonical ? '1' : '0';
        }

        return (string) ($canonical ?? 'null');
    }

    /**
     * Recursively normalize a nested value for canonical comparison.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->normalize($item);
            }

            ksort($normalized);

            return $normalized;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (is_bool($value)) {
            return $value;
        }

        return trim((string) $value);
    }

    /**
     * README-friendly display of a value, with EGP currency tagged onto price
     * fields and Arabic availability labels for booleans.
     */
    private function formatValue(mixed $value, string $key = ''): string
    {
        if ($value === null) {
            return 'غير محدد';
        }

        if ($value === true) {
            return 'متوفر';
        }

        if ($value === false) {
            return 'غير متوفر';
        }

        if (is_numeric($value)) {
            $number = number_format((float) $value, 2, '.', ',');
            $number = trim(rtrim(rtrim($number, '0'), '.'));

            return $this->isPriceField($key) ? $number.' ج.م' : $number;
        }

        if (is_array($value)) {
            return 'قائمة بيانات';
        }

        return $this->trimText(trim((string) $value), 120);
    }

    private function isPriceField(string $key): bool
    {
        return str_contains($key, 'price')
            || str_contains($key, 'Price')
            || str_contains($key, 'سعر');
    }

    /**
     * Translate common scraper field names to natural Arabic labels for the
     * diff messages; unknown fields keep their original name.
     */
    private function translateField(string $field): string
    {
        return match ($field) {
            'price' => 'السعر',
            'duration' => 'المدة',
            'months' => 'عدد الأشهر',
            'quantity' => 'الكمية',
            'warranty' => 'الضمان',
            'installation' => 'التركيب',
            'detail' => 'التفاصيل',
            default => $field,
        };
    }

    private function trimText(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit).'…';
    }

    private function quoted(string $text): string
    {
        return '«'.$text.'»';
    }

    /**
     * @return array{category: string, change: string}
     */
    private function entry(string $group, string $change): array
    {
        return [
            'category' => self::CATEGORY_LABELS[$group] ?? $group,
            'change' => $change,
        ];
    }
}