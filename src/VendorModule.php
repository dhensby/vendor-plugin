<?php

namespace SilverStripe\VendorPlugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event;
use Composer\Json\JsonFile;
use LogicException;
use SilverStripe\VendorPlugin\Methods\ExposeMethod;

/**
 * Represents a module in the vendor folder
 */
class VendorModule
{
    /**
     * Default replacement folder for 'vendor'
     */
    const DEFAULT_TARGET = 'resources';

    /**
     * Default source folder
     */
    const DEFAULT_SOURCE = 'vendor';

    /**
     * @var PackageInterface
     */
    protected $package = null;

    /**
     * @var Composer
     */
    protected $composer = null;

    /**
     * Build a vendor module library
     *
     * @param PackageInterface $package The package being installed
     * @param Composer $composer
     */
    public function __construct($package, $composer)
    {
        $this->package = $package;
        $this->composer = $composer;
    }

    public static function createFromEvent(PackageEvent $event)
    {
        $composer = $event->getComposer();
        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation || $operation instanceof UninstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            return null;
        }
        return new static(
            $package,
            $composer
        );
    }

    /**
     * @return PackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @return Composer
     */
    public function getComposer()
    {
        return $this->composer;
    }

    /**
     * Get module name
     *
     * @return string
     */
    public function getName()
    {
        return $this->package->getName();
    }

    /**
     * Get full path to the root install for this project
     *
     * @param string $base Rewrite root (or 'vendor' for actual module path)
     * @return string Path for this module
     */
    public function getModulePath($base = self::DEFAULT_SOURCE)
    {
        $installPath = $this->getComposer()->getInstallationManager()->getInstallPath($this->getPackage());
        var_export($installPath);
        if ($this->getPackage() instanceof RootPackageInterface && $base === self::DEFAULT_SOURCE) {
            return Util::joinPaths(
                $this->basePath,
                $this->composer->getPackage()->getTargetDir()
            );
        }
        return Util::joinPaths(
            $this->basePath,
            $base,
            explode('/', $this->name)
        );
    }

    /**
     * Get json content for this module from composer.json
     *
     * @return array
     */
    protected function getJson()
    {
        $composer = Util::joinPaths($this->getModulePath(), 'composer.json');
        $file = new JsonFile($composer);
        return $file->read();
    }

    /**
     * Expose all web accessible paths for this module
     *
     * @param ExposeMethod $method
     * @param string $target Replacement target for 'vendor' prefix to rewrite to. Defaults to 'resources'
     */
    public function exposePaths(ExposeMethod $method, $target = self::DEFAULT_TARGET)
    {
        $folders = $this->getExposedFolders();
        $sourcePath = $this->getModulePath(self::DEFAULT_SOURCE);
        $targetPath = $this->getModulePath($target);
        foreach ($folders as $folder) {
            // Get paths for this folder and delegate to expose method
            $folderSourcePath = Util::joinPaths($sourcePath, $folder);
            $folderTargetPath = Util::joinPaths($targetPath, $folder);
            $method->exposeDirectory($folderSourcePath, $folderTargetPath);
        }
    }

    /**
     * Get name of all folders to expose (relative to module root)
     *
     * @return array
     */
    public function getExposedFolders()
    {
        // Only expose if correct type
        if ($this->getPackage()->getType() !== VendorPlugin::MODULE_TYPE) {
            return [];
        }

        $extra = $this->getPackage()->getExtra();
        // Get all dirs to expose
        if (empty($extra['expose'])) {
            return [];
        }
        $expose = $extra['expose'];

        // Validate all paths are safe
        foreach ($expose as $exposeFolder) {
            if (!$this->validateFolder($exposeFolder)) {
                throw new LogicException("Invalid module folder " . $exposeFolder);
            }
        }
        return $expose;
    }

    /**
     * Validate the given folder is allowed
     *
     * @param string $exposeFolder Relative folder name to check
     * @return bool
     */
    protected function validateFolder($exposeFolder)
    {
        if (strstr($exposeFolder, '.')) {
            return false;
        }
        if (strpos($exposeFolder, '/') === 0) {
            return false;
        }
        if (strpos($exposeFolder, '\\') === 0) {
            return false;
        }
        return true;
    }
}
