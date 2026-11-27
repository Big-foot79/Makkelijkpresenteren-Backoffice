<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Jobs\SyncShopProducts;
use App\Models\Product;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/** The two ways to close a task, shared by the table and the view page. */
class TaskActions
{
    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Afronden')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Task $record) => in_array($record->status, TaskStatus::active(), true))
            ->schema([
                Textarea::make('resolution')->label('Toelichting (optioneel)')->rows(2),
            ])
            ->action(fn (Task $record, array $data) => $record->resolve(TaskStatus::Done, $data['resolution'] ?? null))
            ->successNotificationTitle('Taak afgerond');
    }

    public static function dismiss(): Action
    {
        return Action::make('dismiss')
            ->label('Negeren')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (Task $record) => in_array($record->status, TaskStatus::active(), true))
            ->schema([
                Textarea::make('resolution')->label('Waarom negeren?')->rows(2)->required(),
            ])
            ->action(fn (Task $record, array $data) => $record->resolve(TaskStatus::Dismissed, $data['resolution']))
            ->successNotificationTitle('Taak genegeerd');
    }

    /** Sync conflict: the backoffice is leading, so put its values back in the shop. */
    public static function restoreBackoffice(): Action
    {
        return Action::make('restore_backoffice')
            ->label('Backofficewaarde terugzetten')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('primary')
            ->visible(fn (Task $record) => self::isOpenConflict($record))
            ->requiresConfirmation()
            ->modalDescription('De webshop krijgt weer de prijs en beschikbaarheid uit de backoffice.')
            ->action(function (Task $record) {
                $record->resolve(TaskStatus::Done, 'Backofficewaarde teruggezet in de webshop');
                SyncShopProducts::dispatch([$record->subject_id]);
            })
            ->successNotificationTitle('Wordt teruggezet in de webshop');
    }

    /** Sync conflict: the price was changed in the shop on purpose; take it over. */
    public static function acceptShop(): Action
    {
        return Action::make('accept_shop')
            ->label('Webshopprijs overnemen')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (Task $record) => self::isOpenConflict($record)
                && array_intersect(array_keys($record->data['fields'] ?? []), ['regular_price', 'sale_price']) !== [])
            ->requiresConfirmation()
            ->modalDescription('De prijs uit de webshop wordt de backofficeprijs. Een afwijkende voorraadstatus wordt bij de volgende sync weer gelijkgezet.')
            ->action(function (Task $record) {
                $product = $record->subject;
                $woo = $product?->wooProduct;

                if ($product instanceof Product && $woo) {
                    $product->update(['webshop_price' => $woo->regular_price, 'sale_price' => $woo->sale_price]);
                }

                $record->resolve(TaskStatus::Done, 'Webshopprijs overgenomen');
                SyncShopProducts::dispatch([$record->subject_id]);
            })
            ->successNotificationTitle('Webshopprijs overgenomen');
    }

    private static function isOpenConflict(Task $record): bool
    {
        return $record->kind === TaskKind::SyncConflict
            && $record->subject_type === (new Product)->getMorphClass()
            && in_array($record->status, TaskStatus::active(), true);
    }
}
