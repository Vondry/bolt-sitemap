# Enterprise XML Sitemap Architecture & Technical SEO Specification — v3

* **Document Role:** Technical SEO & Web Engineering Standard
* **Version:** 3.0 (2026-10-24)
* **Protocol:** Sitemaps.org 0.9 + Google extensions (Image 1.1, Video 1.1, News 0.9, XHTML `hreflang`)
* **Architecture:** Zero native XSLT — works in every browser version, before and after Chrome's XSLT removal (Chrome 158, Nov 17, 2026)

---

## 1. Core Protocol Standards

An XML sitemap is a machine-readable list of the canonical URLs on a host that you want crawled, optionally with the last modification date, media, and localized alternates. It is a **hint**, not a directive: listing a URL does not force indexing, and omitting one does not deindex it.

Crawlers that consume sitemaps include Googlebot, Bingbot, SeznamBot, and Applebot.

### 1.1 Encoding & escaping
* The file **must** be UTF-8.
* Entity-escape XML special characters in values: `&` → `&amp;`, `<` → `&lt;`, `>` → `&gt;`, `'` → `&apos;`, `"` → `&quot;`.
* URLs must be **absolute** and **RFC 3986-encoded**. Non-ASCII characters (for example, Czech diacritics in slugs) must be **percent-encoded** before XML escaping:
  `https://example.com/služby?a=1&b=2` → `https://example.com/slu%C5%BEby?a=1&amp;b=2`
* **Internationalized domain names:** a non-ASCII **host** is written in Punycode (IDNA), not percent-encoded. The path still uses UTF-8 percent-encoding:
  `https://háčky.cz/služby` → `https://xn--hky-ela4t.cz/slu%C5%BEby`
  Generate the host with a library (PHP `idn_to_ascii()`, JS `new URL(…).hostname`) rather than by hand.
* **Nothing may precede `<?xml`**: no UTF-8 BOM, no blank line, no whitespace. Browsers reject the file with *"XML declaration allowed only at the start of the document"*. Google is more tolerant, but other parsers are not. The usual culprit in Twig/Bolt/Symfony templates is a `{# comment #}` or `{% extends %}` line above the declaration. Put `<?xml` on line 1 or use whitespace control (`{%- … -%}`).

### 1.2 Size limits
* **Per sitemap:** max **50,000 URLs** and **50 MB (52,428,800 bytes) uncompressed**. The limit applies to the uncompressed size even when the file is served gzipped.
* **Per sitemap index:** max **50,000 child sitemaps**, 50 MB. **Indexes cannot be nested** (an index must not reference another index).
* **Compression:** `sitemap.xml.gz` files are supported. Alternatively serve plain `.xml` with transport compression (`Content-Encoding: gzip`), which is simpler and keeps the file human-viewable.
* **Search Console:** up to **500 sitemap index files** can be submitted per site.
* **Practical chunking.** Whichever limit is hit first applies. Bytes per `<url>` grow with extensions:
  * plain `<loc>` + `<lastmod>` ≈ 150 B → the 50,000-URL limit binds first;
  * each `<xhtml:link>` ≈ 100 B, so 10 locales ≈ 1.2 KB per URL → ~40,000 URLs per 50 MB;
  * 40+ locales, or many `<image:image>` entries per URL → the 50 MB limit can bind at ~10,000 URLs.

  Measure your own average entry size and split well below both limits. Child sitemaps of **10,000–25,000 URLs** are a sensible CMS default: they stay small, regenerate fast, and give finer per-segment reporting in Search Console.

### 1.3 Scope & host rules
* A sitemap may only list URLs **on the same host and protocol**, at or below its own path. A file at `/catalog/sitemap.xml` may list `/catalog/*` but not `/images/*`. **Place sitemaps at the root.**
* Cross-host listing requires either a `Sitemap:` line in the *target* host's `robots.txt` or verified ownership of both properties in Google Search Console.
* The sitemap itself must return `200 OK`.

### 1.4 `<lastmod>`
* Format: **W3C Datetime**. Either a date (`2026-10-22`) or a full datetime **with a timezone** (`2026-10-22T08:51:00+02:00`). Use one format consistently.
* **Never hardcode the offset** (`+02:00`) in a template: it is wrong for half the year in Central Europe (CET `+01:00` / CEST `+02:00`). Output UTC (`2026-10-22T06:51:00Z`), or format a timezone-aware date with `DateTimeInterface::ATOM` (PHP) / `toISOString()` (JS, UTC).
* It must reflect the last **meaningful** change to the page's primary content or structured data. It must not reflect the sitemap generation time, a deploy, or a footer/CSS change.
* Google uses `lastmod` only when it is consistently and verifiably accurate. Bing treats an accurate `lastmod` as a key signal for prioritizing recrawls.
* **Listing and category pages:** a defensible `lastmod` is the **later** of:
  1. `MAX(updated_at)` of the items currently shown on the page, and
  2. the last time the page's item set changed (an item added, removed, unpublished, or reordered).

  `MAX(updated_at)` alone misses removals: deleting an item changes the page but does not move the maximum forward.
* **If no honest date can be derived** (for example, a hand-assembled homepage that is not tracked), **omit `<lastmod>`** for that URL rather than inventing one.

### 1.5 Remove `<changefreq>` and `<priority>`
Google ignores both. Bing also states that it ignores them and that they do not influence crawling or ranking. Many CMS generators (Bolt CMS, older WordPress plugins) still emit them. Remove them from templates: they are noise that implies a control which does not exist. The byte savings are negligible.

---

## 2. Browser Support & the XSLT Removal

Historically, CMSs added `<?xml-stylesheet type="text/xsl" href="…"?>` so that a human opening `sitemap.xml` in a browser would see a styled HTML table instead of raw XML.

### 2.1 What is being removed and what is not
* **Removed:** the XSLT processing instruction (`<?xml-stylesheet type="text/xsl" …?>`) and the `XSLTProcessor` JavaScript API.
* **Not removed:** XML itself, and `<?xml-stylesheet type="text/css" …?>`. CSS styling of XML remains supported.
* Crawlers never executed client-side XSLT. **Search engines are unaffected.** Only humans viewing the file in a browser are.

### 2.2 Why
1. **Security.** Chromium uses `libxslt`, an aging C/C++ codebase with a history of high-severity memory-safety bugs (for example, CVE-2025-7425 and CVE-2022-22834). Chrome is also moving XML parsing from `libxml2` to a memory-safe Rust parser.
2. **Negligible usage.** About **0.02%** of page loads use XSLT at all, and **under 0.001%** use the XSLT processing instruction (Chrome Platform Status).
3. **Stagnation.** Browsers only ever implemented XSLT 1.0 (1999), never 2.0 (2007) or 3.0 (2017).

### 2.3 Chrome timeline (official)

| Milestone | Date | Effect |
| :--- | :--- | :--- |
| Chrome 142 | Oct 28, 2025 | Early-warning **console** messages. |
| Chrome 143 | Dec 2, 2025 | Official deprecation. Warnings in the console and Lighthouse. |
| Chrome 145 (Canary) | Dec 2, 2025 | Canary/Dev/Beta disable XSLT by default. |
| Chrome 146 | Mar 10, 2026 | `XSLTEnabled` Enterprise Policy available. |
| Chrome 152 | Aug 25, 2026 | Origin Trial available. |
| Chrome 154 | Sep 22, 2026 | WebView Canary/Dev/Beta disable XSLT by default. |
| **Chrome 158** | **Nov 17, 2026** | **XSLT stops working on Stable**, except for Origin Trial and Enterprise Policy participants. |
| Chrome 176 | Aug 17, 2027 | Origin Trial and Enterprise Policy end. XSLT is disabled for everyone. |

* When XSLT is disabled, Chrome shows an **in-page warning banner** linking to a polyfill extension. Before that point, the warnings appear only in DevTools and Lighthouse.
* **Edge** follows the Chromium schedule; confirm exact Edge dates in Microsoft's release notes.
* **Firefox (Gecko) and Safari (WebKit)** have both indicated support for removing XSLT. **No removal dates had been published** as of this revision.
* Some older copies of Chrome's guide list M155/M164. The milestones above are the current ones.

---

## 3. Inclusion & Exclusion Matrix

| Include | Exclude |
| :--- | :--- |
| **`200 OK`, indexable pages only.** | **Non-200 URLs:** `3xx` redirects, `4xx`, `5xx`. |
| **Self-referencing canonicals.** The exact URL in `<link rel="canonical">`: scheme, `www`/non-`www`, trailing slash, letter case. | **Non-canonical and parameterized URLs:** tracking parameters (`?utm_*`), faceted filter combinations, session IDs, sort orders. |
| **Primary content:** homepage, service/category pages, product pages, articles, pricing, contact. | **`noindex` pages**, whether via `<meta name="robots">` or the `X-Robots-Tag` header. |
| **Original, valuable images** via the Image extension (§4.3). | **URLs disallowed in `robots.txt`**, internal search results, cart/checkout, login/account pages. |
| **Localized variants** with complete, reciprocal `hreflang` (§4.2). Every alternate must itself be `200`, canonical, and indexable. | **Thin utility pages** at your discretion. Excluding legal pages or `?page=N` pagination from the sitemap is fine, but it does **not** deindex them. Use `noindex` or canonicals for that. |

**Rule:** every URL in the sitemap must be one you would be happy to see as a search result, and it must agree with that page's canonical, robots, and hreflang signals. Conflicting signals are worse than missing ones.

---

## 4. Production Blueprints

### 4.1 Sitemap index (`/sitemap.xml` or `/sitemap_index.xml`)
Segmenting by content type lets you see indexing coverage per segment in Search Console (for example, products at 98% versus articles at 60%).

```xml
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap>
    <loc>https://example.com/sitemap-pages.xml</loc>
    <lastmod>2026-10-22T08:51:00+02:00</lastmod>
  </sitemap>
  <sitemap>
    <loc>https://example.com/sitemap-services.xml</loc>
    <lastmod>2026-10-21T20:57:00+02:00</lastmod>
  </sitemap>
  <sitemap>
    <loc>https://example.com/sitemap-articles.xml</loc>
    <lastmod>2026-10-24T06:30:00+02:00</lastmod>
  </sitemap>
</sitemapindex>
```

* A child sitemap's `<lastmod>` = the time that child's **content last changed**: the later of its newest URL `<lastmod>` and the last time a URL was added to or removed from it. It is not the file's generation time. (Newest URL `<lastmod>` alone misses removals, the same trap as in §6.1.)
* Child sitemaps must be on the same host (§1.3) and must not be indexes themselves.

### 4.2 Urlset with Image and `hreflang` (`sitemap-pages.xml`)
Contains no XSLT instruction, no `<priority>`, and no `<changefreq>`.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">

  <!-- Czech homepage: lists ALL alternates, including itself -->
  <url>
    <loc>https://example.com/</loc>
    <xhtml:link rel="alternate" hreflang="cs" href="https://example.com/" />
    <xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/" />
    <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/" />
    <image:image>
      <image:loc>https://example.com/files/workshop-hero.webp</image:loc>
    </image:image>
  </url>

  <!-- English homepage: its own <url> entry with the SAME full set -->
  <url>
    <loc>https://example.com/en/</loc>
    <xhtml:link rel="alternate" hreflang="cs" href="https://example.com/" />
    <xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/" />
    <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/" />
  </url>

  <!-- Czech service page -->
  <url>
    <loc>https://example.com/sluzby</loc>
    <lastmod>2026-10-21T20:57:00+02:00</lastmod>
    <xhtml:link rel="alternate" hreflang="cs" href="https://example.com/sluzby" />
    <xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/services" />
    <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/sluzby" />
  </url>

  <!-- English service page -->
  <url>
    <loc>https://example.com/en/services</loc>
    <lastmod>2026-10-21T20:57:00+02:00</lastmod>
    <xhtml:link rel="alternate" hreflang="cs" href="https://example.com/sluzby" />
    <xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/services" />
    <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/sluzby" />
  </url>

  <!-- Page with no translation: no hreflang at all -->
  <url>
    <loc>https://example.com/cenik</loc>
    <lastmod>2026-10-21T20:57:00+02:00</lastmod>
  </url>

</urlset>
```

Notes on this example:
* The homepage omits `<lastmod>` here to illustrate a page with no trackable change date (§1.4). If your homepage is a listing driven by records, derive its `lastmod` with the listing-page rule instead.
* **Each** language version has its own `<url>` entry with the **full reciprocal set**, including a self-reference.
* Language codes are ISO 639-1, with an optional ISO 3166-1 region (`cs` or `cs-CZ`). Use `cs` unless you serve region-specific variants.
* Declare `hreflang` in **one** place: the sitemap, HTML `<link>` tags, or HTTP headers. Mixing them invites conflicts.
* Bing and Seznam give `hreflang` little weight; it is primarily a Google signal.

### 4.3 Image extension rules
* Only `<image:loc>` is supported. Google deprecated `image:caption`, `image:title`, `image:license`, and `image:geo_location` in 2022. Remove them from templates.
* Up to **1,000 images per `<url>`**.
* Image URLs may be on another host, such as a CDN.
* List original, content-relevant images only: no icons, logos, or decorative assets.

### 4.4 News and Video extensions (essentials)
* **News** (`xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"`):
  * Only for publishers eligible for Google News.
  * Include only articles published in the **last 2 days**.
  * Max **1,000** `<news:news>` entries per sitemap.
  * Required fields: publication name and language, `news:publication_date`, `news:title`.
  * Keep News in its own sitemap file.
* **Video** (`xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"`):
  * Required: `video:thumbnail_loc`, `video:title`, `video:description`, plus at least one of `video:content_loc` or `video:player_loc`.
  * Video structured data on the page is an equivalent alternative.
  * Deprecated in the same 2022 clean-up as the image tags (no effect since Aug 6, 2022): `video:category`, `video:gallery_loc`, `video:price`, `video:tvshow`, and the `allow_embed` and `autoplay` attributes of `video:player_loc`. Remove them from templates.
* If a site uses neither extension, do not declare those namespaces.

---

## 5. Styling `sitemap.xml` for Humans Without Native XSLT

Choose **one** method. Crawlers ignore all of them.

| | A — CSS | B — Embedded JS | C — Raw XML | D — XSLT polyfill |
| :--- | :---: | :---: | :---: | :---: |
| Clickable links | ✗ | ✓ | ✗ | ✓ |
| Requires JS | ✗ | ✓ | ✗ | ✓ (WASM) |
| Extra schema for strict XSD validation | — | XHTML | — | XHTML |
| Avoids Chrome XSLT warnings | ✓ | ✓ | ✓ | ✗ (see Method D) |
| Reuses existing XSL template | ✗ | ✗ | ✗ | ✓ |
| Maintenance | Low | Medium | None | Low |

**Schema note (B and D):** the sitemaps.org XSD explicitly allows foreign-namespace elements as children of `<urlset>` **before** the first `<url>`, so a `<script>` placed as the first child is schema-legal. The wildcard uses `processContents="strict"`, so a validator needs the XHTML schema loaded — the same requirement as for `image:` and `xhtml:link` extensions (§7). XHTML 1.0 Strict requires `type` on `<script>`, hence `type="text/javascript"` in the samples below. Chrome's migration guide uses the same pattern for RSS feeds. Still **verify after deployment** in Google Search Console, Bing Webmaster Tools, and Seznam Webmaster.

**Anti-pattern — content negotiation:** never serve an HTML page on the sitemap URL based on the request's `Accept` header or user agent. Crawlers commonly send `Accept` values that include `text/html`, so negotiation can hand them HTML instead of XML and break parsing. The sitemap URL must always return the same XML; any human-facing view is built from that XML in the browser (Methods A, B, D).

### Method A — Pure CSS (`text/css`)

**1. Add the processing instruction directly below the XML declaration:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<?xml-stylesheet type="text/css" href="/assets/sitemap.css"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
  <!-- <url> entries -->
</urlset>
```

**2. `/assets/sitemap.css`:**
```css
@namespace sm url(http://www.sitemaps.org/schemas/sitemap/0.9);

sm|urlset, sm|sitemapindex {
  color-scheme: light dark;
  display: block;
  max-width: 1100px;
  margin: 3rem auto;
  padding: 0 1rem;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  background: Canvas;
  color: CanvasText;
}

sm|urlset::before, sm|sitemapindex::before {
  content: "XML Sitemap";
  display: block;
  font-size: 1.5rem;
  font-weight: 700;
  padding-bottom: 1rem;
}

/* Grid rows with a FIXED second column: rows without <lastmod> still span
   the full width, so borders and hover backgrounds never stop half-way
   (a CSS table row with a missing cell would). */
sm|url, sm|sitemap {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 15rem;
  align-items: center;
  border-bottom: 1px solid light-dark(#e5e7eb, #374151);
}
sm|url:hover, sm|sitemap:hover {
  background: light-dark(#f9fafb, #1f2937);
}

/* Hide every child (the <script> of Method B, image:*, xhtml:link, video:*, news:*, legacy tags)… */
sm|urlset > :not(sm|url), sm|sitemapindex > :not(sm|sitemap),
sm|url > *, sm|sitemap > * { display: none; }

/* …then show only the sitemap-namespace loc and lastmod (higher specificity) */
sm|url > sm|loc, sm|url > sm|lastmod,
sm|sitemap > sm|loc, sm|sitemap > sm|lastmod {
  display: block;
  padding: 12px 16px;
  font-size: 0.925rem;
}

sm|loc {
  grid-column: 1;
  font-weight: 500;
  color: light-dark(#1d4ed8, #93c5fd);
  word-break: break-all;
}

sm|lastmod {
  grid-column: 2;
  color: light-dark(#6b7280, #9ca3af);
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
```

Limitations:
* URLs are not clickable.
* An XML document has no viewport meta, so phones render the page at desktop width.
* Adding a stylesheet replaces the browser's built-in collapsible XML tree view.

### Method B — Embedded XHTML `<script>` (clickable, interactive)

**1. Add a single script element as the first child of the root:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
  <script xmlns="http://www.w3.org/1999/xhtml" type="text/javascript" src="/assets/sitemap-ui.js"></script>
  <url>
    <loc>https://example.com/</loc>
  </url>
</urlset>
```

**2. `/assets/sitemap-ui.js`:**
```javascript
(() => {
  const SM  = 'http://www.sitemaps.org/schemas/sitemap/0.9';
  const IMG = 'http://www.google.com/schemas/sitemap-image/1.1';
  const XH  = 'http://www.w3.org/1999/xhtml';
  const BATCH = 1000; // rows rendered per step; keeps 50,000-URL sitemaps responsive

  const CSS = `
    :root { color-scheme: light dark; }
    body { font-family: system-ui, sans-serif; max-width: 1080px; margin: 2.5rem auto; padding: 0 1rem;
           background: Canvas; color: CanvasText; }
    h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
    p.meta { opacity: .7; font-size: .9rem; margin: 0 0 1.5rem; }
    table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    th { text-align: left; padding: 10px 12px; border-bottom: 2px solid light-dark(#e5e7eb, #374151); }
    td { padding: 10px 12px; border-bottom: 1px solid light-dark(#e5e7eb, #374151); vertical-align: top; }
    td.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    tr:hover { background: light-dark(#f9fafb, #1f2937); }
    a { color: light-dark(#1d4ed8, #93c5fd); text-decoration: none; word-break: break-all; }
    a:hover { text-decoration: underline; }
    .sub { display: block; margin-top: 4px; font-size: .8rem; opacity: .8; }
    .hl { display: inline-block; margin: 4px 4px 0 0; padding: 0 6px; border-radius: 4px; font-size: .75rem;
          background: light-dark(#eef2ff, #1e293b); }
    button.more { margin: 1.5rem 0; padding: .5rem 1rem; font: inherit; cursor: pointer; }
  `;

  // Create an XHTML element; text goes through textContent, never through markup.
  const el = (tag, attrs = {}, text) => {
    const e = document.createElementNS(XH, tag);
    for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
    if (text != null) e.textContent = text;
    return e;
  };

  const isHttp = (url) => /^https?:\/\//i.test(url);

  // Only http(s) URLs become links; anything else is shown as plain text.
  const link = (url, text = url, attrs = {}) =>
    isHttp(url) ? el('a', { ...attrs, href: url }, text) : el('span', attrs, text);

  const first = (node, ns, name) =>
    node.getElementsByTagNameNS(ns, name)[0]?.textContent.trim() ?? '';

  const run = () => {
    const root = document.documentElement;
    if (root.namespaceURI !== SM) return; // not a sitemap, or already rendered

    const isIndex = root.localName === 'sitemapindex';
    const items = Array.from(root.getElementsByTagNameNS(SM, isIndex ? 'sitemap' : 'url'));

    // Reading the data is cheap even for 50,000 entries; building DOM nodes is not.
    const rows = items.map((node, i) => ({
      n: i + 1,
      loc: first(node, SM, 'loc'),
      lastmod: first(node, SM, 'lastmod') || '—',
      images: Array.from(node.getElementsByTagNameNS(IMG, 'loc'), e => e.textContent.trim()),
      alternates: Array.from(node.getElementsByTagNameNS(XH, 'link'))
        .filter(l => l.getAttribute('rel') === 'alternate')
        .map(l => ({ lang: l.getAttribute('hreflang') || '?', href: l.getAttribute('href') || '' })),
    }));

    const renderRow = (r) => {
      const tr = el('tr');
      const urlCell = el('td');
      urlCell.append(link(r.loc));
      for (const img of r.images) {
        const sub = el('span', { class: 'sub' }, '↳ ');
        sub.append(link(img));
        urlCell.append(sub);
      }
      if (r.alternates.length) {
        // hreflang codes as pills, linking to each alternate (hover shows the URL)
        const langs = el('span', { class: 'sub' });
        for (const a of r.alternates) langs.append(link(a.href, a.lang, { class: 'hl', title: a.href }));
        urlCell.append(langs);
      }
      tr.append(el('td', { class: 'num' }, String(r.n)), urlCell);
      if (!isIndex) tr.append(el('td', { class: 'num' }, String(r.images.length)));
      tr.append(el('td', { class: 'num' }, r.lastmod));
      return tr;
    };

    const label = isIndex ? 'sitemaps' : 'URLs';
    const html = el('html', { lang: 'en' });
    const head = el('head');
    head.append(
      el('meta', { charset: 'utf-8' }),
      el('meta', { name: 'viewport', content: 'width=device-width, initial-scale=1' }),
      el('title', {}, `XML Sitemap (${rows.length} ${label})`),
      el('style', {}, CSS),
    );

    const body = el('body');
    const meta = el('p', { class: 'meta' }, `This ${isIndex ? 'index' : 'sitemap'} lists `);
    meta.append(el('strong', {}, String(rows.length)), ` ${label}.`);

    const table = el('table');
    const thead = el('thead');
    const hr = el('tr');
    hr.append(el('th', {}, '#'), el('th', {}, isIndex ? 'Sitemap' : 'URL · images · hreflang'));
    if (!isIndex) hr.append(el('th', {}, 'Images'));
    hr.append(el('th', {}, 'Last modified'));
    thead.append(hr);
    const tbody = el('tbody');
    table.append(thead, tbody);

    // Render in batches: the first BATCH rows immediately, the rest on demand.
    let shown = 0;
    const more = el('button', { type: 'button', class: 'more' });
    const renderNext = () => {
      const frag = document.createDocumentFragment();
      const end = Math.min(shown + BATCH, rows.length);
      for (; shown < end; shown++) frag.append(renderRow(rows[shown]));
      tbody.append(frag);
      if (shown < rows.length) {
        more.textContent = `Show next ${Math.min(BATCH, rows.length - shown)} (${shown} of ${rows.length} shown)`;
      } else {
        more.remove();
      }
    };
    more.addEventListener('click', renderNext);

    body.append(el('h1', {}, 'XML Sitemap'), meta, table);
    if (rows.length > BATCH) body.append(more);
    renderNext();

    html.append(head, body);
    root.replaceWith(html);
  };

  // Mandatory: the script element sits BEFORE the <url> entries, so wait
  // until the whole document has been parsed before reading it.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
```

Why this version is safe and fast:
* `<loc>` values are **never** written into markup. In an XML document, `innerHTML` uses the XML fragment parser, which throws on a bare `&`. Building the DOM element by element avoids this and closes the injection path.
* Elements are resolved **by namespace**, so `image:loc` and `sm:loc` cannot be confused.
* **Batched rendering:** a full 50,000-URL sitemap with images would otherwise mean hundreds of thousands of DOM nodes built in one synchronous loop, freezing the tab for seconds. Only the first 1,000 rows are rendered; the rest load on demand.
* **`hreflang` audit:** alternates are shown as language pills under each URL, so missing or non-reciprocal alternates are visible at a glance.
* **No reliance on `defer`:** the `DOMContentLoaded` guard is what guarantees every `<url>` is parsed before rendering. That is why the `<script>` element in step 1 carries no `defer` attribute.
* Send a `Content-Security-Policy` that allows `/assets/sitemap-ui.js` if the site uses a strict CSP.

### Method C — Raw, unstyled XML (default)
Serve the sitemap with **no** stylesheet instruction. Chromium and Firefox render unstyled XML as a collapsible tree; Safari's rendering differs. Deleting the `<?xml-stylesheet type="text/xsl" …?>` line is the smallest possible fix and needs no maintenance.

### Method D — Official XSLT polyfill (keep the existing XSL)
If the current XSL template (for example, one shipped by a CMS or plugin) is acceptable, keep it and add one line, as recommended in Chrome's migration guide:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <script xmlns="http://www.w3.org/1999/xhtml" type="text/javascript" src="/assets/xslt-polyfill.min.js"></script>
  <!-- <url> entries -->
</urlset>
```

The polyfill detects the XSLT processing instruction and performs the transform itself in WASM, without using the browser's native XSLT.

Trade-offs:
* **It does not remove Chrome's XSLT warnings.** The `<?xml-stylesheet type="text/xsl"?>` instruction stays in the file, so Chrome still detects it: deprecation messages in DevTools and Lighthouse, and possibly the in-page XSLT banner once native XSLT is disabled. Treat Method D as a bridge, not the end state.
* While native XSLT is still enabled in a browser, the browser transforms the document itself and the polyfill does not run; the polyfill only takes over where native XSLT is gone.
* It adds a WASM payload (loaded only by human viewers).
* It keeps an XSLT 1.0 template alive in the codebase.
* Self-host the polyfill file; do not hot-link it.

---

## 6. Serving & Deployment

### 6.1 HTTP response headers
Target headers for every sitemap file:

```
HTTP/1.1 200 OK
Content-Type: application/xml; charset=utf-8
X-Robots-Tag: noindex
Cache-Control: public, max-age=3600
ETag: "<hash of the response body>"
```

* `X-Robots-Tag: noindex` keeps the `.xml` file itself out of search results. It does **not** affect the URLs listed inside it. `follow` has no meaning here, so omit it.
* `text/xml` is also accepted. `application/xml` is preferred.
* **Conditional requests: validate on the content, not on `<lastmod>`.** Google's crawlers support both `ETag`/`If-None-Match` and `Last-Modified`/`If-Modified-Since`.
  * Use a **content-hash `ETag`**: it changes on additions, edits **and removals**.
  * Do **not** derive `Last-Modified` from the newest `<lastmod>` in the file. Removing a URL leaves that value unchanged (or moves it backwards), so crawlers get `304 Not Modified` and never see the removal. It is also undefined when no URL has a `<lastmod>` (§1.4).
  * If you also send `Last-Modified`, set it to the time the sitemap **content** last changed, removals included.
  * Static files served by nginx already get an `ETag` and a file-mtime `Last-Modified` by default; both change when the file is regenerated, so nothing extra is needed there.

### 6.2 Static sitemaps — nginx
Use this only when the files exist on disk:

```nginx
# Static sitemap files only.
# Do NOT use this block when sitemaps are generated by the application (see 6.3).
location ~* ^/sitemap[^/]*\.xml$ {
    types { application/xml xml; }   # override mime.types (.xml → text/xml by default)
    charset utf-8;
    charset_types application/xml;   # application/xml is not in the default charset_types
    add_header X-Robots-Tag "noindex" always;
    # No "always" here: caching headers must not be attached to 404/5xx
    # responses, or a CDN would cache an error for an hour.
    add_header Cache-Control "public, max-age=3600";
    # add_header in a location REPLACES all inherited add_header lines:
    # re-declare server-level security headers (HSTS, X-Content-Type-Options, …) here.
}
```

* Without `always`, nginx adds a header only to `200`, `201`, `204`, `206`, `301`, `302`, `303`, `304`, `307` and `308` responses. Keep `always` on `X-Robots-Tag`, never on `Cache-Control`.

* **Prefer transport compression** (`gzip on; gzip_types application/xml;`) over `.xml.gz` files.
* If you do serve `.xml.gz`, give those files their own `location` with `types { application/gzip gz; }`, never `application/xml`.

### 6.3 Dynamic sitemaps — Bolt CMS / Symfony / PHP
When a route such as `/sitemap.xml` is rendered by the application, a regex `location` in nginx takes the request away from the front controller and returns **404**. Leave routing to the app and set the headers in the response.

Symfony example:

```php
use Symfony\Component\HttpFoundation\Response;

$response = new Response($xml, Response::HTTP_OK, [
    'Content-Type' => 'application/xml; charset=UTF-8',
    'X-Robots-Tag' => 'noindex',
]);
$response->setPublic();
$response->setMaxAge(3600);

// Content-hash ETag: changes on additions, edits AND removals (see 6.1).
// xxh128 needs PHP 8.1+; use md5() on older versions.
$response->setEtag(hash('xxh128', $xml));

// Turns the response into an empty 304 when If-None-Match matches.
$response->isNotModified($request);

return $response;
```

* Cache the generated XML (and its hash) and invalidate it when a record is created, updated, unpublished or deleted. Otherwise every crawler request regenerates the whole sitemap just to compute the hash.

**Bolt CMS:** override the sitemap Twig template in your theme. Check the template and XSL files bundled with your Bolt version. In the override:
* remove the XSL instruction (or apply Method D);
* remove `<changefreq>` and `<priority>`;
* output a real per-record `lastmod` (the record's modification date);
* keep `<?xml` on the very first line.

### 6.4 `robots.txt`
```text
User-agent: *
Disallow:

Sitemap: https://example.com/sitemap.xml
```
* The `Sitemap:` line must be an absolute URL.
* It may appear **anywhere** in the file, independent of `User-agent` groups.
* Multiple `Sitemap:` lines are allowed. Point to the index when you have one.
* `Allow: /` alone is redundant; an empty `Disallow:` means the same thing.

### 6.5 Submission & change notification
* **Google:** submit the sitemap in **Search Console**. The anonymous `google.com/ping?sitemap=` endpoint was deprecated in 2023 and no longer works.
* **Bing:** submit in **Bing Webmaster Tools**, or rely on the `robots.txt` line. Bing fetches a sitemap immediately on submission and re-reads it regularly. Bing's anonymous ping is also deprecated.
* **Seznam:** submit in **Seznam Webmaster** (webmaster.seznam.cz).
* **IndexNow:** an optional push API supported by Bing, Yandex, Seznam, Naver, and Yep. **Google does not participate.**
  * Call IndexNow when a page is created, updated, or deleted.
  * It is independent of the sitemap and complements it rather than replacing it.
  * It requires a key file hosted on your domain.

---

## 7. Pre-Go-Live Validation Checklist

* [ ] **First bytes are `<?xml`**, with no BOM or whitespace: `curl -s https://example.com/sitemap.xml | head -c 5 | xxd` → `3c3f 786d 6c` (`<?xml`).
* [ ] **Well-formed:** `curl -s https://example.com/sitemap.xml | xmllint --noout -`
* [ ] **Schema-valid.** Download the XSDs first: `xmllint` cannot fetch `https://` schema locations.
  * Sitemap **index** → `siteindex.xsd` (not `sitemap.xsd`):
    `xmllint --noout --schema siteindex.xsd sitemap.xml`
  * Urlset **without** foreign elements → `xmllint --noout --schema sitemap.xsd sitemap-pages.xml`
  * Urlset **with** extensions (`image:`, `xhtml:link`) or a Method B/D `<script>`: the XSD's wildcards use `processContents="strict"`, so `sitemap.xsd` alone fails on the first foreign element. Validate against a local wrapper that imports every namespace used:
    ```xml
    <?xml version="1.0" encoding="UTF-8"?>
    <!-- sitemap-all.xsd — all schemaLocations point to downloaded local copies -->
    <xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema">
      <xsd:import namespace="http://www.sitemaps.org/schemas/sitemap/0.9"
                  schemaLocation="sitemap.xsd"/>
      <xsd:import namespace="http://www.google.com/schemas/sitemap-image/1.1"
                  schemaLocation="sitemap-image.xsd"/>
      <xsd:import namespace="http://www.w3.org/1999/xhtml"
                  schemaLocation="xhtml1-strict.xsd"/>
    </xsd:schema>
    ```
    `xmllint --noout --schema sitemap-all.xsd sitemap-pages.xml`
    Sources: `https://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd`, `…/siteindex.xsd`, `https://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd`, `https://www.w3.org/2002/08/xhtml/xhtml1-strict.xsd`. `xhtml1-strict.xsd` imports `xml.xsd` from w3.org: download that too and point the import at the local copy.
  * Search Console's sitemap report remains the authoritative check for Google; XSD validation catches structural errors before deployment.
* [ ] **Headers:** `curl -sI https://example.com/sitemap.xml` → `200`, `application/xml; charset=utf-8`, `X-Robots-Tag: noindex`, an `ETag`.
* [ ] **Every listed URL returns `200`** — portable (Linux and macOS), parallel, and redirects are reported rather than followed:
  ```bash
  curl -s https://example.com/sitemap-pages.xml \
    | xmllint --xpath "//*[local-name()='url']/*[local-name()='loc']/text()" - \
    | sed -e "s/&apos;/'/g" -e 's/&quot;/"/g' -e 's/&amp;/\&/g' \
    | tr '\n' '\0' \
    | xargs -0 -n 1 -P 10 sh -c '
        code=$(curl -s -o /dev/null -w "%{http_code}" "$1")
        [ "$code" = 200 ] || echo "$code $1"' _
  ```
  * `xmllint --xpath` works on minified files and selects only `<loc>` directly under `<url>`, so `image:loc` is excluded.
  * `xmllint` prints text nodes still XML-escaped (`&amp;`), hence the `sed` decode; `&amp;` is decoded last so it cannot double-decode.
  * Do **not** add `curl -L`: following redirects would report a `301` as `200` and hide exactly the URLs this check is meant to find.
  * Tested with libxml2 2.9.14; very old `xmllint` builds print text nodes without newlines between them.
* [ ] Every `<loc>` equals that page's `rel="canonical"`, and no listed page is `noindex` or disallowed in `robots.txt`.
* [ ] No `<changefreq>` or `<priority>`. No `<?xml-stylesheet type="text/xsl"?>` unless Method D is used.
* [ ] `lastmod` changes only on real content changes. Test: deploy a CSS-only change, then diff the sitemap (expected: no change).
* [ ] `hreflang`: every variant has its own `<url>` entry with the full reciprocal set including itself, and every alternate URL is `200`, canonical, and indexable.
* [ ] Non-ASCII characters in URL paths are percent-encoded; non-ASCII hosts are Punycode.
* [ ] No hardcoded timezone offsets in `lastmod` templates (§1.4).
* [ ] The sitemap URL returns the same XML regardless of `Accept` header or user agent (§5).
* [ ] Removing a URL changes the sitemap's `ETag` and its child-sitemap `lastmod` in the index (test: unpublish one page, re-fetch with `If-None-Match` → expect `200`, not `304`).
* [ ] The index references no other index, and each child's `lastmod` reflects that child's last content change (§4.1).
* [ ] Submitted in Google Search Console, Bing Webmaster Tools, and Seznam Webmaster; no parsing errors reported.
* [ ] The human view works in Chrome with XSLT disabled (Canary, or the `XSLTEnabled` enterprise policy set to false).

---

## 8. References

**Protocol & search engines**
* Sitemaps.org protocol — https://www.sitemaps.org/protocol.html
* Sitemaps.org XSD (urlset) — https://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd
* Sitemaps.org XSD (sitemap index) — https://www.sitemaps.org/schemas/sitemap/0.9/siteindex.xsd
* Google image sitemap XSD — https://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd
* XHTML 1.0 Strict XSD — https://www.w3.org/2002/08/xhtml/xhtml1-strict.xsd
* Google: Build and submit a sitemap — https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap
* Google: Large sitemaps and sitemap index files — https://developers.google.com/search/docs/crawling-indexing/sitemaps/large-sitemaps
* Google: Localized versions of your page (`hreflang`) — https://developers.google.com/search/docs/specialty/international/localized-versions
* Google: Image sitemaps — https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps
* Google: Image and video sitemap tag deprecations (May 2022) — https://developers.google.com/search/blog/2022/05/spring-cleaning-sitemap-extensions
* Google: Crawling December — HTTP caching (ETag / Last-Modified support, Dec 2024) — https://developers.google.com/search/blog/2024/12/crawling-december-caching
* Google: News sitemaps — https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap
* Google: Video sitemaps — https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps
* Google: Sitemaps ping endpoint deprecation and `lastmod` (2023) — https://developers.google.com/search/blog/2023/06/sitemaps-lastmod-ping
* Google: Robots meta tag and `X-Robots-Tag` — https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag
* Bing: The importance of setting the `lastmod` tag (Feb 2023) — https://blogs.bing.com/webmaster/february-2023/The-Importance-of-Setting-the-lastmod-Tag-in-Your-Sitemap
* Bing: Keeping content discoverable with sitemaps in AI-powered search (Jul 2025) — https://blogs.bing.com/webmaster/July-2025/Keeping-Content-Discoverable-with-Sitemaps-in-AI-Powered-Search
* Seznam Webmaster — https://webmaster.seznam.cz
* IndexNow protocol — https://www.indexnow.org

**XSLT removal**
* Chrome for Developers: Removing XSLT for a more secure browser — https://developer.chrome.com/docs/web-platform/deprecating-xslt
* Chrome Platform Status entry — https://chromestatus.com/feature/4709671889534976
* WHATWG HTML issue #11523 (removal discussion) — https://github.com/whatwg/html/issues/11523
* XSLT polyfill (Mason Freed) — https://github.com/mfreed7/xslt_polyfill
* Chrome Enterprise policy `XSLTEnabled` — https://chromeenterprise.google/policies/#XSLTEnabled

**Engineering**
* RFC 3986 (URI syntax and percent-encoding) — https://www.rfc-editor.org/rfc/rfc3986
* RFC 5891 (IDNA 2008, Punycode host names) — https://www.rfc-editor.org/rfc/rfc5891
* PHP `idn_to_ascii()` — https://www.php.net/manual/function.idn-to-ascii.php
* MDN: `Element.innerHTML` (XML documents use the XML fragment parser) — https://developer.mozilla.org/docs/Web/API/Element/innerHTML
* MDN: CSS `@namespace` — https://developer.mozilla.org/docs/Web/CSS/@namespace
* MDN: CSS `light-dark()` — https://developer.mozilla.org/docs/Web/CSS/color_value/light-dark
* nginx `charset` / `charset_types` — https://nginx.org/en/docs/http/ngx_http_charset_module.html
* nginx `add_header` (inheritance, `always`, status codes) — https://nginx.org/en/docs/http/ngx_http_headers_module.html
* Symfony HTTP cache (validation, `isNotModified`) — https://symfony.com/doc/current/http_cache/validation.html
