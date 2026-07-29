# MoeLog LLMs.txt

**A clean, efficient WordPress content index plugin built for AI language models.**

This plugin follows the emerging [`llms.txt` proposal](https://llmstxt.org/) for WordPress, providing AI tools with a structured site directory and noise-free Markdown versions of public content — without HTML clutter.

## Features

- **`llms.txt`-style index**: Automatically generates `/llms.txt`, listing published, non-password-protected posts and pages.
- **On-demand Markdown output**: Append `.md` to any post or page URL (e.g. `https://example.com/about.md`) to retrieve a clean Markdown version of that content.
- **HTML-to-Markdown cleanup**:
  - Strips `header`, `footer`, `nav`, `aside`, `script`, `style`, and other non-content elements.
  - Converts the core content to standard Markdown using a DOMDocument-based recursive converter.
- **Smart noise filtering**:
  - Temporarily removes `the_content` filter callbacks from configured noise sources (YARPP, ad injection plugins, related post widgets, etc.) before rendering Markdown.
  - Only the article body reaches the AI — no wasted context window.
- **SEO-safe**:
  - `.md` responses include `X-Robots-Tag: noindex`, preventing search engines from treating them as duplicate content.
  - No `Disallow` in `robots.txt` — AI crawlers like `GPTBot` and `ClaudeBot` can access `.md` files freely.
- **Cache-friendly**:
  - `.md` responses include `Cache-Control: public, max-age=3600`.
  - `/llms.txt` is cached for 30 minutes and automatically rebuilt at the origin after content, site identity, or permalink settings change.
- **Protected-content safe**:
  - Password-protected, private, and unpublished content is excluded from both `/llms.txt` and `.md` responses.

## Requirements

- WordPress 6.0 or later.
- PHP 7.4 or later.
- Pretty permalinks are required for `.md` endpoints. With Plain Permalinks, `/llms.txt` falls back to the original HTML URLs.
- PHP DOM is recommended. If it is unavailable, Markdown output safely falls back to plain text.

## Installation

1. Upload the `moelog-llms-txt` folder to `/wp-content/plugins/`, or install via the WordPress admin panel.
2. Activate **MoeLog LLMs.txt** from the Plugins screen.
3. Visit `/llms.txt` and one public `.md` URL to verify the endpoints.

If `.md` URLs return 404, go to **Settings → Permalinks** and click **Save Changes** once as a troubleshooting step. Activation normally flushes the rewrite rules automatically.

### Verify it's working

- Visit `https://yoursite.com/llms.txt` to confirm the index is generated correctly.
- Pick any post URL and append `.md` (e.g. `https://yoursite.com/hello-world.md`) to see the clean Markdown output.

## Design Philosophy

As AI agents and intelligent assistants become primary traffic sources, traditional HTML pages — bloated with DOM structure, CSS, ads, and tracking scripts — are increasingly hostile to machine consumption.

This plugin opens a dedicated **AI fast lane** for your WordPress site: no heavy `llms-full.txt` dumps, no reinventing the wheel. AI crawlers first get a lightweight `/llms.txt` overview, then pull only the specific `/*.md` content they need — precise, low-overhead, and context-window friendly.

## Customization

If another plugin injects noise into post content, extend the callback-name patterns from your own small plugin or mu-plugin. Do not edit `includes/class-llms-txt.php`, because updates would overwrite that change:

```php
add_filter( 'moelog_llms_noise_patterns', function ( $patterns ) {
    $patterns[] = 'My_Ads_Plugin::append_ad';
    return $patterns;
} );
```

Use the narrowest callable or class/method fragment possible. Broad patterns such as `jetpack` can remove useful gallery, shortcode, or formatting callbacks along with sharing widgets. After changing these patterns, compare the Markdown output of a real post before and after the change.

## License

GPL-2.0+
