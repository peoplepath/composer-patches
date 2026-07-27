<?php

namespace cweagans\Composer\Tests\Unit;

use Codeception\Test\Unit;
use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use cweagans\Composer\Reconciler;

class ReconcilerTest extends Unit
{
    protected function makeReconciler(): array
    {
        $vendorDir = codecept_output_dir('reconciler-test-' . uniqid());
        mkdir($vendorDir, 0777, true);

        $config = new Config(false);
        $config->merge(['config' => ['vendor-dir' => $vendorDir]]);

        $composer = new Composer();
        $composer->setConfig($config);

        $io = new NullIO();
        $reconciler = new Reconciler($composer, $io);

        return [$reconciler, $vendorDir];
    }

    public function testGetDriftedPackagesNoDrift()
    {
        [$reconciler] = $this->makeReconciler();
        $reconciler->writeAppliedHashes(['some/package' => 'abc']);

        $this->assertEquals([], $reconciler->getDriftedPackages(['some/package' => 'abc']));
    }

    public function testGetDriftedPackagesChangedHash()
    {
        [$reconciler] = $this->makeReconciler();
        $reconciler->writeAppliedHashes(['some/package' => 'abc']);

        $this->assertEquals(['some/package'], $reconciler->getDriftedPackages(['some/package' => 'def']));
    }

    public function testGetDriftedPackagesNewPackage()
    {
        [$reconciler] = $this->makeReconciler();
        $reconciler->writeAppliedHashes([]);

        $this->assertEquals(['some/package'], $reconciler->getDriftedPackages(['some/package' => 'abc']));
    }

    public function testGetDriftedPackagesRemovedPatches()
    {
        [$reconciler] = $this->makeReconciler();
        $reconciler->writeAppliedHashes(['some/package' => 'abc']);

        // Desired hashes no longer mention the package at all (its patches
        // were removed) -- this should still count as drift, since the
        // package needs to be reinstalled to strip the old patch back out.
        $this->assertEquals(['some/package'], $reconciler->getDriftedPackages([]));
    }

    public function testWriteAndGetAppliedHashesRoundTrip()
    {
        [$reconciler, $vendorDir] = $this->makeReconciler();

        $this->assertEquals([], $reconciler->getAppliedHashes());

        $reconciler->writeAppliedHashes(['b/pkg' => '2', 'a/pkg' => '1']);
        $this->assertEquals(['a/pkg' => '1', 'b/pkg' => '2'], $reconciler->getAppliedHashes());

        $this->assertFileExists($vendorDir . '/composer/installed-patches.lock.json');
    }

    public function testSetPackageHashAddsAndRemoves()
    {
        [$reconciler] = $this->makeReconciler();

        $reconciler->setPackageHash('some/package', 'abc');
        $this->assertEquals(['some/package' => 'abc'], $reconciler->getAppliedHashes());

        $reconciler->setPackageHash('some/package', null);
        $this->assertEquals([], $reconciler->getAppliedHashes());
    }

    public function testPruneMissingPackagesRemovesUninstalledEntries()
    {
        [$reconciler] = $this->makeReconciler();
        $reconciler->writeAppliedHashes(['kept/package' => 'abc', 'removed/package' => 'def']);

        $reconciler->pruneMissingPackages(['kept/package']);

        $this->assertEquals(['kept/package' => 'abc'], $reconciler->getAppliedHashes());
    }
}
