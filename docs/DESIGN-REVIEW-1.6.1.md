# EGM review: design system, roadmap and concept (1.6.1)

Targets: `design.md`, `ROADMAP.md`, `concepts_brief.md`. Two fresh reviewers ran the egm-review/direct-dispatch pass: a UI/UX and accessibility reviewer using the ui-ux-pro-max and Hallmark standards, and an engineering/product reviewer. No dashboard code was built.

## Verdict

**Both documents were not ready as written. They are corrected now.** The design direction and the 1.6/1.7/1.8/2.0 order survived; the concrete state model, component layer, acceptance criteria, co-installation order and 2.0 migration story did not.

## Design review findings and disposition

| Severity | Finding | Disposition |
| --- | --- | --- |
| BLOCKER | The design used ready/degraded/failed, but the code has six states and no `.is-failed`/`.is-disconnected` | Fixed: six-state table, classes, dot+text rule |
| BLOCKER | Destructive flush was styled as the teal primary action | Fixed: `.ch-destructive` rule and action mapping |
| BLOCKER | No shared token/component layer; the widget uses a third status presentation | Fixed: one token block for both roots, grouped status selector, widget covered |
| MAJOR | Breakpoints contradicted the stylesheet and ignored the WP sidebar | Fixed: 782/1100 and 783–1099 rules |
| MAJOR | The content column was not centred | Fixed: `margin-inline:auto` |
| MAJOR | The chart is JavaScript-dependent and unreadable at narrow widths without JS | Fixed: explicit no-JS fallback and overflow rule |
| MAJOR | Table scroll regions had no focus target | Fixed: `tabindex`, `role=region`, `aria-label`, focus rule |
| MAJOR | Pending/disabled/invalid states were promised but absent | Fixed: required state list |
| MAJOR | Heading outline breaks on four tabs | Fixed: H1/H2/H3 map |
| MAJOR | `.ch-probe` was a nested card | Fixed: banner treatment |
| MAJOR | Settings groups did not match the shipped form | Fixed: exact shipped groups |
| MAJOR | Metric strip promised a time window it does not have | Fixed: per-request counters; chart owns the window |
| MAJOR | Radius system was never applied | Fixed: explicit panel/control/chip radii |
| MAJOR | Type scale did not match the markup | Fixed: selector-keyed rules |
| MAJOR | 44px applied to every control, including dense table rows | Fixed: controls/tabs/inputs/row actions; 32px dense rows |
| NIT | Focus ring offset differed | Fixed: 2px |
| NIT | `min-width` vs fixed width on the status column | Fixed |
| NIT | Last metric tile kept its border | Fixed |
| NIT | Two bad reds and a weak neutral | Fixed: `--ch-bad`, `-neutral` |
| NIT | Token noise and admin background | Fixed: removed `--ch-ink-strong`-style duplication; admin grey accepted |
| NIT | Low-contrast decorative borders | Fixed: stated as decorative |
| NIT | Dead requirements and untested widget | Fixed: removed/conditioned; widget added to verification |
| NIT | Tests pin exact class strings | Fixed: implementation boundary notes it |
| NIT | Verification only covered 1440/375 | Fixed: 1440/1100/1024/782/600/375 plus widget |

## Roadmap review findings and disposition

| Severity | Finding | Disposition |
| --- | --- | --- |
| BLOCKER | 2.0 mixed dual-read, reversible and no-data-loss claims that cannot all be true | Fixed: one strategy (layout-signature bump, measured cold start; rollback = cold misses) |
| BLOCKER | Co-installation risk was scheduled after WordPress.org submission | Fixed: 1.6.2 arbitration guard, both-order CI test, dated before broad promotion |
| BLOCKER | Key-memory premise was unmeasured and the quoted 190 bytes was low | Fixed: 10k-key sample, 200/231 byte current range, material-share gate |
| MAJOR | 1.7 re-added `watch`, `digest` and `/health`, which already exist | Fixed: improve them, do not re-add |
| MAJOR | Unit tests were placed after the work they protect | Fixed: 1.8, before 2.0 |
| MAJOR | Logged-in front-end `class-console.php` cost was missing | Fixed: 1.7 measured deferral |
| MAJOR | Rate-limit transient would be erased by the flush it protects | Fixed: user meta/owner namespace, 1/min and 10/hour, 429, test |
| MAJOR | Granular Site Health would duplicate rules across three surfaces | Fixed: one `Status::health_facts()` provider first |
| MAJOR | Relay gate ranked below cosmetics | Fixed: 1.8 gate before 1.9 refresh, or explicitly unsupported |
| MAJOR | Cosmetic release between correctness and performance | Fixed: refresh follows 1.7/1.8, not a standalone 1.7.x |
| MAJOR | Chart-control behavior contradicted "no behavior change" | Fixed: keep existing controls and test them, or remove from scope |
| MAJOR | Admin-post global flush still lacked the REST network check | Fixed: 1.6.2 item |
| MAJOR | Security/migration scope incomplete (`unserialize`, owner key TTL, key families, mixed versions) | Fixed: 1.6.2 warning, 2.0 key-family inventory and mixed-version test |
| NIT | "Roughly 190" and untestable acceptance criteria | Fixed: measured range and command/number criteria |

## What remains unknown

- The actual key share of Redis memory. The 2.0 premise depends on a 10k-key sample; the roadmap gates it.
- Live Relay failover behavior; the gate is defined but not run.
- The reverse-order behavior with another object-cache plugin under a real WordPress activation sequence; the 1.6.2 test is specified, not executed.
- The dashboard's rendered appearance at the listed widths; no build was requested, so no screenshots were taken.

## Ponytail note

The corrections are documentation-only. No CSS, PHP or JavaScript was changed for the redesign. The roadmap now removes already-shipped work, moves the safety guard before promotion, and gates 2.0 on a measurement instead of a copied number.

## Status

`design.md`, `ROADMAP.md` and `concepts_brief.md` are corrected. The 1.6.1 code PR remains the release vehicle; the dashboard refresh is a later implementation task.
