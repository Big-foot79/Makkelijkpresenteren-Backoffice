<?php

namespace App\Filament\Resources\ShopConnections;

use App\Filament\Resources\ShopConnections\Pages\EditShopConnection;
use App\Filament\Resources\ShopConnections\Pages\ListShopConnections;
use App\Models\ShopConnection;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class ShopConnectionResource extends Resource
{
    protected static ?string $model = ShopConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Systeem';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'webshopverbinding';

    protected static ?string $pluralModelLabel = 'webshopverbinding';

    protected static ?string $navigationLabel = 'Webshop';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $secret = fn (string $field) => TextInput::make($field)
            ->password()
            ->revealable()
            ->dehydrated(fn (?string $state) => filled($state))
            ->formatStateUsing(fn () => null)
            ->placeholder(fn (?ShopConnection $record) => filled($record?->{$field}) ? 'Ingesteld (leeg laten om te houden)' : null);

        return $schema
            ->columns(2)
            ->components([
                Section::make('WooCommerce')
                    ->description('Maak in WordPress een REST API-sleutel aan: WooCommerce → Instellingen → Geavanceerd → REST API, rechten "Lezen/Schrijven".')
                    ->schema([
                        TextInput::make('name')->label('Naam')->required(),
                        TextInput::make('url')
                            ->label('Webshop-URL')
                            ->url()
                            ->required()
                            ->startsWith(['https://'])
                            ->placeholder('https://makkelijkpresenteren.nl'),
                        $secret('consumer_key')->label('Consumer key')->helperText('Begint met ck_. Versleuteld opgeslagen.'),
                        $secret('consumer_secret')->label('Consumer secret')->helperText('Begint met cs_. Versleuteld opgeslagen.'),
                        Toggle::make('is_active')
                            ->label('Schrijven naar de webshop')
                            ->helperText('Uit: de backoffice leest alleen en proefruns laten zien wat er zou veranderen. Aan: prijs en voorraad worden bijgewerkt na elke import en elke nacht.'),
                    ]),
                Section::make('Webhooks')
                    ->description('Zo hoort de backoffice direct als er in de webshop iets verandert.')
                    ->schema([
                        TextEntry::make('webhook_url')
                            ->label('Bezorg-URL')
                            ->state(fn (?ShopConnection $record) => $record?->webhookUrl() ?? route('webhooks.woocommerce'))
                            ->fontFamily('mono')
                            ->copyable(),
                        TextEntry::make('webhook_help')
                            ->hiddenLabel()
                            ->state(new HtmlString('Maak in WooCommerce → Instellingen → Geavanceerd → Webhooks vier webhooks aan met deze URL en hetzelfde geheim: <b>Product bijgewerkt</b>, <b>Product aangemaakt</b>, <b>Product verwijderd</b> en <b>Bestelling aangemaakt</b>. API-versie: WP REST API v3.')),
                        $secret('webhook_secret')->label('Webhookgeheim')->helperText('Kies zelf een lange willekeurige tekst en vul die in WooCommerce bij "Geheim" in.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Naam'),
                TextColumn::make('url')->label('URL'),
                IconColumn::make('is_active')->label('Schrijft naar webshop')->boolean(),
                TextColumn::make('woo_version')->label('WooCommerce')->placeholder('—'),
                TextColumn::make('last_checked_at')->label('Laatst getest')->since()->placeholder('nooit'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShopConnections::route('/'),
            'edit' => EditShopConnection::route('/{record}/edit'),
        ];
    }
}
