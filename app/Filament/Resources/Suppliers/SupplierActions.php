<?php

namespace App\Filament\Resources\Suppliers;

use App\Jobs\ImportSupplierFeed;
use App\Models\Supplier;
use App\Suppliers\Feeds\Feed;
use App\Suppliers\Files\RemoteFiles;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/** Buttons shared by the supplier list and edit page. */
class SupplierActions
{
    /** One "import now" button per kind; only the kinds this supplier's feed offers are shown. */
    public static function imports(): ActionGroup
    {
        return ActionGroup::make(
            collect(Feed::KIND_LABELS)->map(fn (string $label, string $kind) => Action::make('import_'.$kind)
                ->label(ucfirst($label).' nu importeren')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (Supplier $record) => in_array($kind, $record->feed()->kinds(), true))
                ->requiresConfirmation()
                ->modalDescription('De import draait op de achtergrond; de voortgang staat onder Runs.')
                ->action(function (Supplier $record) use ($kind, $label) {
                    ImportSupplierFeed::dispatch($record->id, $kind);

                    Notification::make()
                        ->title("{$record->name}: {$label} gestart")
                        ->body('Volg de voortgang onder Werk → Runs.')
                        ->success()
                        ->send();
                }))->values()->all()
        )
            ->label('Importeren')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->button();
    }

    public static function testConnection(): Action
    {
        return Action::make('test_connection')
            ->label('Verbinding testen')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (Supplier $record) {
                try {
                    $files = (new RemoteFiles($record))->listing();

                    Notification::make()
                        ->title('Verbinding gelukt')
                        ->body(count($files).' bestanden gevonden: '.collect($files)->map(fn ($f) => basename($f))->take(8)->implode(', '))
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Verbinding mislukt')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }
}
