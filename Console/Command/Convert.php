<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Elgentos\ComposerQualityPatches\Console\Command;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Convert
 * @package Elgentos\ComposerQualityPatches\Console\Command
 */
class Convert extends Command
{
    const DIVIDER = '─────────────';
    const NOT_APPLIED = 'Not Applied';
    const NOT_APPLICABLE = 'N/A';
    const APPLIED = 'Applied';
    const COMPOSER_QUALITY_PATCHES_JSON = 'composer.quality-patches.json';

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
    protected $version = 'os';
    /**
     * @var mixed
     */
    protected $patchesJsonContent;
    protected $outputArray = [];

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Magento\CloudPatches\Shell\PackageNotFoundException
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

        $this->patchesJsonContent = json_decode(file_get_contents('./vendor/magento/quality-patches/patches.json'), true);

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
                && $patchData['type'] !== \Magento\CloudPatches\Patch\Collector\QualityCollector::PROP_DEPRECATED
            ) {
                $patchData['affected_components'] = $this->getAffectedComponents($patch);
                list($patchData['file'], $patchData['description']) = $this->getFileAndDescriptionFromPatchesJson($patchData['patchId']);
                $this->addPatchDataToOutputArray($patchData);
            }
        }

        file_put_contents(self::COMPOSER_QUALITY_PATCHES_JSON, json_encode(['patches' => $this->outputArray], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $this->output->writeln('Created ' . self::COMPOSER_QUALITY_PATCHES_JSON . ' file with quality patches structure for usage with vaimo/composer-patches package');

        $rootComposerJson = json_decode(file_get_contents('composer.json'), true);
        if (!isset($rootComposerJson['extra']['patches-file']) || !in_array(self::COMPOSER_QUALITY_PATCHES_JSON, $rootComposerJson['extra']['patches-file'])) {
            $rootComposerJson['extra']['patches-file'][] = self::COMPOSER_QUALITY_PATCHES_JSON;
            file_put_contents('composer.json', json_encode($rootComposerJson));
            $this->output->writeln('Added ' . self::COMPOSER_QUALITY_PATCHES_JSON . ' to extra.patches-file key in composer.json');
        }

    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName("elgentos:quality-patches:convert");
        $this->setDescription("Convert composer quality patches");
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
            \Magento\CloudPatches\Patch\Status\StatusPool::NOT_APPLIED,
            \Magento\CloudPatches\Patch\Status\StatusPool::APPLIED,
            \Magento\CloudPatches\Patch\Status\StatusPool::NA,
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
            \Magento\CloudPatches\Patch\Collector\QualityCollector::PROP_DEPRECATED,
            \Magento\CloudPatches\Patch\Data\PatchInterface::TYPE_OPTIONAL,
            \Magento\CloudPatches\Patch\Data\PatchInterface::TYPE_REQUIRED,
            \Magento\CloudPatches\Patch\Data\PatchInterface::TYPE_CUSTOM,
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

    private function findPatchFile(array $patchData)
    {
        print_r(glob('vendor/magento/quality-patches/patches/' . $this->version
            .'/' . $patchData['patchId'] . '*'));
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
            // not implemented yet
        }

    }

    private function getLevel(): int
    {
        return 4;
    }
}
