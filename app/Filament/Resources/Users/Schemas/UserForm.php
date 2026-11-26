<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Account')
                    ->schema([
                        TextInput::make('name')
                            ->label('Naam')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mailadres')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),
                        Toggle::make('is_active')
                            ->label('Actief')
                            ->helperText('Uit = kan niet meer inloggen; bij een AI-agent werken de tokens dan niet meer.')
                            ->default(true),
                        TextInput::make('password')
                            ->label('Wachtwoord')
                            ->password()
                            ->revealable()
                            ->rule(Password::min(10))
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'Leeg laten om het huidige wachtwoord te houden.'
                                : 'Minimaal 10 tekens.'),
                    ]),
                Section::make('Rol')
                    ->schema([
                        Radio::make('role')
                            ->hiddenLabel()
                            ->options(collect(UserRole::cases())->mapWithKeys(fn (UserRole $r) => [$r->value => $r->getLabel()]))
                            ->descriptions(collect(UserRole::cases())->mapWithKeys(fn (UserRole $r) => [$r->value => $r->description()]))
                            ->default(UserRole::Staff->value)
                            ->required()
                            // The last administrator cannot be demoted, so the panel always has someone in charge.
                            ->disableOptionWhen(fn (string $value, ?User $record) => $record?->isAdmin()
                                && $value !== UserRole::Admin->value
                                && User::where('role', UserRole::Admin)->count() <= 1),
                    ]),
            ]);
    }
}
