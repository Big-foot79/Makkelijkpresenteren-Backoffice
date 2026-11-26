<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Models\Supplier;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SupplierForm
{
    /** Settings each feed understands, shown as help text. */
    private const SETTING_HELP = [
        'tdsynnex' => 'cron_catalog, cron_stock, special_date_allocation, special_date_overdue (TD Synnex wijzigt deze nepdatums jaarlijks)',
        'also' => 'cron_catalog, cron_stock, catalog_file, stock_file',
        'travion' => 'cron_full, file_pattern (regex)',
        'copaco' => 'cron_full, file, skip_lines',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Leverancier')
                    ->schema([
                        TextInput::make('name')->label('Naam')->required(),
                        TextInput::make('code')->label('Code')->disabled()->helperText('Bepaalt welk importprofiel wordt gebruikt.'),
                        TextInput::make('account_code')->label('Klant- of accountnummer'),
                        Toggle::make('is_active')
                            ->label('Actief')
                            ->helperText('Alleen actieve leveranciers worden volgens planning ingelezen.'),
                    ]),
                Section::make('Verbinding')
                    ->schema([
                        Select::make('protocol')
                            ->label('Protocol')
                            ->options(['ftp' => 'FTP', 'sftp' => 'SFTP', 'local' => 'Lokale map (test)'])
                            ->live()
                            ->required(),
                        TextInput::make('host')->label('Server'),
                        TextInput::make('port')->label('Poort')->numeric()->placeholder('21 / 22'),
                        TextInput::make('username')->label('Gebruikersnaam'),
                        TextInput::make('password')
                            ->label('Wachtwoord')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->placeholder(fn (?Supplier $record) => filled($record?->password) ? 'Ingesteld (leeg laten om te houden)' : null)
                            ->helperText('Versleuteld opgeslagen. Bij SFTP met sleutel alleen nodig als de server ook een wachtwoord vraagt.'),
                        Textarea::make('private_key')
                            ->label('Private key (SFTP)')
                            ->rows(4)
                            ->visible(fn (Get $get) => $get('protocol') === 'sftp')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->formatStateUsing(fn () => null)
                            ->placeholder(fn (?Supplier $record) => filled($record?->private_key) ? 'Ingesteld (leeg laten om te houden)' : '-----BEGIN OPENSSH PRIVATE KEY----- …')
                            ->helperText('Plak de volledige sleutel (OpenSSH, PEM of PuTTY). Versleuteld opgeslagen.'),
                        TextInput::make('passphrase')
                            ->label('Wachtwoordzin van de sleutel')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get) => $get('protocol') === 'sftp')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->placeholder(fn (?Supplier $record) => filled($record?->passphrase) ? 'Ingesteld (leeg laten om te houden)' : null),
                        TextInput::make('root')->label('Map')->placeholder('/'),
                    ]),
                Section::make('Instellingen')
                    ->columnSpanFull()
                    ->schema([
                        KeyValue::make('settings')
                            ->hiddenLabel()
                            ->keyLabel('Instelling')
                            ->valueLabel('Waarde')
                            ->helperText(fn (?Supplier $record) => 'Bekende instellingen: '
                                .(self::SETTING_HELP[$record?->code] ?? '—')
                                .'. Planning als cron in Nederlandse tijd, bijv. "20 9 * * *" = dagelijks 09:20.'),
                    ]),
            ]);
    }
}
