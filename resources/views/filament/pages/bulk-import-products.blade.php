<x-filament-panels::page>
    <x-filament-panels::form wire:submit="import">
        {{ $this->form }}

        <x-filament-panels::form.actions>
            <x-filament::button type="submit">
                استيراد المنتجات
            </x-filament::button>
        </x-filament-panels::form.actions>
    </x-filament-panels::form>
</x-filament-panels::page>