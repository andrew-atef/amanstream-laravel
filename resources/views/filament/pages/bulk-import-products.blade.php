<x-filament-panels::page>
    <x-filament-panels::form wire:submit="import">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit">
                استيراد المنتجات
            </x-filament::button>
        </div>
    </x-filament-panels::form>
</x-filament-panels::page>