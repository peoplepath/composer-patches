<?php

/**
 * @var \Codeception\Scenario $scenario
 */

use cweagans\Composer\Tests\AcceptanceTester;

$I = new AcceptanceTester($scenario);

$I->wantTo('leave patch drift unresolved when auto-repatch is left disabled');
$I->amInPath(codecept_data_dir('fixtures/reconcile-patch-drift-disabled'));
$I->runComposerCommand('install', ['-vvv']);
$I->canSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/OneMoreTest.php');

// Same drift-inducing sequence as ReconcileDriftedPatchesCept, but auto-repatch is off
// here, so the pre-existing (buggy but non-regressed) behavior must be preserved.
$I->runShellCommand('mv composer.json composer_old.json');
$I->runShellCommand('cp composer2.json composer.json');
$I->runShellCommand('composer patches-relock');

$I->runComposerCommand('install', ['-vvv']);
$I->seeInComposerOutput('Nothing to install, update or remove');
$I->dontSeeInComposerOutput('patch set changed on disk');
$I->canSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/OneMoreTest.php');
$I->cantSeeFileFound('./vendor/cweagans/composer-patches-testrepo/src/YetAnotherTest.php');
$I->cantSeeFileFound('./vendor/composer/installed-patches.lock.json');

// Reset composer.json so we don't accidentally commit the new version.
$I->runShellCommand('mv composer_old.json composer.json');
