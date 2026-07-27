<?php

namespace cweagans\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;

/**
 * Detects when a package's patch set has drifted from what's applied on
 * disk, and forces a targeted reinstall of just the drifted packages.
 */
class Reconciler
{
    protected Composer $composer;

    protected IOInterface $io;

    protected ?JsonFile $snapshotFile = null;

    protected ?array $hashCache = null;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    // Resolved lazily, since $composer->getConfig() isn't always ready when this is constructed.
    protected function getSnapshotFile(): JsonFile
    {
        if ($this->snapshotFile === null) {
            $vendorDir = $this->composer->getConfig()->get('vendor-dir');
            $this->snapshotFile = new JsonFile($vendorDir . '/composer/installed-patches.lock.json', null, $this->io);
        }

        return $this->snapshotFile;
    }

    /**
     * @return array<string, string>
     */
    public function getAppliedHashes(): array
    {
        if ($this->hashCache !== null) {
            return $this->hashCache;
        }

        if (!$this->getSnapshotFile()->exists()) {
            return $this->hashCache = [];
        }

        $data = $this->getSnapshotFile()->read();
        return $this->hashCache = $data['hashes'] ?? [];
    }

    public function setPackageHash(string $package, ?string $hash): void
    {
        $hashes = $this->getAppliedHashes();
        if ($hash === null) {
            unset($hashes[$package]);
        } else {
            $hashes[$package] = $hash;
        }
        $this->writeAppliedHashes($hashes);
    }

    /**
     * @param array<string, string> $hashes
     */
    public function writeAppliedHashes(array $hashes): void
    {
        ksort($hashes);
        $this->getSnapshotFile()->write(['hashes' => $hashes]);
        $this->hashCache = $hashes;
    }

    /**
     * A package whose patches were removed entirely (no longer in $desiredHashes) still
     * counts as drifted, since it needs reinstalling to strip the old patch back out.
     *
     * @param array<string, string> $desiredHashes
     * @return string[]
     */
    public function getDriftedPackages(array $desiredHashes): array
    {
        $applied = $this->getAppliedHashes();
        $names = array_unique(array_merge(array_keys($desiredHashes), array_keys($applied)));

        $drifted = [];
        foreach ($names as $name) {
            if (($desiredHashes[$name] ?? null) !== ($applied[$name] ?? null)) {
                $drifted[] = $name;
            }
        }

        return $drifted;
    }

    /**
     * Uninstall and install are issued as two separate execute() calls: batching both
     * for the same package into one call triggers a download/extract race in ZipDownloader.
     *
     * @param string[] $packageNames
     */
    public function reinstallPackages(array $packageNames, bool $devMode): void
    {
        if (empty($packageNames)) {
            return;
        }

        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
        $packages = array_values(array_filter(
            $localRepository->getPackages(),
            fn (PackageInterface $p) => in_array($p->getName(), $packageNames, true)
        ));

        if (empty($packages)) {
            return;
        }

        foreach ($packages as $package) {
            $this->io->write(
                "  - <info>{$package->getName()}</info> patch set changed on disk; reinstalling to reapply patches."
            );
        }

        $installationManager = $this->composer->getInstallationManager();

        $uninstallOps = array_map(fn ($p) => new UninstallOperation($p), $packages);
        $installationManager->execute($localRepository, $uninstallOps, $devMode, true, false);

        $installOps = array_map(fn ($p) => new InstallOperation($p), $packages);
        $installationManager->execute($localRepository, $installOps, $devMode, true, false);
    }

    /**
     * @param string[] $installedPackageNames
     */
    public function pruneMissingPackages(array $installedPackageNames): void
    {
        $hashes = $this->getAppliedHashes();
        $pruned = array_intersect_key($hashes, array_flip($installedPackageNames));
        if ($pruned !== $hashes) {
            $this->writeAppliedHashes($pruned);
        }
    }
}
