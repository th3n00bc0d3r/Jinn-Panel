<p align="center">
  <img src="assets/brand/logo.svg" alt="JinnPanel" width="360">
</p>

<p align="center">
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-plain%2C%20no%20framework-777bb4.svg">
  <img alt="Platform" src="https://img.shields.io/badge/platform-AlmaLinux%2010-1c1c1c.svg">
  <a href="CODE_OF_CONDUCT.md"><img alt="Contributor Covenant" src="https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa.svg"></a>
</p>

# JinnPanel

A WHM/cPanel-style hosting control panel: plain PHP + Tailwind CSS, running
on a from-scratch, modern stack - FrankenPHP, MariaDB, Stalwart Mail,
SFTPGo, Knot DNS - on AlmaLinux. Built to be genuinely deployable on a
fresh box with one script, not just a demo on the one it was developed on.

See [`docs/COMPARISON.md`](docs/COMPARISON.md) for how this differs from
cPanel, Plesk, and other panels, and who it's actually for.

## Get started

- **Quick setup** (copy/paste, running in minutes) →
  [`docs/INSTALL.md#quick-setup`](docs/INSTALL.md#quick-setup)
- **Detailed setup** (what each step does, options, how to verify as you
  go) → [`docs/INSTALL.md#detailed-setup`](docs/INSTALL.md#detailed-setup)

The short version:

```bash
scp -r JinnPanel root@your-server-ip:/root/
ssh root@your-server-ip
cd /root/JinnPanel/installer
chmod +x install.sh
./install.sh
```

Non-interactive, no prompts, every credential randomly generated. When it
finishes it prints the panel's URL and exactly one manual step: visit
`https://panel.<your-hostname>/setup` and create the administrator
account. No admin password is ever baked into the installer or checked
into this repo.

## What's in this repo

```
JinnPanel/
├── README.md                 - this file
├── LICENSE                   - MIT
├── CONTRIBUTING.md           - dev setup, code style, PR guidelines
├── CODE_OF_CONDUCT.md
├── SECURITY.md                - how to report a vulnerability privately
├── CHANGELOG.md
├── docs/
│   ├── ARCHITECTURE.md       - how everything fits together, and why some
│   │                           pieces are built the way they are
│   ├── FEATURES.md           - what the panel actually does, screen by screen
│   ├── TROUBLESHOOTING.md    - known gotchas (mostly SELinux) and how
│   │                           they're already handled
│   ├── INSTALL.md            - quick setup + full detailed setup guide
│   ├── COMPARISON.md         - how JinnPanel compares to cPanel/Plesk/etc
│   └── ICONS.md              - brand assets and the UI icon set
├── assets/
│   ├── brand/                - logo, icon, favicon (SVG)
│   └── icons/                - every UI icon used in the panel (SVG)
├── installer/
│   └── install.sh            - run this on a fresh AlmaLinux 10 server
└── app/                       - the full application source (this is what
                                  install.sh deploys to /var/www/hostpanel)
```

## What you get

- **WHM** (admin/reseller side): create reseller and hosting accounts,
  hosting packages with quotas, and a full **Server Config** area - direct
  control of Stalwart's ~150 settings objects, SFTPGo/PHP/MariaDB tuning,
  one-click performance profiles (Balanced/Performance/Extreme) computed off
  the box's actual CPU/RAM, install/remove additional PHP versions as fully
  isolated instances, AutoSSL, and live service logs on the dashboard.
- **cPanel Migration**: one-click move from cPanel & WHM - a single
  account, a reseller's accounts, or a whole server via WHM root - with
  site files, databases, email accounts and stored mail
  ([`docs/MIGRATION.md`](docs/MIGRATION.md)).
- **cPanel** (end-user side): domains (real vhosts, HTTP+HTTPS), MySQL
  databases, email accounts (real Stalwart mailboxes), SFTP accounts (real
  SFTPGo virtual users), DNS zones (real Knot DNS), a file manager, and
  per-domain PHP version + AutoSSL selection.

See [`docs/FEATURES.md`](docs/FEATURES.md) for the full tour and
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for how it's actually built
underneath.

## Re-running the installer

Safe. Package installs are idempotent (dnf skips what's already there), and
config file writes just overwrite cleanly. Already-generated secrets for
stateful services (MariaDB, Stalwart, SFTPGo) are read back from
`/root/.jinnpanel/` rather than regenerated, so re-running won't lock you
out of data that already exists. Details in
[`docs/INSTALL.md#re-running--upgrading`](docs/INSTALL.md#re-running--upgrading).

## Contributing

Contributions welcome - see [`CONTRIBUTING.md`](CONTRIBUTING.md) for dev
setup and code style, and [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) for
how we expect people to treat each other here. Found a security issue?
Please report it privately per [`SECURITY.md`](SECURITY.md) rather than
opening a public issue.

## Support model

This is a from-scratch build, not a fork of an existing panel - there's no
upstream to file issues against. [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md)
documents every non-obvious fix already baked into `install.sh`,
specifically so the same problems don't need re-discovering by hand if you
hit them again while customizing this further.

## License

[MIT](LICENSE) - free to use, modify, and self-host.
