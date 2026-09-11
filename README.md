# AI Pages

Build and edit Statamic entries with Claude — from a document, a link, a design export or a paragraph of text.

AI Pages reads your blueprints and your existing content, works out how your site is actually put together, and
then assembles new pages out of your real page-builder blocks. Everything lands as an unpublished draft.

Statamic 5 & 6 · PHP 8.2+

---

## Why this rather than a prompt box

Most "AI for CMS" tooling generates a wall of Markdown and drops it into one rich-text field. That is not how a
Statamic site is built. This addon works at the level your site actually works at:

- **It reads your blueprints.** Every block, every field, every conditional. Imports and fieldsets resolved.
- **It reads your content.** Which blocks you really use, in what order, with which settings, and what node
  attributes you stamp on paragraphs — measured, not guessed, and measured from *published* entries only so its
  own drafts can never teach it a bad habit.
- **It fills in what it measured.** All the layout boilerplate (`inside_container_width`, `mt-default`,
  `animate: true`) is applied deterministically and left out of the prompt entirely.
- **It writes structured field data**, validated against a JSON Schema generated from your blueprint, so it
  can't invent a field name or put a string where an enum goes. Where the blueprint and your content disagree
  about a field's vocabulary, the weight of what you've actually stored decides it.

## Install

```bash
composer require joelseneque/ai-pages
```

```dotenv
ANTHROPIC_API_KEY=sk-ant-…
ANTHROPIC_MODEL=claude-sonnet-5
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=ai-pages-config
```

**No build step.** The Control Panel styles ship as plain CSS and are published to
`public/vendor/ai-pages` automatically on install — you never need to rebuild your site's CP assets. If the
pages ever look unstyled, re-publish them:

```bash
php artisan vendor:publish --tag=ai-pages --force
```

## First run — the sweep

Before it can write anything that sounds like your site, it has to read your site.

```bash
php please ai-pages:sweep
```

That writes four Markdown files into `resources/ai-pages/`:

| File | What it is |
|---|---|
| `site-profile.md` | What the organisation does, who reads the site, the IA, the exact names and URLs to get right. |
| `tone-of-voice.md` | How the site sounds, derived from its own published copy — vocabulary, register, spelling locale, real CTA examples. |
| `schema/<collection>.md` | Which blocks that collection really uses, the section orders that recur, and the house settings. |
| `house-rules.md` | **Yours.** Never generated, never overwritten, and it overrides everything above. |

They are plain Markdown on purpose: commit them, diff them in a PR, and edit them by hand wherever the sweep's
read of the site isn't quite right. The generated files are a starting point, not gospel.

The sweep also caches a machine-readable `storage/ai-pages/conventions.json` — the measured node attributes,
option values and per-block defaults the builder applies directly.

Re-run it after a redesign, a big content change, or when you add new blocks. To refresh only the measurements
without spending anything on the API:

```bash
php please ai-pages:sweep --measure-only
```

## Building a page

**Control Panel** → AI Pages → Build a page.

Give it anything: pasted text, a Google Doc link (shared as "anyone with the link can view"), a `.docx`, a PDF,
a screenshot or design export, a URL to an existing page, or several at once. PDFs and images go to Claude
natively, so a design export reads as well as a document does.

Then pick how much licence it has:

| Mode | What it does |
|---|---|
| **Keep exact wording** | Your copy, character for character. It only decides which block each part belongs in. The output is diffed back against the source and anything that drifted — or went missing — is flagged. |
| **Minor content tweaks** | Keeps your sentences and meaning. Fixes grammar, spelling, headings and button labels. |
| **Write the content** | Treats the source as a brief and writes the page in your tone of voice. |

From the command line:

```bash
php please ai-pages:build pages --source=brief.docx --mode=generate --title="Non-surgical weight loss"
```

Add `--dry` to plan and build without saving.

## Tweaking an existing page

Any entry's action menu has **Tweak with AI**. Describe the change in plain language:

> Add an FAQ block at the bottom using the medication FAQs. Shorten the intro to two paragraphs. Point the hero
> button at /book-a-consultation.

It reads the page as a numbered outline, plans a **patch** — edit, replace, insert, delete, move — and shows you
what it intends to do. Nothing is written until you approve it, and sections you didn't mention keep their exact
stored values. If the collection has revisions enabled, a working copy is left behind.

## How a build actually runs

1. **Plan.** The planner sees a one-line summary of each available block — never the full field definitions —
   plus your instructions and the source. It returns the page identity and an ordered list of blocks, splitting
   the source text across them.
2. **Fill in.** Each block is a separate, small request carrying that block's full schema and a real stored
   example of the same block from your own site.
3. **Assemble.** Markdown becomes Bard nodes with the attributes your site uses *in that context*. Asset
   filenames and entry references are resolved against real records; anything that can't be found is dropped
   with a warning rather than saved as a dangling reference.
4. **Check.** In verbatim mode, every output sentence is diffed against the source.

Splitting it this way is what makes it work on a big blueprint: the planning call stays small however many
blocks you have, each block is independently retryable, and one bad field doesn't cost you the page.

## Configuration

```php
'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
'collections' => ['*'],              // which collections it may build into
'publish_new_entries' => false,      // drafts only, by default
'exemplars_per_collection' => 3,     // how many of your own blocks to show as examples
'queue' => env('AI_PAGES_QUEUE'),    // null runs builds inline
'verbatim_threshold' => 0.92,        // similarity below which a sentence counts as drifted
```

Builds run inline unless you set `AI_PAGES_QUEUE`. On a large page that means a slow request — set a queue
connection and a worker for anything serious.

## Permissions

- `build ai pages`
- `edit ai pages`
- `manage ai pages instructions`

## Source documents are untrusted

A brief pulled off a public URL or out of a client's Word document can contain text that reads like an
instruction. Every source is fenced and explicitly labelled as material to work from, and the system prompt says
so in as many words. Instructions only ever come from you, in the Control Panel.

## Housekeeping

```bash
php please ai-pages:prune --days=30
```

Deletes old build records and the uploads they used.

## Licence

MIT.
