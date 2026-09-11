<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\Actions\TweakWithAi;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;

class TweakWithAiTest extends TestCase
{
    protected function entry(): Entry
    {
        Collection::make('pages')->save();

        return EntryFacade::make()->collection('pages')->slug('a-page');
    }

    /**
     * This action is evaluated on every screen that lists entry actions, so a
     * fatal in visibleTo() takes out the whole entry listing and editor — which
     * is exactly what an undefined user() helper did.
     */
    public function test_visible_to_does_not_fatal_on_an_entry(): void
    {
        config(['ai-pages.api_key' => 'sk-ant-test']);

        $this->assertTrue((new TweakWithAi)->visibleTo($this->entry()));
    }

    public function test_it_is_hidden_when_no_api_key_is_configured(): void
    {
        config(['ai-pages.api_key' => null]);

        $this->assertFalse((new TweakWithAi)->visibleTo($this->entry()));
    }

    public function test_it_is_hidden_for_things_that_are_not_entries(): void
    {
        config(['ai-pages.api_key' => 'sk-ant-test']);

        $this->assertFalse((new TweakWithAi)->visibleTo(new \stdClass));
    }

    public function test_it_never_offers_itself_for_bulk_selections(): void
    {
        $this->assertFalse((new TweakWithAi)->visibleToBulk(collect([$this->entry()])));
    }

    public function test_authorisation_is_refused_without_the_permission(): void
    {
        // Must be saved — an id-less user blows up Statamic's permission cache.
        $user = tap(\Statamic\Facades\User::make()->email('nobody@example.com'))->save();

        $this->assertFalse((new TweakWithAi)->authorize($user, $this->entry()));
    }
}
