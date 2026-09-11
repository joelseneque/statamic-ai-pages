<?php

namespace Joelseneque\AiPages\Tests;

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Statamic binds {entry} and {collection} on every Control Panel route, so a
 * controller that type-hints a string for either gets a TypeError rather than
 * an id. Only actually hitting the routes catches that.
 */
class CpRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-pages.api_key' => 'sk-ant-test']);

        // More than one user requires Pro, and these tests need an admin and a
        // nobody to tell the permission cases apart.
        config(['statamic.editions.pro' => true]);
        Collection::make('pages')->title('Pages')->save();
    }

    protected function admin()
    {
        return tap(User::make()->email('admin@example.com')->makeSuper())->save();
    }

    protected function entry()
    {
        return tap(Entry::make()->collection('pages')->slug('a-page')->data(['title' => 'A Page']))->save();
    }

    public function test_the_dashboard_loads(): void
    {
        $this->actingAs($this->admin())
            ->get(cp_route('ai-pages.index'))
            ->assertOk();
    }

    public function test_the_build_form_loads(): void
    {
        $this->actingAs($this->admin())
            ->get(cp_route('ai-pages.build'))
            ->assertOk();
    }

    public function test_the_instructions_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get(cp_route('ai-pages.instructions'))
            ->assertOk();
    }

    public function test_the_history_page_loads(): void
    {
        $this->actingAs($this->admin())
            ->get(cp_route('ai-pages.jobs'))
            ->assertOk();
    }

    public function test_the_tweak_form_loads_for_a_bound_entry(): void
    {
        $this->actingAs($this->admin())
            ->get(cp_route('ai-pages.tweak', $this->entry()->id()))
            ->assertOk()
            ->assertSee('A Page');
    }

    public function test_an_unknown_entry_is_a_404_not_a_type_error(): void
    {
        $this->actingAs($this->admin())
            ->get(cp_route('ai-pages.tweak', 'nope'))
            ->assertNotFound();
    }

    public function test_the_set_listing_loads_for_a_bound_collection(): void
    {
        $this->actingAs($this->admin())
            ->get(cp_route('ai-pages.build.sets', 'pages'))
            ->assertOk();
    }

    public function test_pages_are_closed_to_users_without_permission(): void
    {
        $nobody = tap(User::make()->email('nobody@example.com'))->save();

        // Statamic bounces an unauthorised CP user rather than showing a 403.
        $this->actingAs($nobody)
            ->get(cp_route('ai-pages.index'))
            ->assertRedirect();
    }
}
