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
        return $item instanceof Entry
            && app(Client::class)->configured()
            && user()?->can('edit ai pages');
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
