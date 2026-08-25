## What does this PR do?

A short description of the change and why it's needed.

Fixes #(issue), if applicable.

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] Installer change
- [ ] Other (describe above)

## How was this tested?

Plain PHP + a real stack, no test suite yet (see `CONTRIBUTING.md`) - so be
specific about how you actually verified this:
- [ ] `php -l` on every changed file
- [ ] Ran `installer/install.sh` end-to-end (fresh VM or re-run on existing)
- [ ] Exercised the changed screen(s)/endpoint(s) manually against a real
      running stack
- [ ] Rebuilt Tailwind CSS if any classes changed

## Checklist

- [ ] I read `CONTRIBUTING.md`, especially the "Security-sensitive
      changes" section if this touches file paths, SQL identifiers, or
      `hostpanel-worker.php`
- [ ] Views escape output with `e()` unless there's a commented reason not to
- [ ] State-changing POST handlers call `Csrf::requireValid()`
- [ ] New dynamically-built Tailwind class names (if any) were added to
      `tailwind.config.js`'s `safelist`
- [ ] If this fixes a non-obvious SELinux/FrankenPHP quirk, it's also
      documented in `docs/TROUBLESHOOTING.md`
