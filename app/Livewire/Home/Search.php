<?php

declare(strict_types=1);

namespace App\Livewire\Home;

use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class Search extends Component
{
    private const int MIN_CONTENT_SEARCH_QUERY_LENGTH = 1;

    /**
     * The component's search query.
     */
    #[Url(as: 'q')]
    public string $query = '';

    /**
     * Indicates if the search input should be focused.
     */
    public bool $focusInput = false;

    /**
     * Renders the component.
     */
    public function render(): View
    {
        return view('livewire.home.search', [
            'results' => $this->query !== ''
                ? $this->searchByQuery()
                : $this->defaultUsers(),
        ]);
    }

    private function searchByQuery(): Collection
    {
        return $this->usersByQuery()
            ->when(
                mb_strlen($this->query) >= self::MIN_CONTENT_SEARCH_QUERY_LENGTH,
                fn (Collection $collection): Collection => $collection->merge($this->questionsByQuery()),
            );
    }

    /**
     * Returns the users by query, ordered by the number of questions received.
     *
     * @return Collection<int, User>
     */
    private function usersByQuery(): Collection
    {
        return User::query()
            ->with('links')
            ->whereAny(['name', 'username'], 'like', "%{$this->query}%")
            ->withCount(['questionsReceived as answered_questions_count' => function (Builder $query): void {
                $query->whereNotNull('answer');
            }])
            ->orderBy('answered_questions_count', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * Returns the questions by query, ordered by the number of likes received.
     *
     * @return Collection<int, User>
     */
    private function questionsByQuery(): Collection
    {
        return Question::query()
            ->withCount('likes')
            ->orderBy('likes_count', 'desc')
            ->with(['to', 'from', 'likes'])
            ->whereAny(['question', 'answer'], 'like', "%{$this->query}%")
            ->where('is_reported', false)
            ->where('is_ignored', false)
            ->limit(10)
            ->get();
    }

    /**
     * Returns the default users, ordered by the number of questions received.
     *
     * @return Collection<int, User>
     */
    private function defaultUsers(): Collection
    {
        $verifiedUsers = $this->verifiedUsers();

        return $this->famousUsers($verifiedUsers)
            ->merge($verifiedUsers)
            ->shuffle()
            ->load('links');
    }

    /**
     * Returns the users with the most questions received.
     *
     * @param  Collection<int, User>  $except
     * @return Collection<int, User>
     */
    private function famousUsers(Collection $except): Collection
    {
        $famousUsers = Cache::remember('top-50-users', now()->endOfDay(), fn (): array => User::query()
            ->whereHas('links', function (Builder $query): void {
                $query->where('url', 'like', '%twitter.com%')
                    ->orWhere('url', 'like', '%github.com%');
            })
            ->whereNotIn('id', $except->pluck('id'))
            ->withCount(['questionsReceived as answered_questions_count' => function (Builder $query): void {
                $query->whereNotNull('answer');
            }])
            ->orderBy('answered_questions_count', 'desc')
            ->limit(50)->pluck('id')->toArray()
        );

        return User::query()
            ->whereIn('id', $famousUsers)
            ->inRandomOrder()
            ->limit(10 - $except->count())
            ->get();
    }

    /**
     * Resets the users with verified badges.
     *
     * @return Collection<int, User>
     */
    private function verifiedUsers(int $limit = 2): Collection
    {
        return User::query()
            ->whereHas('links', function (Builder $query): void {
                $query->where('url', 'like', '%twitter.com%')
                    ->orWhere('url', 'like', '%github.com%');
            })
            ->where(function (Builder $query): void {
                $query->where('is_verified', true)
                    ->orWhereIn('username', array_merge(
                        config()->array('sponsors.github_company_usernames', []),
                        config()->array('sponsors.github_usernames', [])
                    ));
            })
            ->limit($limit)
            ->inRandomOrder()
            ->get();
    }
}
