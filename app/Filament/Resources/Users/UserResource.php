<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Support\Menu;
use App\Models\User;
use BackedEnum;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Override;

class UserResource extends Resource
{
    #[Override]
    protected static ?string $model = User::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    #[Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[Override]
    protected static ?string $modelLabel = 'Usuario';

    #[Override]
    protected static ?string $pluralModelLabel = 'Usuarios';

    #[Override]
    protected static ?int $navigationSort = 1;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return Menu::ADMINISTRACION;
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * Filtrar la query base: cada usuario solo ve su rama.
     * Super admin ve todos.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();

        return parent::getEloquentQuery()->visibleTo($user);
    }

    /**
     * Verificar si el usuario puede ver un registro específico.
     */
    #[Override]
    public static function canView(Model $record): bool
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole(Utils::getSuperAdminName())) {
            return true;
        }

        return in_array($record->id, $user->getVisibleUserIds());
    }

    /**
     * Verificar si el usuario puede editar un registro específico.
     */
    #[Override]
    public static function canEdit(Model $record): bool
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole(Utils::getSuperAdminName())) {
            return true;
        }

        return in_array($record->id, $user->getVisibleUserIds());
    }

    /**
     * Verificar si el usuario puede eliminar un registro específico.
     * No se puede eliminar a sí mismo ni al super admin.
     */
    #[Override]
    public static function canDelete(Model $record): bool
    {
        /** @var User $user */
        $user = Auth::user();

        // No puede borrarse a sí mismo
        if ($record->id === $user->id) {
            return false;
        }

        // No puede borrar super admins
        if ($record->hasRole(Utils::getSuperAdminName())) {
            return false;
        }

        if ($user->hasRole(Utils::getSuperAdminName())) {
            return true;
        }

        return in_array($record->id, $user->getVisibleUserIds());
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view'   => ViewUser::route('/{record}'),
            'edit'   => EditUser::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }
}
