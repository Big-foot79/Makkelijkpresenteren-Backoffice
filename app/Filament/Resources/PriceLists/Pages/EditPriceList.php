<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Resources\PriceLists\PriceListResource;
use App\Jobs\ImportPriceList;
use App\Models\PriceList;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPriceList extends EditRecord
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(fn (PriceList $record) => $record->status === 'imported' ? 'Opnieuw inlezen' : 'Inlezen')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    ImportPriceList::dispatch($this->record->id);

                    Notification::make()->title('Inlezen gestart')->body('Volg de voortgang onder Werk → Runs; ververs daarna deze pagina.')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
