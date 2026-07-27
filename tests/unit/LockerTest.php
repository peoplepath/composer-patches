<?php

namespace cweagans\Composer\Tests\Unit;

use Codeception\Test\Unit;
use cweagans\Composer\Locker;
use cweagans\Composer\Patch;
use cweagans\Composer\PatchCollection;

class LockerTest extends Unit
{
    protected function makePatch(string $package, string $description, string $url): Patch
    {
        $patch = new Patch();
        $patch->package = $package;
        $patch->description = $description;
        $patch->url = $url;
        $patch->extra = [];
        return $patch;
    }

    public function testGetPackageHashesEmptyCollection()
    {
        $collection = new PatchCollection();
        $this->assertEquals([], Locker::getPackageHashes($collection));
    }

    public function testGetPackageHashesAreIsolatedPerPackage()
    {
        $collection = new PatchCollection();
        $collection->addPatch($this->makePatch('some/package', 'patch1', 'https://example.com/1.patch'));
        $collection->addPatch($this->makePatch('other/package', 'patch2', 'https://example.com/2.patch'));

        $hashes = Locker::getPackageHashes($collection);
        $this->assertCount(2, $hashes);
        $this->assertArrayHasKey('some/package', $hashes);
        $this->assertArrayHasKey('other/package', $hashes);

        $otherHashBefore = $hashes['other/package'];

        // Adding a second patch to only 'some/package' should change its hash
        // without affecting 'other/package's hash -- this per-package isolation
        // is exactly what the whole-collection getCollectionHash() can't give us.
        $collection->addPatch($this->makePatch('some/package', 'patch3', 'https://example.com/3.patch'));
        $newHashes = Locker::getPackageHashes($collection);

        $this->assertNotEquals($hashes['some/package'], $newHashes['some/package']);
        $this->assertEquals($otherHashBefore, $newHashes['other/package']);
    }

    public function testGetPackageHashesDeterministic()
    {
        $collection = new PatchCollection();
        $collection->addPatch($this->makePatch('some/package', 'patch1', 'https://example.com/1.patch'));

        $first = Locker::getPackageHashes($collection);
        $second = Locker::getPackageHashes($collection);

        $this->assertEquals($first, $second);
    }

    public function testGetPackageHashMatchesGetPackageHashes()
    {
        $collection = new PatchCollection();
        $collection->addPatch($this->makePatch('some/package', 'patch1', 'https://example.com/1.patch'));

        $hashes = Locker::getPackageHashes($collection);
        $this->assertEquals($hashes['some/package'], Locker::getPackageHash($collection, 'some/package'));
    }
}
