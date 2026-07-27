<?php

/**
 * @var \Codeception\Scenario $scenario
 */

use cweagans\Composer\Tests\AcceptanceTester;

$I = new AcceptanceTester($scenario);

$I->wantTo('reapply a patch when composer install has nothing to do but the patch set changed (#583)');
$I->amInPath(codecept_data_dir('fixtures/reconcile-patch-drift'));
$I->runComposerCommand('install', ['-vvv']);
$I->canSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/OneMoreTest.php');

// Guard against a hash mismatch between the just-downloaded PatchCollection and one
// reloaded from patches.lock.json, which would make every patched package look drifted.
$I->runComposerCommand('install', ['-vvv']);
$I->dontSeeInComposerOutput('patch set changed on disk');
$I->canSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/OneMoreTest.php');

// Swap in a different patch and relock, but deliberately leave vendor/ and composer.lock
// untouched -- the #583 repro. Only `auto-repatch` (enabled in this fixture) catches this.
$I->runShellCommand('mv composer.json composer_old.json');
$I->runShellCommand('cp composer2.json composer.json');
$I->runShellCommand('composer patches-relock');

$I->runComposerCommand('install', ['-vvv']);
$I->seeInComposerOutput('Nothing to install, update or remove');
$I->seeInComposerOutput('patch set changed on disk');
$I->canSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/YetAnotherTest.php');
$I->cantSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/OneMoreTest.php');

// Running install again with no further changes should be idempotent -- no
// unnecessary reinstall of a package that's no longer drifted.
$I->runComposerCommand('install', ['-vvv']);
$I->dontSeeInComposerOutput('patch set changed on disk');
$I->canSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/YetAnotherTest.php');

// Reset composer.json so we don't accidentally commit the new version.
$I->runShellCommand('mv composer_old.json composer.json');
