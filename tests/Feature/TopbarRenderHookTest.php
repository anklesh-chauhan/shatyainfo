<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;

it('renders the authenticated topbar hook without mounting the removed check in widget', function (): void {
    $this->actingAs(new User(['name' => 'Representative', 'email' => 'rep@example.test']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $html = FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER)->toHtml();

    expect($html)->not->toContain('check-in-widget');
    expect(Filament::getCurrentPanel()->hasTopbar())->toBeTrue();
});
