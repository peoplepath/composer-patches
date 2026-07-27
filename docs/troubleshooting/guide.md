---
title: Guide
weight: 10
---

{{< lead text="Common problems that people have run into and how to fix them." >}}

## System readiness

If you've encountered a problem, a good first step is to run [`composer patches-doctor`]({{< relref "../usage/commands.md#composer-patches-doctor" >}}). This will run a few checks against your system and look for common configuration errors.

## Enable verbose output

If a command is failing and `composer patches-doctor` doesn't give you any errors, you should re-run your command with extra verbose output. Example: `composer -v install`. Composer Patches will usually display extra information about _why_ something is failing in any of the verbose output modes. Composer Patches only emits extra output in the normal verbose mode (`composer -v`), but if you're having other problems with Composer, you can also add one or two additional `v`'s to your command (`composer -vv` or `composer -vvv`) to get extra information from Composer itself.

## Upgrade system software

See the [system requirements]({{< relref "../getting-started/system-requirements.md" >}}) page for the minimum supported versions of PHP, Composer, and other software. Upgrade your software using the method appropriate for your operating system.

## Install patching software

Composer Patches requires `git` to be installed in order to apply patches. Previous versions of the plugin relied on
various versions of `patch`, but that is no longer the case. Make sure you have `git` installed and you should be all set.

## Download patches securely

If you've been referred here, you're trying to download patches over HTTP without explicitly telling Composer that you want to do that. See the [`secure-http`]({{< relref "../usage/configuration.md#secure-http" >}}) documentation for more information.

## My patch changes aren't applied after `composer install`

Composer only applies patches when a package is actually being installed or updated. If you add or change a patch for a package that's already installed and already satisfies `composer.lock` (i.e. you didn't also bump its version), a plain `composer install` will report `Nothing to install, update or remove` for that package, and your patch change won't be picked up -- even if `patches.lock.json` is already correct.

Normally, you'd fix this by running [`composer patches-repatch`]({{< relref "../usage/commands.md#composer-patches-repatch" >}}) after relocking. If you're on a host that only ever runs `composer install` (and can't run additional commands as part of a deploy), see [Restricted deploy hosts (install-only)]({{< relref "../usage/recommended-workflows.md#restricted-deploy-hosts-install-only" >}}) for how to make `composer install` detect and fix this automatically.
