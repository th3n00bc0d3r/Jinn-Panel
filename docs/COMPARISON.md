# How JinnPanel compares

Honest positioning, not a sales pitch - JinnPanel is a young project;
several of these have 10-20+ years of production hardening behind them.
This is here to help you decide if it fits your use case, not to claim
it's better across the board.

| | **JinnPanel** | cPanel/WHM | Plesk | CyberPanel | HestiaCP | ISPConfig |
|---|---|---|---|---|---|---|
| **License / cost** | MIT, free | Commercial, paid per account | Commercial, paid per account | Free (OpenLiteSpeed) / paid tiers | Free, open source | Free, open source (+ paid ISPConfig 3 Perfect) |
| **Web server** | FrankenPHP (Caddy + embedded PHP) | Apache/LiteSpeed/NGINX | Apache/NGINX | OpenLiteSpeed/LiteSpeed | Apache/NGINX | Apache/NGINX |
| **Mail server** | Stalwart (single Rust binary: SMTP+IMAP+JMAP) | Exim + Dovecot | Postfix + Dovecot | Rspamd + Postfix/Dovecot | Exim + Dovecot | Postfix + Dovecot |
| **DNS server** | Knot DNS | PowerDNS/BIND | BIND | PowerDNS | BIND | BIND/PowerDNS |
| **SFTP** | SFTPGo (virtual users via REST API, no local Unix accounts) | Local Unix accounts | Local Unix accounts | Local Unix accounts | Local Unix accounts | Local Unix accounts |
| **Multiple PHP versions** | Yes - each version a fully isolated process | Yes (EasyApache/CloudLinux) | Yes | Yes | Yes | Yes |
| **AutoSSL** | Yes - Caddy's native ACME, zero custom code | Yes (cPanel AutoSSL) | Yes (Let's Encrypt) | Yes | Yes | Yes |
| **Reseller accounts** | Yes | Yes | Yes (via Power Pack) | No (single-tenant) | No | Yes |
| **Config surface** | Direct access to ~150 Stalwart settings via a schema-driven UI; PHP/MariaDB/SFTPGo tuning with a computed one-click profile | Deep, mature, GUI-first | Deep, mature, GUI-first | Moderate | Moderate | Moderate |
| **Architecture** | Plain PHP, no framework, ~4k LOC app code, everything readable in an afternoon | Large proprietary C/Perl codebase | Large proprietary codebase | Python + Django | PHP | PHP |
| **Maturity** | New (2026) | 25+ years, industry standard | 20+ years | ~5 years | ~10 years (fork of VestaCP) | ~15 years |
| **Support** | Community (GitHub issues) | Paid vendor support | Paid vendor support | Community + paid | Community | Community |

## Where JinnPanel is a genuinely different choice, not just a free clone

- **The whole stack is modern and small.** Postfix+Dovecot+BIND+Apache+PHP-FPM
  is ~15-20 years of accumulated config surface across five separate,
  loosely-related projects. FrankenPHP+Stalwart+Knot+SFTPGo is four binaries,
  each built in the last few years with a coherent, often API-first design -
  Stalwart in particular replaces two mail daemons (SMTP+IMAP) with one, and
  exposes essentially its entire configuration surface through a structured
  API rather than scattered flat files.
- **SFTP has no local Unix users at all.** Every other panel in this table
  creates a real system account per FTP/SFTP user. JinnPanel's are entirely
  virtual (SFTPGo + REST API), which is a meaningfully smaller attack
  surface and avoids `/etc/passwd` sprawl on a busy multi-tenant box.
- **The admin config UI is generated from Stalwart's own schema**, not
  hand-built per setting - meaning as Stalwart adds new settings objects,
  they become editable in JinnPanel's WHM without anyone writing new PHP
  for them.
- **Multi-PHP-version support is real process isolation**, not a
  PHP-FPM-pool config switch - each additional version is a fully separate
  FrankenPHP instance with its own PHP shared library.

## Where the established panels are still the safer choice

- **Production track record.** cPanel and Plesk have been battle-tested
  against real-world abuse, edge cases, and hosting-scale load for decades.
  JinnPanel hasn't.
- **Breadth of integrations.** Backup providers, billing/WHMCS integration,
  marketplace app installers, migration tools from other panels - cPanel/
  Plesk/CyberPanel have large ecosystems here that JinnPanel doesn't have
  yet.
- **Paid support.** If "someone I can call at 3am" matters, that's cPanel,
  Plesk, or CyberPanel's paid tier, not this.
- **Disk/bandwidth quota enforcement.** JinnPanel tracks quotas as numbers
  today, not real filesystem-level enforcement (documented in
  `docs/FEATURES.md`) - the established panels enforce this properly.

## Who JinnPanel is actually for

Self-hosters and small teams who want to understand and own every part of
their hosting stack, are comfortable reading PHP and shell when something
needs fixing, and would rather run four small modern daemons than five
large legacy ones - not (yet) hosting companies running thousands of
paying customer accounts unattended.
