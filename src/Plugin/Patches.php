<?php

/**
 * @file
 * Provides a way to patch Composer packages after installation.
 */

namespace cweagans\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use cweagans\Composer\Capability\CommandProvider;
use cweagans\Composer\Capability\Downloader\CoreDownloaderProvider;
use cweagans\Composer\Capability\Downloader\DownloaderProvider;
use cweagans\Composer\Capability\Patcher\CorePatcherProvider;
use cweagans\Composer\Capability\Patcher\PatcherProvider;
use cweagans\Composer\Capability\Resolver\CoreResolverProvider;
use cweagans\Composer\Capability\Resolver\ResolverProvider;
use cweagans\Composer\ConfigurablePlugin;
use cweagans\Composer\Downloader;
use cweagans\Composer\Event\PatchEvent;
use cweagans\Composer\Event\PatchEvents;
use cweagans\Composer\Locker;
use cweagans\Composer\Patch;
use cweagans\Composer\PatchCollection;
use cweagans\Composer\Patcher;
use cweagans\Composer\Reconciler;
use cweagans\Composer\Resolver;
use cweagans\Composer\Util;
use InvalidArgumentException;
use Exception;

class Patches implements PluginInterface, EventSubscriberInterface, Capable
{
    use ConfigurablePlugin;

    /**
     * @var Composer $composer
     */
    protected Composer $composer;

    /**
     * @var IOInterface $io
     */
    protected IOInterface $io;

    /**
     * @var EventDispatcher $eventDispatcher
     */
    protected EventDispatcher $eventDispatcher;

    /**
     * @var ProcessExecutor $executor
     */
    protected ProcessExecutor $executor;

    /**
     * @var array $patches
     */
    protected array $patches;

    /**
     * @var array $installedPatches
     */
    protected array $installedPatches;

    /**
     * @var ?PatchCollection $patchCollection
     */
    protected ?PatchCollection $patchCollection;

    protected Locker $locker;

    protected JsonFile $lockFile;

    protected Reconciler $reconciler;

    // Re-entrancy guard for reconcilePatchedPackages().
    protected static bool $reconciling = false;

    /**
     * Get the path to the current patches lock file.
     */
    public static function getPatchesLockFilePath(): string
    {
        $composer_file = \Composer\Factory::getComposerFile();

        $dir = dirname(realpath($composer_file));
        $base = pathinfo($composer_file, \PATHINFO_FILENAME);

        if ($base === 'composer') {
            return "$dir/patches.lock.json";
        }

        return "$dir/$base-patches.lock.json";
    }

    /**
     * Apply plugin modifications to composer
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->executor = new ProcessExecutor($this->io);
        $this->patches = array();
        $this->installedPatches = array();
        $this->lockFile = new JsonFile(
            static::getPatchesLockFilePath(),
            null,
            $this->io
        );
        $this->locker = new Locker($this->lockFile);
        $this->reconciler = new Reconciler($this->composer, $this->io);
        $this->configuration = [
            'disable-resolvers' => [
                'type' => 'list',
                'default' => [],
            ],
            'disable-downloaders' => [
                'type' => 'list',
                'default' => [],
            ],
            'disable-patchers' => [
                'type' => 'list',
                'default' => [],
            ],
            'default-patch-depth' => [
                'type' => 'int',
                'default' => 1,
            ],
            'package-depths' => [
                'type' => 'list',
                'default' => [],
            ],
            'patches-file' => [
                'type' => 'string',
                'default' => 'patches.json',
            ],
            "ignore-dependency-patches" => [
                'type' => 'list',
                'default' => [],
            ],
            'auto-repatch' => [
                'type' => 'bool',
                'default' => false,
            ],
        ];
        $this->configure($this->composer->getPackage()->getExtra(), 'composer-patches');
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @calls resolvePatches
     */
    public static function getSubscribedEvents(): array
    {
        return array(
            PackageEvents::PRE_PACKAGE_INSTALL => ['loadLockedPatches'],
            PackageEvents::PRE_PACKAGE_UPDATE => ['loadLockedPatches'],
            // The POST_PACKAGE_* events are a higher weight for compatibility with
            // https://github.com/AydinHassan/magento-core-composer-installer and more generally for compatibility with
            // any Composer Plugin which deploys downloaded packages to other locations. In the cast that you want
            // those plugins to deploy patched files, those plugins have to run *after* this plugin.
            // @see: https://github.com/cweagans/composer-patches/pull/153
            PackageEvents::POST_PACKAGE_INSTALL => ['patchPackage', 10],
            PackageEvents::POST_PACKAGE_UPDATE => ['patchPackage', 10],
            // Unlike the package-level events above, these fire even when the solver
            // has nothing to do, which is needed to catch drift (see reconcilePatchedPackages).
            ScriptEvents::POST_INSTALL_CMD => ['reconcilePatchedPackages'],
            ScriptEvents::POST_UPDATE_CMD => ['reconcilePatchedPackages'],
        );
    }

    /**
     * Return a list of plugin capabilities.
     *
     * @return array
     */
    public function getCapabilities(): array
    {
        return [
            ResolverProvider::class => CoreResolverProvider::class,
            DownloaderProvider::class => CoreDownloaderProvider::class,
            PatcherProvider::class => CorePatcherProvider::class,
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    /**
     * Discover patches using all available Resolvers.
     */
    public function resolvePatches()
    {
        $resolver = new Resolver($this->composer, $this->io, $this->getConfig('disable-resolvers'));
        return $resolver->loadFromResolvers();
    }

    /**
     * Resolve and download patches so that all sha256 sums can be included in the lock file.
     */
    public function createNewPatchesLock()
    {
        $this->patchCollection = $this->resolvePatches();
        $downloader = new Downloader($this->composer, $this->io, $this->getConfig('disable-downloaders'));
        foreach ($this->patchCollection->getPatchedPackages() as $package) {
            foreach ($this->patchCollection->getPatchesForPackage($package) as $patch) {
                $this->download($patch);
                $this->guessDepth($patch);
            }
        }
        $this->locker->setLockData($this->patchCollection);
    }

    /**
     * Load previously discovered patches from the Composer lock file.
     *
     * @param PackageEvent $event
     *   The event provided by Composer.
     */
    public function loadLockedPatches()
    {
        $locked = $this->locker->isLocked();
        if (!$locked) {
            $filename = pathinfo($this->getLockFile()->getPath(), \PATHINFO_BASENAME);
            $this->io->write("<warning>$filename does not exist. Creating a new $filename.</warning>");
            $this->createNewPatchesLock();
            return;
        }

        $this->patchCollection = PatchCollection::fromJson($this->locker->getLockData());
    }

    public function download(Patch $patch)
    {
        static $downloader;
        if (is_null($downloader)) {
            $downloader = new Downloader($this->composer, $this->io, $this->getConfig('disable-downloaders'));
        }

        $this->composer->getEventDispatcher()->dispatch(
            PatchEvents::PRE_PATCH_DOWNLOAD,
            new PatchEvent(PatchEvents::PRE_PATCH_DOWNLOAD, $patch, $this->composer, $this->io)
        );
        $downloader->downloadPatch($patch);
        $this->composer->getEventDispatcher()->dispatch(
            PatchEvents::POST_PATCH_DOWNLOAD,
            new PatchEvent(PatchEvents::POST_PATCH_DOWNLOAD, $patch, $this->composer, $this->io)
        );
    }

    public function guessDepth(Patch $patch)
    {
        $event = new PatchEvent(PatchEvents::PRE_PATCH_GUESS_DEPTH, $patch, $this->composer, $this->io);
        $this->composer->getEventDispatcher()->dispatch(PatchEvents::PRE_PATCH_GUESS_DEPTH, $event);
        $patch = $event->getPatch();

        $depth = $patch->depth ??
            $this->getConfig('package-depths')[$patch->package] ??
            Util::getDefaultPackagePatchDepth($patch->package) ??
            $this->getConfig('default-patch-depth');
        $patch->depth = $depth;
    }

    public function apply(Patch $patch, string $install_path)
    {
        static $patcher;
        if (is_null($patcher)) {
            $patcher = new Patcher($this->composer, $this->io, $this->getConfig('disable-patchers'));
        }

        $this->guessDepth($patch);

        $event = new PatchEvent(PatchEvents::PRE_PATCH_APPLY, $patch, $this->composer, $this->io);
        $this->composer->getEventDispatcher()->dispatch(PatchEvents::PRE_PATCH_APPLY, $event);
        $patch = $event->getPatch();

        $this->io->write(
            "      - Applying patch <info>{$patch->localPath}</info> (depth: {$patch->depth})",
            true,
            IOInterface::DEBUG
        );

        $status = $patcher->applyPatch($patch, $install_path);
        if ($status === false) {
            $e = new Exception("No available patcher was able to apply patch {$patch->url} to {$patch->package}");

            $this->composer->getEventDispatcher()->dispatch(
                PatchEvents::POST_PATCH_APPLY_ERROR,
                new PatchEvent(PatchEvents::POST_PATCH_APPLY_ERROR, $patch, $this->composer, $this->io, $e)
            );

            throw $e;
        }

        $this->composer->getEventDispatcher()->dispatch(
            PatchEvents::POST_PATCH_APPLY,
            new PatchEvent(PatchEvents::POST_PATCH_APPLY, $patch, $this->composer, $this->io)
        );
    }


    /**
     * Download and apply patches.
     *
     * @param PackageEvent $event
     *   The event that Composer provided to us.
     */
    public function patchPackage(PackageEvent $event)
    {
        // Sometimes, patchPackage is called before a patch loading function (for instance, when composer-patches itself
        // is installed -- the pre-install event can't be invoked before this plugin is installed, but the post-install
        // event *can* be. Skipping composer-patches and composer-configurable-plugin ensures that this plugin and its
        // dependency won't cause an error to be thrown when attempting to read from an uninitialized PatchCollection.
        // This also means that neither composer-patches nor composer-configurable-plugin can have patches applied.
        $package = $this->getPackageFromOperation($event->getOperation());
        if (in_array($package->getName(), ['cweagans/composer-patches', 'cweagans/composer-configurable-plugin'])) {
            return;
        }

        // If there aren't any patches, there's nothing to do.
        if (empty($this->patchCollection->getPatchesForPackage($package->getName()))) {
            $this->io->write(
                "No patches found for <info>{$package->getName()}</info>",
                true,
                IOInterface::DEBUG,
            );
            if ($this->getConfig('auto-repatch')) {
                $this->reconciler->setPackageHash($package->getName(), null);
            }
            return;
        }

        $install_path = $this->composer->getInstallationManager()
            ->getInstaller($package->getType())
            ->getInstallPath($package);

        $this->io->write("  - Patching <info>{$package->getName()}</info>");

        foreach ($this->patchCollection->getPatchesForPackage($package->getName()) as $patch) {
            /** @var $patch Patch */

            // Download patch.
            $this->io->write(
                "    - Downloading and applying patch <info>{$patch->url}</info> ({$patch->description})",
                true,
                IOInterface::VERBOSE
            );

            $this->io->write("      - Downloading patch <info>{$patch->url}</info>", true, IOInterface::DEBUG);

            $this->download($patch);
            $this->guessDepth($patch);

            // Apply patch.
            $this->io->write(
                "      - Applying downloaded patch <info>{$patch->localPath}</info>",
                true,
                IOInterface::DEBUG
            );

            $this->apply($patch, $install_path);
        }

        $this->io->write(
            "  - All patches for <info>{$package->getName()}</info> have been applied.",
            true,
            IOInterface::DEBUG
        );

        if ($this->getConfig('auto-repatch')) {
            $this->reconciler->setPackageHash(
                $package->getName(),
                Locker::getPackageHash($this->patchCollection, $package->getName())
            );
        }
    }

    /**
     * Reinstalls packages whose patch set has drifted from what's applied on disk.
     *
     * @see https://github.com/cweagans/composer-patches/issues/583
     */
    public function reconcilePatchedPackages(ScriptEvent $event): void
    {
        if (!$this->getConfig('auto-repatch')) {
            return;
        }

        if (self::$reconciling) {
            return;
        }

        self::$reconciling = true;
        try {
            // A no-op solver run never fires PRE_PACKAGE_INSTALL, so this may still be unset.
            if (!isset($this->patchCollection)) {
                $this->loadLockedPatches();
            }

            $desired = Locker::getPackageHashes($this->patchCollection);
            $drifted = $this->reconciler->getDriftedPackages($desired);

            if (!empty($drifted)) {
                $this->reconciler->reinstallPackages($drifted, $event->isDevMode());
            }

            $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
            $installedNames = array_map(fn ($p) => $p->getName(), $localRepository->getPackages());

            $this->reconciler->writeAppliedHashes($desired);
            $this->reconciler->pruneMissingPackages($installedNames);
        } finally {
            self::$reconciling = false;
        }
    }

    public function getReconciler(): Reconciler
    {
        return $this->reconciler;
    }

    /**
     * Get a Package object from an OperationInterface object.
     *
     * @param OperationInterface $operation
     * @return PackageInterface
     * @throws InvalidArgumentException
     */
    protected function getPackageFromOperation(OperationInterface $operation): PackageInterface
    {
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            throw new InvalidArgumentException('Unknown operation: ' . get_class($operation));
        }

        return $package;
    }

    public function getLocker(): Locker
    {
        return $this->locker;
    }

    public function getLockFile(): JsonFile
    {
        return $this->lockFile;
    }

    public function getPatchCollection(): ?PatchCollection
    {
        return $this->patchCollection;
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
