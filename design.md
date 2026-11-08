# HarborCache design system

Status: corrected after the design review. This is a specification, not an implemented refresh. Brand colors are fixed.

## Brand and direction

HarborCache is an operations tool. The interface should feel calm, exact and inspectable: a teal signal on warm off-white, strong hierarchy, thin borders, flat surfaces, and no decorative gradients or shadows. The palette is the existing brand:

| Token | Value | Use |
| --- | --- | --- |
| `--ch-accent` | `#0b615e` | primary action, active tab, chart line, ready state |
| `--ch-accent-soft` | `#e9f5f0` | selected rows, connected surface |
| `--ch-ink` | `#182c32` | primary text |
| `--ch-ink-strong` | `#172b32` | display headings |
| `--ch-muted` | `#485e65` | secondary text, labels |
| `--ch-border` | `#bdcaca` | panel and table borders |
| `--ch-border-soft` | `#dbe4e2` | chart grid, dividers |
| `--ch-surface` | `#ffffff` | panels and controls |
| `--ch-surface-alt` | `#f8fbfa` | alternate table rows |
| `--ch-warn-bg` | `#fff5da` | degraded surface |
| `--ch-warn-border` | `#806325` | degraded border |
| `--ch-warn-ink` | `#624810` | degraded text |
| `--ch-bad` | `#8a3b12` | unreachable, codec failure, destructive border |
| `--ch-neutral` | `#6f7d7d` | disabled, not-installed, neutral dot |
| `--ch-focus` | `#135ead` | focus ring |

The admin page background stays WordPress grey. `--ch-bg: #f3f7f5` is for scoped panels and previews only; do not repaint the entire admin.

## Type

System UI stack only. No remote fonts, no new dependency. Rules key to selectors, not generic tags.

- Page/tab title: 26px/1.25, 600, ink-strong.
- Panel head (`.ch-panel > h2`): 20px/1.3, 600.
- Sub-group (`.ch-panel > h3`): 16px/1.4, 600.
- Body: 16px/1.6.
- Label/meta: 13px/1.45, muted.
- Metric value: 28px/1.1, 600, tabular numbers.
- Table head (`.widefat thead th`): 14px/1.4, 600, muted.
- Controls: 16px, 44px minimum height.
- Widget: 14px body, 20px metric, 13px meta; the same status dot and label as the dashboard.

## Spacing, shape, depth

- Spacing: 4, 8, 12, 16, 24, 32.
- Panel padding 20–24px; table cells 12–14px 16px.
- Radius: 8px panels, 6px controls, 4px chips. Apply these explicitly; WordPress's 3px default must not leak through.
- Borders: 1px `--ch-border`; alternate rows `--ch-surface-alt`; no nested cards.
- One optional shadow only for a sticky control: `0 1px 2px rgba(23,43,50,.06)`.

## Layout

- `#harborcache.ch-console { max-width: 1180px; margin-inline: auto; }` Reuse WordPress's gutters; do not add 24px inside a 375px screen.
- Status: identity row, readiness panel, metric strip, chart, connection summary.
- Readiness: 2 columns above 782px, 1 below.
- Metrics: 4 columns at 1100px and above, 2 at 783–1099px, 1 below 783px. Remove the right border on the last tile of each row.
- Forms: 2 columns above 782px, 1 below. Labels above inputs; help text below.
- Tables: wrap in `.ch-table-scroll` with `tabindex="0"`, `role="region"` and an `aria-label`; add a visible focus ring. Allow horizontal scroll.
- Navigation: WordPress tabs; active tab has a 3px teal underline. Chart metric buttons are `aria-pressed` controls, not navigation tabs; do not style them identically.

## Status model

`Status::state_for()` has six states. One status component must cover all of them, including the dashboard widget.

| State | Class | Surface | Dot/text |
| --- | --- | --- | --- |
| `connected` | `is-connected` | accent-soft | accent |
| `degraded` | `is-degraded` | warn | warn-ink |
| `unreachable` | `is-disconnected` | white, bad border | bad |
| `not-loaded` | `is-disconnected` | white, bad border | bad |
| `disabled` | `is-disabled` | white, neutral border | neutral |
| `not-installed` | `is-not-installed` | white, neutral border | neutral |

Status is always dot plus text, never color alone. Use one grouped selector for `.ch-status`, `.ch-health-chip` and the widget status, with a `::before` dot. Minimum 32px high.

## Components

### Panels
White surface, 1px border, 8px radius, 20–24px padding. Section head and optional aside action in the first row. No nested cards; `.ch-probe` becomes a banner with a left border, no full border and no tinted panel.

### Metrics
Per-request counters, not time-windowed metrics. Each tile: 13px muted label, 28px tabular value, one supporting sentence where useful. The chart owns the time window and must state it in the caption.

### Chart
Teal line, 12% fill, `#dbe4e2` grid, 15px axis labels where space allows. Use a fixed `viewBox` plus an `overflow-x:auto` container below 600px. Without JavaScript, show the existing static SVG with a readable fallback; state that the chart is not interactive without JS. Controls are 44px buttons with an active underline, not links.

### Tables
Header muted and 600; separators `#e7efec`; hover `--ch-surface-alt`; row actions 44px. Group names may use a monospace-adjacent stack because they are identifiers. Long names wrap with `overflow-wrap:anywhere`.

### Forms and buttons
Primary: teal background, white text. Secondary: white, border, ink. Destructive: white background, `--ch-bad` border and ink; add `.ch-destructive` and pass it for flush, flush-global and disable actions. Keep primary for Save, Connect and Test.

Every control needs default, hover, focus-visible, active, disabled, invalid and pending (`aria-busy="true"`) styles. Focus ring: 3px `--ch-focus`, 2px offset, instant. The 44px rule applies to controls, tabs, inputs and row actions; inline body links and 32px table-row controls are exempt.

### Notices and empty states
Use WordPress notice classes plus a left border in the state color. Empty states state what is missing and the next action; no illustrations. Demonstration analytics data must be labelled if used.

## Headings and content

- One H1 per page; panel heads are H2; sub-groups are H3.
- The first screen answers: connected, namespace owned, codec round-trip, drop-in current.
- Settings groups match the shipped form: Connection, Advanced settings, Cache safety, Performance and maintenance, Diagnostics and analytics, Analytics sampling. An "Access" group does not exist; do not promise it.
- Chart caption includes window, sample count and delay.
- Diagnostics are on demand, bounded and copyable.

## Motion and accessibility

- Only `transform` and `opacity`, 120–160ms. No `transition: all`; no width/height/top/left animation.
- `prefers-reduced-motion: reduce` removes non-essential motion.
- WCAG AA text contrast; decorative borders and grid lines are not text and must not be relied on for meaning.
- Keyboard: visible focus on every control and scroll region; no trap.
- Content visible with JS off; chart fallback stated.
- Touch targets 44×44px for controls; 32px minimum for dense table rows.
- No emoji icons, no decorative dots without text, no invented metrics.

## Implementation boundary

CSS-first refresh. Reuse the existing `#harborcache` markup and JS; add semantic wrappers only where required. Files: `assets/css/console.css`, `assets/css/chart.css`, minimum PHP templates in `includes/class-console.php`. No new build step, font, icon library or framework.

Tests pin exact class strings (`tests/console-render.php` matches `ch-panel ch-health` and `ch-status is-connected`). Keep those tokens or update the tests with the CSS change.

## Verification

- Offline suites and the full matrix; Plugin Check clean.
- Screenshots at 1440, 1100, 1024, 782, 600 and 375 for Status, Settings, Cache, Analytics, Diagnostics and the dashboard widget.
- Keyboard-only pass, 200% zoom, reduced-motion pass, and a no-JS chart check.
