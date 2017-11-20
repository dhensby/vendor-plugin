<?php

namespace SilverStripe\VendorPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\VendorPlugin\Methods\CopyMethod;
use SilverStripe\VendorPlugin\Methods\ExposeMethod;
use SilverStripe\VendorPlugin\Methods\ChainedMethod;
use SilverStripe\VendorPlugin\Methods\SymlinkMethod;

/**
 * Provides public webroot rewrite functionality for vendor modules
 */
class VendorPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * Module type to match
     */
    const MODULE_TYPE = 'silverstripe-vendormodule';

    /**
     * Method env var to query
     */
    const METHOD_ENV = 'SS_VENDOR_METHOD';

    /**
     * Method name for "none" option
     */
    const METHOD_NONE = 'none';

    /**
     * Method name to auto-attempt best method
     */
    const METHOD_AUTO = 'auto';

    /**
     * @var Filesystem
     */
    protected $filesystem = null;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Apply vendor plugin
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activating');
    }

    public static function getSubscribedEvents()
    {
        return [
            'post-install-cmd' => 'updateResources',
            'post-update-cmd' => 'updateResources',
        ];
    }

    /**
     * @param Event $event
     */
    public function updateResources(Event $event)
    {
        $composer = $event->getComposer();
        $repo = $composer->getRepositoryManager()->getLocalRepository();
        $io = $event->getIO();
        // map all existing resources
        $existingResources = $this->mapExistingResources(
            $path = Util::joinPaths(
                $this->getProjectPath(),
                'resources'
            )
        );
        $io->write(var_export($existingResources, true));
        $exposeMethod = $this->getMethod();
        // iterate over all modules (including root module)
        foreach (array_merge([$composer->getPackage()], $repo->getPackages()) as $package) {
            // not a vendormodule, move on
            if ($package->getType() !== self::MODULE_TYPE) {
                continue;
            }
            $module = new VendorModule($package, $event->getComposer());
            $folders = $module->getExposedFolders();
            if (empty($folders)) {
                continue;
            }
            $name = $module->getName();
            $io->write("Exposing web directories for module <info>{$name}</info>:");
            foreach ($folders as $folder) {
                $io->write("  - <info>$folder</info>");
                $exposeMethod->exposeDirectory(
                    Util::joinPaths($module->getInstallPath(), $folder),
                    Util::joinPaths($module->getResourcePath(), $folder)
                );
            }
        }
    }

    /**
     * @param string $path
     *
     * @return array
     */
    protected function mapExistingResources($path)
    {
        $fileMap = [];
        if (!file_exists($path)) {
            return $fileMap;
        }
        $files = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($files);
        /** @var \DirectoryIterator $file */
        foreach ($iterator as $file) {
            echo "checking {$file->getPathname()}" . PHP_EOL;
            $fileMap[] = $file->getPath();
        }
        return $fileMap;
    }

    /**
     * @return string
     */
    protected function getProjectPath()
    {
        return dirname(realpath(Factory::getComposerFile()));
    }

    /**
     * Ensure the resources folder is safely created and protected from index.php in root
     */
    protected function setupResources()
    {
        // Setup root dir
        $resourcesPath = Util::joinPaths(
            $this->getProjectPath(),
            VendorModule::DEFAULT_TARGET
        );
        $this->filesystem->ensureDirectoryExists($resourcesPath);

        // Copy missing resources
        $files = new DirectoryIterator(__DIR__.'/../resources');
        foreach ($files as $file) {
            $targetPath = $resourcesPath . DIRECTORY_SEPARATOR . $file->getFilename();
            if ($file->isFile() && !file_exists($targetPath)) {
                copy($file->getPathname(), $targetPath);
            }
        }
    }

    /**
     * @return ExposeMethod
     */
    protected function getMethod()
    {
        // Switch based on SS_VENDOR_METHOD arg
        switch (getenv(self::METHOD_ENV)) {
            case CopyMethod::NAME:
                return new CopyMethod();
            case SymlinkMethod::NAME:
                return new SymlinkMethod();
            case self::METHOD_NONE:
                // 'none' is forced to an empty chain
                return new ChainedMethod([]);
            case self::METHOD_AUTO:
            default:
                // Default to safe-failover method
                return new ChainedMethod(new SymlinkMethod(), new CopyMethod());
        }
    }
}
