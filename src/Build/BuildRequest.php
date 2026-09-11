<?php

namespace Joelseneque\AiPages\Build;

use Illuminate\Support\Str;

class BuildRequest
{
    public const MODE_VERBATIM = 'verbatim';

    public const MODE_POLISH = 'polish';

    public const MODE_GENERATE = 'generate';

    public function __construct(
        public string $collection,
        public SourceBundle $sources,
        public ?string $blueprint = null,
        public ?string $site = null,
        public string $mode = self::MODE_POLISH,
        public ?string $title = null,
        public ?string $slug = null,
        public ?string $brief = null,
        public ?string $templateEntry = null,
        public ?string $parent = null,
        public bool $publish = false,
        public ?int $maxSections = null,
        /** Plan and write nothing — not the page, and not its supporting entries. */
        public bool $dry = false,
    ) {}

    public static function fromArray(array $input, SourceBundle $sources): self
    {
        return new self(
            collection: $input['collection'],
            sources: $sources,
            blueprint: $input['blueprint'] ?? null,
            site: $input['site'] ?? null,
            mode: in_array($input['mode'] ?? '', self::modes(), true) ? $input['mode'] : self::MODE_POLISH,
            title: $input['title'] ?? null,
            slug: $input['slug'] ?? null,
            brief: $input['brief'] ?? null,
            templateEntry: $input['template_entry'] ?? null,
            parent: $input['parent'] ?? null,
            publish: (bool) ($input['publish'] ?? false),
            maxSections: isset($input['max_sections']) ? (int) $input['max_sections'] : null,
            dry: (bool) ($input['dry'] ?? false),
        );
    }

    public static function modes(): array
    {
        return [self::MODE_VERBATIM, self::MODE_POLISH, self::MODE_GENERATE];
    }

    public static function modeLabels(): array
    {
        return [
            self::MODE_VERBATIM => 'Keep exact wording',
            self::MODE_POLISH => 'Minor content tweaks',
            self::MODE_GENERATE => 'Write the content',
        ];
    }

    /**
     * The behavioural contract for the chosen fidelity mode. This is the single
     * most load-bearing instruction in a build, so it's stated bluntly.
     */
    public function modeInstruction(): string
    {
        return match ($this->mode) {
            self::MODE_VERBATIM => <<<'TEXT'
            FIDELITY: KEEP EXACT WORDING.

            The source text is final copy. Your job is to place it, not to write it.

            - Reproduce every sentence character for character. Do not reword, reorder within a sentence,
              retitle, expand, condense, fix grammar, change punctuation, or adjust spelling — not even
              obvious mistakes.
            - You may only: split the copy across sections, decide which block each part belongs in, and
              choose structural settings (layout, alignment, colour, block type).
            - Headings must be lifted verbatim from the source. If a section needs a heading and the source
              has none, leave the heading empty rather than inventing one.
            - Never write connecting sentences, introductions or calls to action that are not in the source.
            - Omit nothing. Every substantive sentence in the source must appear somewhere in the output.
            TEXT,

            self::MODE_POLISH => <<<'TEXT'
            FIDELITY: MINOR TWEAKS ONLY.

            The source text is close to final. Improve its delivery without changing what it says.

            - Keep the author's sentences, structure, order and meaning.
            - You may: fix grammar, spelling and punctuation; align spelling to the site's locale; tighten
              obvious wordiness; split an overlong sentence; write or sharpen headings and button labels;
              adjust capitalisation to house style.
            - You may not: add new claims, facts, figures, benefits or sections; remove substantive content;
              change the meaning or strength of any statement; restate the argument in your own words.
            - Every factual claim in the output must be traceable to the source.
            TEXT,

            self::MODE_GENERATE => <<<'TEXT'
            FIDELITY: WRITE THE CONTENT.

            Treat the source as a brief and write finished page copy from it, in the site's tone of voice.

            - Cover everything the brief asks for, in the depth a real page needs.
            - Use only facts present in the brief, the site profile, or the site's existing pages. Never invent
              statistics, prices, credentials, timeframes, testimonials or clinical claims. If the page clearly
              needs a fact you do not have, write the sentence around it or leave the field empty — do not
              guess and do not use a placeholder like "XX%".
            - Match the tone-of-voice guide exactly, including spelling locale.
            - Write for a reader deciding what to do next, not for a search engine.
            TEXT,
        };
    }

    public function suggestedSlug(): ?string
    {
        return $this->slug ?: ($this->title ? Str::slug($this->title) : null);
    }
}
