# Releasing

A release is a tag push. `.github/workflows/publish.yml` does the rest.

There is **no upload step and no token**. Composer resolves versions from git
tags and Packagist mirrors them over a webhook, so pushing the tag *is* the
publish. What the workflow adds is the part that has gone wrong here before:
proving the tag was tested, and proving the package actually became installable.

## Cutting a release

1. Land the work on `main` and wait for the gates to go green.
2. Tag it and push:

   ```
   git tag -a v0.2.0        # annotated: the message BECOMES the release notes
   git push origin v0.2.0
   ```

   **The tag annotation is the changelog.** These packages ship no CHANGELOG
   file, so what you write in the tag message is what a consumer reads on the
   release page and what arrives in their inbox. The workflow publishes it
   verbatim.

   A lightweight tag — `git tag v0.2.0` with no `-a` and no message — FAILS the
   release step rather than publishing an empty release. If a version is worth
   cutting, it is worth a sentence saying why.

   Say what breaks, what it fixes, and what a consumer has to do. Anyone holding
   a pinned digest or matching on an error code learns it here or not at all.

Composer takes the version from the tag, so there is nothing to bump in
`composer.json` — and a `version` key there is refused, because it reintroduces
exactly the tag-versus-declared disagreement Composer avoids by not having one.

## What it refuses, and why

- **No successful `tests.yml` run for that exact commit.** Not "nothing failed"
  — *succeeded*. A package whose tests never ran reports nothing failed, which
  is how `prism-opentelemetry` shipped v0.1.1 with 32 tests on disk that CI had
  never once executed.
- **Any other gate failed on that commit** — PHPStan, Formatting, Require
  Checker, Factcheck, whichever this repo has.
- **`composer.json` declares a `version`.**
- **Packagist never serves the version.** The release job succeeds and this one
  still fails, deliberately: a tag and a GitHub release are not a distribution.

## First publication

If the package has never been published, the last job fails no matter how good
the release is, because nothing is listening for the webhook yet. Submit the
package once at <https://packagist.org/packages/submit>; Packagist follows the
repository's tags on its own afterwards, and re-running that job then passes.

This is not hypothetical. `prism-human-plus` was tagged, released on GitHub and
uninstallable for days because nobody had submitted it, and nothing anywhere
reported a problem. `prism-memory`, `prism-workspace` and `prism-browser` are in
that state now.
