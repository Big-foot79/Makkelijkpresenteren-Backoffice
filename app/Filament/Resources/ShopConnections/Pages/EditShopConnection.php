<?php

namespace App\Filament\Resources\ShopConnections\Pages;

use App\Filament\Resources\ShopConnections\ShopConnectionResource;
use App\Filament\Resources\WooProducts\WooProductResource;
use App\Jobs\RefreshShopProducts;
use App\Jobs\SyncShopProducts;
use App\Models\ShopConnection;
use App\Shop\WooClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class EditShopConnection extends EditRecord
{
    protected static string $resource = ShopConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Verbinding testen')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function (ShopConnection $record) {
                    try {
                        $info = WooClient::make($record)->check();
                        $record->update(['woo_version' => $info['woo_version'], 'last_checked_at' => now()]);

                        Notification::make()
                            ->title('Verbinding gelukt: '.$info['store'])
                            ->body(collect([
                                "WooCommerce {$info['woo_version']}, WordPress {$info['wordpress_version']}",
                                "{$info['products']} producten",
                                $info['gtin_field'] ? 'EAN-veld (GTIN) beschikbaar' : 'Let op: WooCommerce ouder dan 9.2, geen GTIN-veld; EAN wordt uit attribuut of meta gelezen',
                                $info['prices_include_tax'] === 'yes' ? 'Prijzen in de shop zijn inclusief btw' : 'Prijzen in de shop zijn exclusief btw',
                            ])->implode(' · '))
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Verbinding mislukt')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('refresh')
                ->label('Producten ophalen')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->disabled(fn (ShopConnection $record) => ! $record->hasCredentials())
                ->requiresConfirmation()
                ->modalDescription('Leest alle producten uit de webshop en maakt het koppelrapport. Er wordt niets in de webshop veranderd.')
                ->action(function () {
                    RefreshShopProducts::dispatch();
                    Notification::make()->title('Ophalen gestart')->body('Het koppelrapport staat onder Webshop → Koppelrapport zodra de run klaar is.')->success()->send();
                }),
            Action::make('report')
                ->label('Koppelrapport')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->url(WooProductResource::getUrl()),
            Action::make('sync')
                ->label(fn (ShopConnection $record) => $record->canWrite() ? 'Nu bijwerken' : 'Proefrun bijwerken')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->disabled(fn (ShopConnection $record) => ! $record->hasCredentials())
                ->requiresConfirmation()
                ->modalDescription(fn (ShopConnection $record) => $record->canWrite()
                    ? 'Prijs en voorraadstatus van alle gekoppelde producten worden naar de webshop gestuurd (alleen wat verschilt).'
                    : 'Proefrun: laat in de run zien wat er in de webshop zou veranderen, zonder iets te schrijven.')
                ->action(function (ShopConnection $record) {
                    SyncShopProducts::dispatch(null, ! $record->canWrite());
                    Notification::make()->title('Gestart')->body('Volg de voortgang onder Werk → Runs.')->success()->send();
                }),
        ];
    }
}
