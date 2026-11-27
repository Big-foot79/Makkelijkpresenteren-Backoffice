<?php

namespace App\Filament\Resources\WooProducts\Pages;

use App\Filament\Resources\WooProducts\WooProductResource;
use App\Jobs\LinkShopProducts;
use App\Jobs\RefreshShopProducts;
use App\Models\ShopConnection;
use App\Models\WooProduct;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListWooProducts extends ListRecords
{
    protected static string $resource = WooProductResource::class;

    public function getSubheading(): ?string
    {
        $report = ShopConnection::current()?->last_report;

        if (! $report) {
            return 'Nog niet opgehaald. Vul eerst de API-sleutel in onder Systeem → Webshop en kies "Producten ophalen".';
        }

        return sprintf(
            'Laatst opgehaald %s. %d producten, waarvan %d met een EAN die ook bij een leverancier voorkomt.',
            isset($report['fetched_at']) ? Carbon::parse($report['fetched_at'])->timezone('Europe/Amsterdam')->format('d-m-Y H:i') : '—',
            $report['total'] ?? 0,
            $report['ok_with_supplier'] ?? 0,
        );
    }

    public function getTabs(): array
    {
        $counts = WooProduct::query()->groupBy('match_status')->pluck(DB::raw('count(*)'), 'match_status');
        $tabs = ['all' => Tab::make('Alle')->badge(fn () => $counts->sum() ?: null)];

        foreach (WooProduct::MATCH_STATUSES as $status => $label) {
            if (($counts[$status] ?? 0) > 0) {
                $tabs[$status] = Tab::make($label)
                    ->badge((int) $counts[$status])
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('match_status', $status));
            }
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        $connection = ShopConnection::current();
        $toLink = WooProduct::query()->where('match_status', 'ok')->count();

        return [
            Action::make('refresh')
                ->label('Opnieuw ophalen')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(! $connection?->hasCredentials())
                ->action(function () {
                    RefreshShopProducts::dispatch();
                    Notification::make()->title('Ophalen gestart')->body('Ververs deze pagina als de run onder Werk → Runs klaar is.')->success()->send();
                }),
            Action::make('link')
                ->label("Koppeling uitvoeren ({$toLink})")
                ->icon(Heroicon::OutlinedLink)
                ->disabled($toLink === 0)
                ->requiresConfirmation()
                ->modalHeading('Producten koppelen')
                ->modalDescription("{$toLink} webshopproducten met een unieke EAN worden gekoppeld aan een backofficeproduct. De huidige webshopprijs wordt het startpunt. In de webshop verandert nu nog niets; producten zonder of met dubbele EAN blijven in dit rapport staan.")
                ->modalSubmitActionLabel('Koppelen')
                ->visible(fn () => auth()->user()?->isAdmin())
                ->action(function () {
                    LinkShopProducts::dispatch();
                    Notification::make()->title('Koppelen gestart')->body('Volg de voortgang onder Werk → Runs.')->success()->send();
                }),
        ];
    }
}
