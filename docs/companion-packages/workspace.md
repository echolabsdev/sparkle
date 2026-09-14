# Prism Workspace

**A sandboxed place for an agent to keep its work** — a scoped Laravel `Storage`
disk with an agent-shaped API over it, and a path guard that is the actual
product.

> [!WARNING]
> **Status: files only.** Code execution is deferred, deliberately. Not on
> Packagist yet — install from the repository.

```php
use Prism\Workspace\Facades\PrismWorkspace;

$workspace = PrismWorkspace::for($session);

$workspace->write('reports/q1.md', $content);
$workspace->read('reports/q1.md');
$workspace->list();                       // streamed, not materialised
```

## The one thing it has to get right

A path escaping its workspace is the failure that matters. Everything else is
convenience around it.

Which sets a harder standard than "has a sandbox": **the boundary holds, and you
can watch it hold.**

## Run the corpus against your own configuration

134 adversarial paths across twelve hazard classes ship **inside the package** —
in `Prism\Workspace\Security\EscapeCorpus`, not in `/tests`. Point it at a
workspace built from *your* disk configuration:

```php
use Prism\Workspace\Security\CorpusRunner;

$report = (new CorpusRunner)->against(PrismWorkspace::for($session));

if (! $report->passed()) {
    throw new RuntimeException($report->summary());
}
```

It's safe against a live workspace: a passing run writes nothing, because every
attempt is refused. Two checks run, not one — every attempt is refused with the
code the corpus names, **and** the directories around the workspace are then
swept for the run's marker. The second catches what the first can't: a guard
that refuses everything correctly, in front of a root that was assembled wrongly.

> [!TIP]
> A security property is only true of the configuration it was measured on.
> Ours is measured in CI. Yours is yours to measure.

## What Laravel already does, and what this adds

Measured, not asserted, and pinned in a test so a Flysystem release that moves
it fails a build rather than leaving this table quietly wrong.

Bare `league/flysystem` 3.35 local adapter, 134 attempts:

| | |
|---|---|
| **46 refused by Flysystem** | Traversal and corrupted-path. Portable and genuinely good. |
| **24 refused by the OS** | Trailing dots, edge spaces, over-long names. Platform-dependent. |
| **64 accepted** | Device names, alternate data streams, 8.3 aliases, every percent-encoding, separator homoglyphs, `~/.ssh/id_rsa`, and every absolute path. |

So this package is **not** what stands between an agent and `../../etc/passwd` —
Flysystem's own normaliser already refuses that, and saying otherwise would be a
marketing claim in a security package. What it adds:

- the 64 the framework accepts, refused
- an absolute path **refused** rather than silently relocated. `put('/etc/passwd')`
  on a bare disk drops the leading slash and writes `etc/passwd` inside the root:
  contained, wrong, and no error anywhere.
- a **stable code** on every refusal instead of a message.
  `path_traverses_outside_workspace` is something you can alert on; a substring
  gets reworded in a patch release.
- the same answer on both platforms for the same input
- the symlink boundary, which no path guard can provide

## Why both platform models run on both platforms

24 cases are classified differently on Windows and Linux, and the tempting read
is the wrong way round.

| | Windows | Linux |
|---|---|---|
| `..\secret.txt` | escapes | a filename |
| `C:\Windows\System32\config\SAM` | escapes | a filename |
| `\\server\share\secret.txt` | escapes | a filename |
| `/etc/passwd` | escapes | escapes |

Linux isn't *safe* from the Windows spellings — it's where they get **written**.
Those are legal filenames there, so an unguarded workspace stores them; then the
directory is synced, shared or mounted, a Windows worker opens it, and the
stored filename is an escape.

## Related

- [Harness](/companion-packages/harness) — sessions a workspace is scoped to
