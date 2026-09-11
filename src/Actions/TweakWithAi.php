<?php

namespace Joelseneque\AiPages\Actions;

use Joelseneque\AiPages\Anthropic\Client;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

/**
 * Adds "Tweak with AI" to the entry listing and the entry's own action menu.
 */
class TweakWithAi extends Action
{
    public static function title()
    {
        return __('ai-pages::messages.tweak_action');
    }

    public function visibleTo($item)
    {
        // Permissions belong in authorize() — Statamic's ActionRepository calls
        // it with the current user immediately after this. Reaching for a user
        // here is both redundant and, since there is no global user() helper,
        // fatal on every screen that lists actions.
        return $item instanceof Entry && app(Client::class)->configured();
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function authorize($user, $item)
    {
        return $user->can('edit ai pages') && $user->can('edit', $item);
    }

    public function redirect($items, $values)
    {
        return cp_route('ai-pages.tweak', $items->first()->id());
    }

    public function run($items, $values)
    {
        // The redirect does the work; this action only routes the user.
    }
}
