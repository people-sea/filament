<?php

namespace Filament\Tests\Fixtures\Resources\Posts\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListLivePosts extends ListPosts
{
    public string $noteStatus = 'Waiting for activity';

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('author_id', Filament::auth()->id()))
            ->recordActions([
                Action::make('note')->schema([
                    TextInput::make('note')->extraInputAttributes(['data-testid' => 'live-list-note']),
                ])
                    ->modalDescription(fn (): string => $this->noteStatus)
                    ->livewireListeners([
                        'live-list-note-updated' => static function (ListLivePosts $livewire): void {
                            $livewire->noteStatus = 'Activity received';
                        },
                    ])
                    ->action(static function (): void {}),
            ])
            ->filtersTriggerAction(static fn (Action $action): Action => $action->extraAttributes(['data-testid' => 'live-list-filters']))
            ->filtersApplyAction(static fn (Action $action): Action => $action->extraAttributes(['data-testid' => 'live-list-apply-filters']));
    }
}
