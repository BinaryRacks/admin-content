# Security review: Binary Racks WhatsApp Notifications 1.0.0

Date: 13 Nov 2026. Scope: every file in `modules/addons/br_whatsapp/` (38 PHP files + `.htaccess`).
Method: line-by-line code review, 102 automated attack and feature tests, and live attacks against real
**Apache 2.4 (.htaccess on)**, **nginx 1.24 "nginx only" with the README rule** and **nginx with no rule**,
all with PHP 8.3 (tests/webserver/attack.php, 67 direct-access tricks per server + 8 webhook attacks).

## What held up (attacks that failed)
| Attack | Result |
|--------|--------|
| Opening any module file directly, incl. `//`, `../`, `%2e`, `%2f`, wrong case, null byte, `::$DATA`, `.bak`, PATH_INFO | Apache: 403. nginx: empty page or 404. No source, data, paths or errors leaked on any server. |
| Forged webhook: no signature, wrong signature, other secret, body changed after signing | 403, nothing changed |
| Webhook verify with wrong token / script in `hub.challenge` | 403 / 400, nothing echoed |
| Oversized, malformed and deeply nested webhook bodies | 413 / 400 |
| Script (XSS) in client names, company, message names, ticket subjects, Meta errors | escaped everywhere |
| Forged admin forms (no token, wrong token, GET instead of POST) | rejected, nothing changed |
| SQL injection in log filters, role lists, settings | stored safely or rejected (bound values only) |
| Pointing the module elsewhere (SSRF) via settings (`../`, `?`, metadata IP) | rejected; host fixed in code; private/metadata IPs blocked |
| Stealing secrets | never in page HTML, stored encrypted, not logged; changing the number ID forces retyping the token |
| Admin roles doing more than allowed, double-send by refresh | blocked on the server; one-time codes |
| Someone else's invoice in a message | refused (ownership check) |

## Findings: your decision needed for each (fix, change, or accept the risk)
| # | Severity | Finding | Proven? |
|---|----------|---------|---------|
| 1 | Medium | The nginx rule in README doesn't block PHP files on Plesk "nginx only" (Plesk puts extra rules after its PHP handler). The PHP guard still shows an empty page, so nothing leaks. Without the rule, `.htaccess` (only comments and rules) is readable. | Yes: 200 + empty body for `lib/Settings.php`; `.htaccess` readable on nginx without rule |
| 2 | Medium | Webhook rate limit can be bypassed by rotating IPv6 addresses, and every unsigned request adds database rows that are only cleaned daily (database growth). | Yes: 5,000 requests from one /64 → 0 blocked, 10,000 rows |
| 3 | Low | "Full Administrator" is recognised by role ID 1 only. If role 1 is ever edited to have fewer rights, it still gets full module rights. | By reading the code |
| 4 | Low | Wrong verify-token attempts (GET handshake) don't count toward the stricter "bad attempt" limit. Guessing a 16+ character token is still impossible in practice. | By reading the code |
| 5 | Low (reliability) | Starting a very large campaign queues every recipient inside one admin page request; tens of thousands of clients could hit PHP's time limit and leave a half-queued campaign. | By reading the code |
| 6 | Info | A STOP from a phone number shared by several client accounts ticks `disable_whatsapp` on all of them. | Test `testStopFromUnknownNumberChangesNoClient` and code |

## Decisions and fixes
| # | Decision | What was done | Proof |
|---|----------|---------------|-------|
| 1 | **Fix** | README nginx rule replaced by a `location ^~` rule that wins over Plesk's PHP handler; only `webhook.php` is passed to PHP, everything else in the folder gets 404. The rule is kept in `tests/webserver/nginx-module-rule.conf`. `tests/webserver/attack.php --strict` now requires the server itself to refuse every non-webhook path. | Strict run: Apache and nginx-with-rule ALL SAFE (68 paths each). nginx without the rule: 50 paths answered and `.htaccess` readable, so the test catches a missing rule. |
