---
title: Recommended Workflows
weight: 40
---

{{< lead text="Common workflows for working with Composer Patches on a team." >}}

## Initial setup

The plugin can safely be [installed]({{< relref "../getting-started/installation.md" >}}) as part of initial project setup, even if you don't have any patches to apply right away. A `patches.lock.json` will still be written, but it will be empty.

## Add a patch to your project

1. [Define a patch]({{< relref "defining-patches.md" >}}) in your `composer.json` or your external patches file (either will work by default, but choose the appropriate place based on how your project is configured).
2. Run [`composer patches-relock`]({{< relref "commands.md#composer-patches-relock" >}}) to regenerate `patches.lock.json` with your new patch.
3. Run [`composer patches-repatch`]({{< relref "commands.md#composer-patches-repatch" >}}) to delete patched dependencies and reinstall them with any defined patches {{< warning title="Running `composer patches-repatch` will delete data" >}}
Ensure that you don't have any unsaved changes in any patched dependencies in your project.
{{< /warning >}}
4. If your patch definition was added to `composer.json`, run `composer update --lock` to update the content hash in `composer.lock`.
5. Commit any related changes to your external patches file (if configured), `composer.json`, `composer.lock`, and `patches.lock.json`.

## Apply patches added to the project by someone else

If you have an existing copy of the project and you're updating it to include someone else's changes:

1. Pull changes from your project's version control system.
2. Run [`composer patches-repatch`]({{< relref "commands.md#composer-patches-repatch" >}}) {{< warning title="Running `composer patches-repatch` will delete data" >}}
Ensure that you don't have any unsaved changes in any patched dependencies in your project.
{{< /warning >}}

If you're installing the project from scratch:

1. Clone the project
2. Run `composer install`

## Remove a patch

1. Delete the patch definition from your `composer.json` or external patches file.
2. Run [`composer patches-relock`]({{< relref "commands.md#composer-patches-relock" >}}) to regenerate `patches.lock.json` with your new patch.
3. Manually delete the dependency that you removed a patch from (the location of the dependency will vary by project, but a good starting point is to look in the `vendor/` directory).
4. Run [`composer patches-repatch`]({{< relref "commands.md#composer-patches-repatch" >}}) to delete patched dependencies and reinstall them with any defined patches {{< warning title="Running `composer patches-repatch` will delete data" >}}
Ensure that you don't have any unsaved changes in any patched dependencies in your project.
{{< /warning >}}
5. If your patch definition was removed from  `composer.json`, run `composer update --lock` to update the content hash in `composer.lock`.
6. Commit any related changes to your external patches file (if configured), `composer.json`, `composer.lock`, and `patches.lock.json`.

## Restricted deploy hosts (install-only)

The workflows above rely on being able to run `composer patches-repatch` as a separate step after pulling in a new or changed patch. Some hosting providers (Pantheon, for example) only run `composer install` during a deploy and don't give you a way to run additional commands in between.

On these hosts, if a patch is added or changed for a package that's already installed and already satisfies `composer.lock`, `composer install` will report `Nothing to install, update or remove` for that package and your patch won't be applied, even though `patches.lock.json` is correct.

To handle this automatically, enable [`auto-repatch`]({{< relref "configuration.md#auto-repatch" >}}):

```json
{
    [...],
    "extra": {
        "composer-patches": {
            "auto-repatch": true
        }
    }
}
```

or via the equivalent environment variable, which is often easier to set on a host you don't control the `composer.json` deploy pipeline for:

```shell
COMPOSER_PATCHES_AUTO_REPATCH=1 composer install
```

With this enabled, a plain `composer install` will detect that a package's patch set has drifted and reinstall just that package to reapply its patches -- you still need to run `composer patches-relock` (as in "Add a patch to your project" above) so `patches.lock.json` reflects the new patch, but you no longer need a separate `composer patches-repatch` step.


