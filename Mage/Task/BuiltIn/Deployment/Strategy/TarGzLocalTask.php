<?php
/*
 * This file is part of the Magallanes package.
*
* (c) Andrés Montañez <andres@andresmontanez.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Mage\Task\BuiltIn\Deployment\Strategy;

use Mage\Task\BuiltIn\Deployment\Strategy\BaseStrategyTaskAbstract;
use Mage\Task\Releases\IsReleaseAware;

/**
 * Task for Sync the Local Code to another local directory via Tar GZ
 *
 * @author Andrés Montañez <andres@andresmontanez.com>
 */
class TarGzLocalTask extends BaseStrategyTaskAbstract implements IsReleaseAware
{
    /**
     * (non-PHPdoc)
     * @see \Mage\Task\AbstractTask::getName()
     */
    public function getName()
    {
        if ($this->getConfig()->release('enabled', false) === true) {
            if ($this->getConfig()->getParameter('overrideRelease', false) === true) {
                return 'Deploy locally via TarGz (with Releases override) [built-in]';
            } else {
                return 'Deploy locally via TarGz (with Releases) [built-in]';
            }
        } else {
            return 'Deploy locally via TarGz [built-in]';
        }
    }

    /**
     * Syncs the Local Code to the Remote Host
     * @see \Mage\Task\AbstractTask::run()
     */
    public function run()
    {
        $this->checkOverrideRelease(false);

        $excludes = $this->getExcludes();
        $excludesListFilePath   = $this->getConfig()->deployment('excludes_file', '');
        ;

        // If we are working with releases
        $deployToDirectory = $this->getConfig()->deployment('to');
        if ($this->getConfig()->release('enabled', false) === true) {
            $releasesDirectory = $this->getConfig()->release('directory', 'releases');
            $deployToDirectory = rtrim($this->getConfig()->deployment('to'), '/')
                . '/' . $releasesDirectory
                . '/' . $this->getConfig()->getReleaseId();
            $output = null;
            $this->runCommandLocal('mkdir -p ' . $deployToDirectory, $output);
        }
        $output = null;
        // Create Tar Gz
        $localTarGz = tempnam(sys_get_temp_dir(), 'mage');
        $remoteTarGz = basename($localTarGz);
        $excludeCmd = '';
        foreach ($excludes as $excludeFile) {
            $excludeCmd .= ' --exclude=' . $excludeFile;
        }

        $excludeFromFileCmd = $this->excludesListFile($excludesListFilePath);

        // Strategy Flags
        $strategyFlags = $this->getConfig()->deployment('strategy_flags', $this->getConfig()->general('strategy_flags', array()));
        if (isset($strategyFlags['targz']) && isset($strategyFlags['targz']['create'])) {
            $strategyFlags = $strategyFlags['targz']['create'];
        } else {
            $strategyFlags = '';
        }

        // remove h option only if dump-symlinks is allowed in the release config part
        $dumpSymlinks = $this->getConfig()->release('dump-symlinks') ? '' : 'h';

        $command = 'tar cfz'. $dumpSymlinks . $strategyFlags . ' ' . $localTarGz . '.tar.gz ' . $excludeCmd . $excludeFromFileCmd . ' -C ' . $this->getConfig()->deployment('from') . ' .';
        $result = $this->runCommandLocal($command, $output);

        // Strategy Flags
        $strategyFlags = $this->getConfig()->deployment('strategy_flags', $this->getConfig()->general('strategy_flags', array()));
        if (isset($strategyFlags['targz']) && isset($strategyFlags['targz']['exctract'])) {
            $strategyFlags = $strategyFlags['targz']['exctract'];
        } else {
            $strategyFlags = '';
        }

        // Copy Tar Gz  to another location (not remote)
        //$command = 'cp ' . $strategyFlags . ' ' . $localTarGz . '.tar.gz ' . $deployToDirectory;
        $command = 'cp -a ' . $localTarGz . '.tar.gz ' . $deployToDirectory;
        $result = $this->runCommandLocal($command, $output) && $result;

        // Strategy Flags
        $strategyFlags = $this->getConfig()->deployment('strategy_flags', $this->getConfig()->general('strategy_flags', array()));
        if (isset($strategyFlags['targz']) && isset($strategyFlags['targz']['cp'])) {
            $strategyFlags = $strategyFlags['targz']['cp'];
        } else {
            $strategyFlags = '';
        }

        // Extract Tar Gz
        $command = $this->getReleasesAwareCommand('tar xfz' . $strategyFlags . ' ' . $remoteTarGz . '.tar.gz');
        //cd to root "to" because getRealeaseAwareCommand cd to release/xxxxxxxx
        $command = 'cd '.$this->getConfig()->deployment('to')  . ' && ' . $command;
        $result = $this->runCommandLocal($command, $output) && $result;

        // Delete Tar Gz from Remote Host
        $command = $this->getReleasesAwareCommand('rm -f ' . $remoteTarGz . '.tar.gz');
        $command = 'cd '.$this->getConfig()->deployment('to') . ' && ' . $command;
        $result = $this->runCommandLocal($command, $output) && $result;

        // Delete Tar Gz from Local
        $command = 'rm -f ' . $localTarGz . ' ' . $localTarGz . '.tar.gz';
        $result = $this->runCommandLocal($command, $output) && $result;

        return $result;
    }

    /**
     * Generates the Exclude from file for rsync
     * @param string $excludesFile
     * @return string
     */
    protected function excludesListFile($excludesFile)
    {
        $excludesListFileRsync = '';
        if (!empty($excludesFile) && file_exists($excludesFile) && is_file($excludesFile) && is_readable($excludesFile)) {
            $excludesListFileRsync = ' --exclude-from=' . $excludesFile;
        }
        return $excludesListFileRsync;
    }
}
