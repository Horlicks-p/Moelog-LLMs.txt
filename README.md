# MoeLog LLMs.txt

**A clean, efficient WordPress content index plugin built for AI language models.**

This plugin implements the emerging [`llms.txt` specification](https://llmstxt.org/) for WordPress, providing AI crawlers (ChatGPT, Claude, Perplexity, etc.) with a structured site directory and noise-free Markdown versions of your content — so your posts are read and understood accurately, without HTML clutter.

## Features

- **`llms.txt` compliant**: Automatically generates `/llms.txt`, listing all published posts and pages with links to their Markdown counterparts.
- **On-demand Markdown output**: Append `.md` to any post or page URL (e.g. `https://example.com/about.md`) to retrieve a clean Markdown version of that content.
- **Deep HTML sanitization**:
  - Strips `header`, `footer`, `nav`, `aside`, `script`, `style`, and other non-content elements.
  - Converts the core content to standard Markdown using a DOMDocument-based recursive converter.
- **Smart noise filtering**:
  - Temporarily removes `the_content` filter callbacks from known noise sources (Jetpack, YARPP, ad injection plugins, related post widgets, etc.) before rendering Markdown.
  - Only the article body reaches the AI — no wasted context window.
- **SEO-safe**:
  - `.md` responses include `X-Robots-Tag: noindex`, preventing search engines from treating them as duplicate content.
  - No `Disallow` in `robots.txt` — AI crawlers like `GPTBot` and `ClaudeBot` can access `.md` files freely.
- **Cache-friendly**:
  - `.md` responses include `Cache-Control: public, max-age=3600`, compatible with Cloudflare and other CDNs, reducing database load from frequent AI crawler requests.

## Installation

1. Upload the `moelog-llms-txt` folder to `/wp-content/plugins/`, or install via the WordPress admin panel.
2. Activate **MoeLog LLMs.txt** from the Plugins screen.
3. **Recommended**: After activation, go to **Settings → Permalinks** and click **Save Changes** once to flush rewrite rules. *(If `.md` URLs return 404, this will fix it.)*

### Verify it's working

- Visit `https://yoursite.com/llms.txt` to confirm the index is generated correctly.
- Pick any post URL and append `.md` (e.g. `https://yoursite.com/hello-world.md`) to see the clean Markdown output.

## Design Philosophy

As AI agents and intelligent assistants become primary traffic sources, traditional HTML pages — bloated with DOM structure, CSS, ads, and tracking scripts — are increasingly hostile to machine consumption.

This plugin opens a dedicated **AI fast lane** for your WordPress site: no heavy `llms-full.txt` dumps, no reinventing the wheel. AI crawlers first get a lightweight `/llms.txt` overview, then pull only the specific `/*.md` content they need — precise, low-overhead, and context-window friendly.

## Customization

If you have other plugins that inject noise into post content, add their function or class name keywords to the `$noise_patterns` array in `includes/class-llms-txt.php`:

```php
$noise_patterns = array(
    'inject_ads',
    'related_posts',
    'share_buttons',
    'social_share',
    'yarpp',
    'wpp_',
    'jetpack',
    // 'my_custom_ads_plugin', // add your own keywords here
);
```

## License

GPL-2.0+
