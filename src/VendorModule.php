<?php

namespace SilverStripe\VendorPlugin;

use Composer\Composer;
use Composer\Factory;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use LogicException;

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
        return $this->getPackage()->getName();
    }

    public function isProject()
    {
        return $this->getPackage() instanceof RootPackageInterface;
    }

    /**
     * Get full path to the root install for this project
     *
     * @return string Path for this module
     */
    public function getInstallPath()
    {
        if ($this->isProject()) {
            return Util::joinPaths(dirname(realpath(Factory::getComposerFile())), $this->getPackage()->getTargetDir());
        }
        return $this->getComposer()->getInstallationManager()->getInstallPath($this->getPackage());
    }

    /**
     * @return string
     */
    public function getResourcePath()
    {
        $path = $this->getComposer()->getInstallationManager()->getInstallPath($this->getPackage());
        $parts = explode(DIRECTORY_SEPARATOR, $path);

        $basePath = $this->getComposer()->getPackage()->getTargetDir();
        $projectParts = array_slice($parts, -2);

        return Util::joinPaths($basePath, 'resources', $projectParts);
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
