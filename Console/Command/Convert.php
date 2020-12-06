<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Elgentos\ComposerQualityPatches\Console\Command;

use Magento\CloudPatches\Patch\Collector\QualityCollector;
use Magento\CloudPatches\Patch\Data\PatchInterface;
use Magento\CloudPatches\Patch\Status\StatusPool;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Convert
 * @package Elgentos\ComposerQualityPatches\Console\Command
 */
class Convert extends Command
{
    const DIVIDER = '─────────────';
    const COMPOSER_QUALITY_PATCHES_JSON = 'composer.quality-patches.json';

    /**
     * @var Json
     */
    public $json;
    /**
     * @var DirectoryList
     */
    public $directoryList;
    /**
     * @var Filesystem
     */
    public $filesystem;
    /**
     * @var InputInterface
     */
    protected $input;
    /**
     * @var OutputInterface
     */
    protected $output;
    /**
     * @var array
     */
    protected $data = [];
    /**
     * @var mixed
     */
    protected $patchesJsonContent;
    /**
     * @var array
     */
    protected $outputArray = [];

    /**
     * Convert constructor.
     * @param Json $json
     * @param DirectoryList $directoryList
     * @param Filesystem $filesystem
     * @param string|null $name
     */
    public function __construct(
        Json $json,
        DirectoryList $directoryList,
        Filesystem $filesystem,
        string $name = null
    ) {
        parent::__construct($name);
        $this->json = $json;
        $this->directoryList = $directoryList;
        $this->filesystem = $filesystem;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->input = $input;
        $this->output = $output;

        $process = new Process(['./vendor/bin/magento-patches','status']);
        $process->mustRun();

        $output = $process->getOutput();
        $lines = explode(PHP_EOL, $output);

        $this->patchesJsonContent = $this->json->unserialize($this->filesystem->getDirectoryRead(DirectoryList::ROOT)->readFile('vendor/magento/quality-patches/patches.json'));

        $patches = [];
        $patch = '';
        $firstLineSeen = false;

        // Gather patch blocks
        foreach($lines as $line) {
            if (stripos($line, 'Id') !== false && stripos($line, 'Title') !== false) {
                $firstLineSeen = true;
                continue;
            }
            if ($firstLineSeen) {
                $patch .= $line . PHP_EOL;
                if (stripos($line, '─────────────') !== false) {
                    // New patch
                    $patches[] = $patch;
                    $patch = '';
                }
            }
        }

        // Process patches into uniform array
        foreach ($patches as $patch) {
            $patchData = [];

            // Get patch ID
            $patchData['patchId'] = $this->getPatchId($patch);
            $patchData['status'] = $this->getStatus($patch);
            $patchData['type'] = $this->getType($patch);
            if (
                $patchData['patchId']
                && $patchData['status']
                && $patchData['type']
                && $patchData['type'] !== QualityCollector::PROP_DEPRECATED
            ) {
                $patchData['affected_components'] = $this->getAffectedComponents($patch);
                list($patchData['file'], $patchData['description']) = $this->getFileAndDescriptionFromPatchesJson($patchData['patchId']);
                $this->addPatchDataToOutputArray($patchData);
            }
        }

        $this->filesystem->getDirectoryWrite(DirectoryList::ROOT)->writeFile(self::COMPOSER_QUALITY_PATCHES_JSON, $this->encodeJson(['patches' => $this->outputArray]));
        $this->output->writeln('<info>Created ' . self::COMPOSER_QUALITY_PATCHES_JSON . ' file with quality patches structure for usage with vaimo/composer-patches package</info>');

        $this->addQualityPatchesJsonToRootComposerJson();
        $this->installPostUpdateCmd();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('elgentos:quality-patches:convert');
        $this->setDescription('Convert composer quality patches');
        parent::configure();
    }

    /**
     * @param string $patch
     * @return string|null
     */
    protected function getPatchId(string $patch): ?string
    {
        preg_match('/((MDVA|MC)-[0-9]{5}(-V[0-9]{1})?)/', $patch, $matches);
        if (isset($matches[0])) {
            return $matches[0];
        }
        return null;
    }

    private function getStatus(string $patch): ?string
    {
        $statuses = [
            StatusPool::NOT_APPLIED,
            StatusPool::APPLIED,
            StatusPool::NA,
        ];
        foreach ($statuses as $status) {
            if (stripos($patch, $status) !== false) {
                return $status;
            }
        }
        return null;
    }

    /**
     * @param string $patch
     * @return string|null
     */
    private function getType(string $patch): ?string
    {
        $types = [
            QualityCollector::PROP_DEPRECATED,
            PatchInterface::TYPE_OPTIONAL,
            PatchInterface::TYPE_REQUIRED,
            PatchInterface::TYPE_CUSTOM,
        ];
        foreach ($types as $type) {
            if (stripos($patch, $type) !== false) {
                return $type;
            }
        }
        return null;
    }

    /**
     * @param string $patch
     * @return array
     */
    private function getAffectedComponents(string $patch): array
    {
        preg_match_all('/(magento\/module-[a-z\-]*)/', $patch, $components);
        if (isset($components[0])) {
            return $components[0];
        }
        return [];
    }

    /**
     * @param string|null $patchId
     * @return string[]
     */
    private function getFileAndDescriptionFromPatchesJson(?string $patchId): array
    {
        if (isset($this->patchesJsonContent[$patchId])) {
            $package = array_key_first($this->patchesJsonContent[$patchId]);
            $description = array_key_first($this->patchesJsonContent[$patchId][$package]);
            $versionConstraint = array_key_first($this->patchesJsonContent[$patchId][$package][$description]);
            $file = $this->patchesJsonContent[$patchId][$package][$description][$versionConstraint]['file'];
            $file = './vendor/magento/quality-patches/patches/' . $file;
            return [$file, $description];
        }
        return [];
    }

    /**
     * @param array $patchData
     */
    private function addPatchDataToOutputArray(array $patchData)
    {
        if (count($patchData['affected_components']) === 1) {
            $patchName = 'Quality Patch ' . $patchData['patchId'] . ' ' . $patchData['description'];
            $module = $patchData['affected_components'][0];
            $this->outputArray[$module] = [$patchName => [
                'source' => $patchData['file'],
                'level' => $this->getLevel()
            ]];
        } else {
            // @TODO not implemented yet
        }
    }

    /**
     * @return int
     */
    private function getLevel(): int
    {
        return 4;
    }

    /**
     *
     * @throws FileSystemException
     */
    private function addQualityPatchesJsonToRootComposerJson(): void
    {
        $originalRootComposerJson = $rootComposerJson = $this->json->unserialize($this->filesystem->getDirectoryRead(DirectoryList::ROOT)->readFile('composer.json'));
        if (!isset($rootComposerJson['extra']['patches-file'])) {
            $rootComposerJson['extra']['patches-file'] = [self::COMPOSER_QUALITY_PATCHES_JSON];
        } else {
            $patchesFile = $rootComposerJson['extra']['patches-file'];
            if (is_string($patchesFile)) {
                $rootComposerJson['extra']['patches-file'] = [$patchesFile, self::COMPOSER_QUALITY_PATCHES_JSON];
            } elseif (is_array($patchesFile) && !in_array(self::COMPOSER_QUALITY_PATCHES_JSON, $patchesFile)) {
                $rootComposerJson['extra']['patches-file'][] = self::COMPOSER_QUALITY_PATCHES_JSON;
            }
        }
        if (strcmp($this->json->serialize($originalRootComposerJson), $this->json->serialize($rootComposerJson)) !== 0) {
            $this->filesystem->getDirectoryWrite(DirectoryList::ROOT)->writeFile('composer.json', $this->encodeJson($rootComposerJson));
            $this->output->writeln('<comment>Added ' . self::COMPOSER_QUALITY_PATCHES_JSON . ' to extra.patches-file key in composer.json</comment>');
        }
    }

    /**
     *
     * @throws FileSystemException
     */
    private function installPostUpdateCmd(): void
    {
        $rootComposerJson = $this->json->unserialize($this->filesystem->getDirectoryRead(DirectoryList::ROOT)->readFile('composer.json'));
        $command = 'bin/magento ' . $this->getName();
        if (!isset($rootComposerJson['scripts']['post-update-cmd']) || !in_array($command, $rootComposerJson['scripts']['post-update-cmd'])) {
            $rootComposerJson['scripts']['post-update-cmd'][] = $command;
            $this->filesystem->getDirectoryWrite(DirectoryList::ROOT)->writeFile('composer.json', $this->encodeJson($rootComposerJson));
            $this->output->writeln('<comment>' . $command . ' has been added as a post-update-cmd script in composer.json</comment>');
        }
    }

    /**
     * @param array $data
     * @return false|string
     */
    private function encodeJson(array $data) {
        return json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    }
}
