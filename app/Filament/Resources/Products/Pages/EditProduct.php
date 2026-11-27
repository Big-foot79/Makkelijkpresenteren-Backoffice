<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Jobs\SyncShopProducts;
use App\Models\ShopConnection;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** A changed price goes to the shop right away. */
    protected function afterSave(): void
    {
        if ($this->record->woo_id && $this->record->wasChanged(['webshop_price', 'sale_price']) && ShopConnection::current()?->canWrite()) {
            SyncShopProducts::dispatch([$this->record->id]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('view', ['record' => $this->record]);
    }
}
