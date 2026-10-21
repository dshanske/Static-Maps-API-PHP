# Contributing

Thanks for helping. This file covers how changes get made; the README's
[Development](README.md#development) section covers the tools, tests and code
layout.

## How a change gets in

1. **Start with an issue.** Describe the problem or proposal before opening a
   pull request, so the approach can be agreed first. Security problems are the
   exception: report them privately, as described in [SECURITY.md](SECURITY.md).
2. **Open a pull request against `main`** that refers to the issue. The
   maintainer reviews and merges every pull request; nothing is pushed to `main`
   directly.
3. **Keep pull requests focused.** A refactoring that doesn't change behaviour
   goes in its own pull request, separate from fixes and features, and must pass
   with the reference images in `tests/fixtures/expected/` unchanged.
4. **`composer check` must pass** (syntax, coding standard with no warnings,
   PHPStan level 6, tests). CI runs the same checks, with the syntax check and
   tests on every supported PHP version.

## Rules for changes

- **Changelog.** Anything users or installs can notice adds a line to the
  `Unreleased` section of [CHANGELOG.md](CHANGELOG.md), with its issue or PR
  number. See [Changelog](README.md#changelog).
- **Documentation comments** on every PHP file, class, function, method and
  property, including units and coordinate order. `composer cs` enforces them.
- **README.** Update it when you add or change a parameter, response header,
  setting, provider, style or marker icon.
- **Settings** go in `config/defaults.php` with a comment saying what the
  setting does, its default and an example override, and are mirrored,
  commented out, in `data/config.example.php`.
- **Deprecations, not breakage.** Changing or removing public behaviour
  (request parameters, responses, settings, the query API) follows the policy
  in [Versioning and deprecation](README.md#versioning-and-deprecation): the old
  way keeps working with `Deprecation` and `Link` headers, gets a row in the
  Deprecated table, and is removed only in a major release, at least three
  releases later.
- **Tests never reach a real tile server.** Use the fake tile server and the
  test configuration in `tests/fixtures/`.
- **Features that fetch a URL from the request** (icons, attribution images,
  custom tiles) must require a client key that allows external URLs.

## Adding a provider, style or overlay

- **Read the provider's terms first**, and link them in the pull request.
  A provider is added only if its terms allow a server like this one to fetch
  its tiles and serve them as images, and the README's
  [Provider terms](README.md#provider-terms) table gets a row summarising them,
  including any limits on commercial use, caching or load.
- Draw the credit the provider requires (`attribution`, `attribution_text`,
  `attribution_html`), and set `cache` to what its terms allow.
- Give it a commented entry in `config/defaults.php`, reusing the shared style
  ids (`streets`, `light`, `dark`, `satellite`, ...) where they fit, and set
  `minzoom`, `maxzoom` and, for regional maps, `bounds` to what it really serves.
- A provider that needs an API key uses `{key}`; it stays unavailable until an
  install sets the key.
- List it in the README's [Providers and styles](README.md#providers-and-styles)
  table.

## AI coding assistants

Contributions made with AI coding assistants are reviewed like any other.
See [AI coding assistants](README.md#ai-coding-assistants) for how they are
marked.
