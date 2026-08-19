<?php

namespace App\Services;

/**
 * Editorial qualitative spec diff engine for deep scrape payloads.
 *
 * Compares a freshly scraped Amazon payload against the previously stored
 * `deep_specs_json` snapshot and produces stable, human-readable Arabic change
 * descriptions grouped by section — the exact shape the Filament admin alert
 * and the `spec_diff_json` column consume:
 *
 *     [{"section": "الضمان", "change": "تغيّرت مدة الضمان من 3 سنوات إلى 5 سنوات"}]
 *
 * Do NOT put pricing/availability here: live prices and stock are owned by the
 * separate daily catalog sync pipeline. This engine only touches qualitative
 * editorial facts (warranty, installation, spec tables, A+ content, bullets,
 * description).
 */
class DeepScrapeDiffService
{
    /**
     * Arabic section labels used in every emitted diff entry.
     */
    private const SECTION_LABELS = [
        'warranty_programs' => 'الضمان',
        'installation_services' => 'خدمات التركيب',
        'quick_specs' => 'المواصفات السريعة',
        'about_this_item' => 'نبذة عن هذا المنتج',
        'technical_details' => 'التفاصيل الفنية',
        'manufacturer_content' => 'محتوى الشركة المصنعة',
        'product_description' => 'وصف المنتج',
    ];

    /**
     * Spec groups whose rows are structured items (warranty plans, install
     * services, quick spec rows, feature bullets, technical table rows).
     */
    private const ITEM_LIST_GROUPS = [
        'warranty_programs',
        'installation_services',
        'quick_specs',
        'about_this_item',
        'technical_details',
    ];

    /**
     * Spec groups that are a single free-form block of text.
     */
    private const TEXT_GROUPS = [
        'manufacturer_content',
        'product_description',
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
     * stored editorial snapshot and the freshly scraped one.
     *
     * @param  array<string, mixed>|null  $old  Previously stored `deep_specs_json`.
     * @param  array<string, mixed>  $new  Freshly submitted editorial payload.
     * @return array<int, array{section: string, change: string, old?: mixed, new?: mixed}>
     */
    public function diff(mixed $old, array $new): array
    {
        $old = is_array($old) ? $old : [];

        // The first captured payload is the baseline, not a diff: every field
        // would otherwise report "changed from غير محدد", drowning the admin
        // alert in noise on the very first deep scrape.
        if ($old === []) {
            return [];
        }

        $diffs = [];

        foreach (self::ITEM_LIST_GROUPS as $group) {
            $diffs = array_merge($diffs, $this->diffItemList($group, $old[$group] ?? null, $new[$group] ?? null));
        }

        foreach (self::TEXT_GROUPS as $group) {
            $diffs = array_merge($diffs, $this->diffTextual($group, $old[$group] ?? null, $new[$group] ?? null));
        }

        return $diffs;
    }

    /**
     * Diff structured editorial rows (warranty plans, installation services,
     * quick specs, technical table rows) with identity-based row matching then
     * per-field comparison, plus added/removed row reports.
     *
     * @return array<int, array{section: string, change: string, old?: mixed, new?: mixed}>
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

            $diffs[] = $this->entry($group, 'تمت إزالة '.$this->describeItem($oldRow), $this->describeItem($oldRow), null);
        }

        foreach ($newRows as $index => $newRow) {
            if (array_key_exists($index, $paired)) {
                continue;
            }

            $diffs[] = $this->entry($group, 'تمت إضافة '.$this->describeItem($newRow), null, $this->describeItem($newRow));
        }

        return $diffs;
    }

    /**
     * Diff a single free-form text block (manufacturer A+ content or product
     * description) into one entry when the canonical text differs.
     *
     * @return array<int, array{section: string, change: string, old?: mixed, new?: mixed}>
     */
    private function diffTextual(string $group, mixed $old, mixed $new): array
    {
        if ($this->looselyEquals($old, $new)) {
            return [];
        }

        return [
            $this->entry(
                $group,
                sprintf(
                    'تغيّر %s%s',
                    $group === 'product_description' ? 'محتوى ' : '',
                    self::SECTION_LABELS[$group]
                ),
                $this->formatValue($old),
                $this->formatValue($new)
            ),
        ];
    }

    /**
     * Field-by-field comparison of two editorial rows that share the same identity.
     *
     * @return array<int, array{section: string, change: string, old?: mixed, new?: mixed}>
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
                ),
                $this->displayValue($oldValue, (string) $field),
                $this->displayValue($newValue, (string) $field)
            );
        }

        return $diffs;
    }

    /**
     * @param  array<int, mixed>  $oldRows
     * @param  array<int, mixed>  $newRows
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
     * Pick the most stable identity key shared by the editorial rows, so
     * value-only updates stay matched to their previous row.
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
     * README-friendly display of a value inside a change message, with EGP
     * currency tagged onto add-on price fields (warranty/installation fees).
     */
    private function formatValue(mixed $value, string $key = ''): string
    {
        if ($value === null) {
            return 'غير محدد';
        }

        if ($value === true) {
            return 'متاح';
        }

        if ($value === false) {
            return 'غير متاح';
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

    /**
     * Structured old/new display values stored alongside the change message.
     */
    private function displayValue(mixed $value, string $key = ''): string
    {
        return $this->formatValue($value, $key);
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
            'terms' => 'الشروط',
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
     * @return array{section: string, change: string, old?: mixed, new?: mixed}
     */
    private function entry(string $group, string $change, mixed $old = null, mixed $new = null): array
    {
        return array_filter([
            'section' => self::SECTION_LABELS[$group] ?? $group,
            'change' => $change,
            'old' => $old,
            'new' => $new,
        ], fn (mixed $value): bool => $value !== null);
    }
}
