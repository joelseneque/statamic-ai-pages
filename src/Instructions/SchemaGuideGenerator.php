<?php

namespace Joelseneque\AiPages\Instructions;

use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Schema\BardConventionDetector;
use Joelseneque\AiPages\Schema\BlueprintCompiler;
use Joelseneque\AiPages\Schema\Conventions;
use Joelseneque\AiPages\Build\CompanionCollections;
use Joelseneque\AiPages\Schema\SetCatalogue;

/**
 * Produces the per-collection guide: what blocks exist, and — crucially — how
 * this particular site uses them in practice.
 *
 * The measured half (block counts, recurring section orders, the values the
 * site actually picks) is deterministic. The model's job is only to turn that
 * evidence into usable editorial guidance.
 */
class SchemaGuideGenerator
{
    public function __construct(
        protected Client $client,
        protected BlueprintCompiler $compiler,
        protected InstructionRepository $instructions,
        protected Conventions $conventions,
    ) {}

    public function generate(string $collection, ?string $site = null): string
    {
        $compiled = $this->compiler->forCollection($collection);
        $detector = new BardConventionDetector;
        $analysis = $detector->analyse($collection, $site);

        // Cache the machine-readable half for the builder to apply directly.
        $this->conventions->put($collection, $analysis);

        $catalogues = collect($compiled['blueprints'])
            ->map(fn ($bp) => "## Blueprint: {$bp['title']} (`{$bp['handle']}`)\n\n"
                .SetCatalogue::fromBlueprint($bp)->toMarkdown())
            ->implode("\n\n");

        $evidence = $detector->toMarkdown($analysis);

        foreach ($compiled['blueprints'] as $bp) {
            if ($companions = CompanionCollections::describe($bp)) {
                $evidence .= "\n\n## Blocks that point at other collections\n\n{$companions}\n\n"
                    ."These relationships allow new entries to be created, so a page can bring its own with it.";

                break;
            }
        }

        $markdown = $this->client->text(
            system: <<<'SYSTEM'
            You are writing the editorial handbook for one collection of a Statamic site. Another system will
            read it before assembling new pages out of these blocks, so it must be practical, not descriptive.

            You get two things: the blocks defined in the blueprint, and hard evidence of how the site's
            existing pages actually use them.

            Write Markdown covering:

            - **Choosing blocks** — for each block that gets real use, one or two lines on when to reach for it
              and when not to. Lead with the workhorses. Say plainly which blocks are rare or effectively
              retired, so they don't get used by accident.
            - **How a page is shaped here** — the section orders that recur, described as reusable patterns with
              names (e.g. "service page", "landing page"). Say what opens a page and what closes it.
            - **House choices** — where the evidence shows this site consistently picks particular option values
              (button styles, colours, alignments), state the default and when to deviate.
            - **Heading discipline** — the heading levels used, and where.
            - **Pairings and pitfalls** — blocks that belong together, blocks that clash, fields that are easy to
              fill in wrongly.
            - **Content that lives elsewhere** — where a block points at entries in another collection rather than
              holding the content itself, say so plainly: which collection, how many a page typically references,
              and whether a new page normally needs new ones written or reuses what exists. This is the thing an
              outsider gets wrong most often, because the content looks like it is on the page when it is not.

            Rules:
            - Ground every claim in the evidence. If a block is defined but never used, say that rather than
              inventing guidance for it.
            - Refer to blocks by their handle in backticks.
            - No preamble. Start with `# Building pages in this collection`.
            SYSTEM,
            messages: [[
                'role' => 'user',
                'content' => "# Blocks defined in the blueprint\n\n{$catalogues}\n\n"
                    ."# Evidence from existing pages\n\n{$evidence}",
            ]],
            options: ['max_tokens' => 8000],
        )->text();

        // Keep the measured evidence in the file — it is the part that's
        // verifiable, and it is what makes a stale guide obvious.
        $body = $markdown."\n\n---\n\n# Measured from the site\n\n".$evidence;

        $this->instructions->putSchemaGuide($collection, $body);

        return $body;
    }

    /**
     * Re-measure without spending anything on the model — useful after a
     * content import, when the prose guide is still accurate but the numbers
     * behind it have moved.
     */
    public function remeasure(string $collection, ?string $site = null): array
    {
        $analysis = (new BardConventionDetector)->analyse($collection, $site);

        $this->conventions->put($collection, $analysis);

        return $analysis;
    }
}
