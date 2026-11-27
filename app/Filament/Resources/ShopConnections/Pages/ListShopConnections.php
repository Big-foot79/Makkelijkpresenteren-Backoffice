<?php

namespace App\Filament\Resources\ShopConnections\Pages;

use App\Filament\Resources\ShopConnections\ShopConnectionResource;
use App\Models\ShopConnection;
use Filament\Resources\Pages\ListRecords;

/** There is one connection: go straight to it. */
class ListShopConnections extends ListRecords
{
    protected static string $resource = ShopConnectionResource::class;

    public function mount(): void
    {
        if ($connection = ShopConnection::current()) {
            $this->redirect(ShopConnectionResource::getUrl('edit', ['record' => $connection]));

            return;
        }

        parent::mount();
    }
}
