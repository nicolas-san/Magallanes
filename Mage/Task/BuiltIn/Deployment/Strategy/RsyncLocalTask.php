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

use Mage\Console;
use Mage\Task\BuiltIn\Deployment\Strategy\BaseStrategyTaskAbstract;
use Mage\Task\Releases\IsReleaseAware;

/**
 * Task for Sync the Local Code to the Remote Hosts via RSYNC
 *
 * @author Andrés Montañez <andres@andresmontanez.com>
 */
class RsyncLocalTask extends BaseStrategyTaskAbstract implements IsReleaseAware
{
    /**
     * (non-PHPdoc)
     * @see \Mage\Task\AbstractTask::getName()
     */
    public function getName()
    {
        if ($this->getConfig()->release('enabled', false) === true) {
            if ($this->getConfig()->getParameter('overrideRelease', false) === true) {
                return 'Deploy locally via Rsync (with Releases override) [built-in]';
            } else {
                $rsync_copy = $this->getConfig()->deployment("rsync-local");
                if ($rsync_copy && is_array($rsync_copy) && $rsync_copy['copy']) {
                    return 'Deploy locally via Rsync (with Releases) [built-in, incremental]';
                } else {
                    return 'Deploy locally via Rsync (with Releases) [built-in]';
                }
            }
        } else {
            return 'Deploy locally via Rsync [built-in]';
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
        $excludesListFilePath = $this->getConfig()->deployment('excludes_file', '');

        $output = null;
        // If we are working with releases
        $deployToDirectory = $this->getConfig()->deployment('to');
        if ($this->getConfig()->release('enabled', false) === true) {
            $releasesDirectory = $this->getConfig()->release('directory', 'releases');
            $symlink = $this->getConfig()->release('symlink', 'current');

            $currentRelease = false;
            $deployToDirectory = rtrim($this->getConfig()->deployment('to'), '/')
                               . '/' . $releasesDirectory
                               . '/' . $this->getConfig()->getReleaseId();

            Console::log('Deploy to ' . $deployToDirectory);
            $resultFetch = $this->runCommandLocal('ls -ld ' . rtrim($this->getConfig()->deployment('to'), '/') . '/' . $symlink . ' | cut -d"/" -f2', $currentRelease);

            if ($resultFetch && $currentRelease) {
                // If deployment configuration is rsync, include a flag to simply sync the deltas between the prior release
                // rsync: { copy: yes }
                $rsync_copy = $this->getConfig()->deployment('rsync-local');
                // If copy_tool_rsync, use rsync rather than cp for finer control of what is copied
                if ($rsync_copy && is_array($rsync_copy) && $rsync_copy['copy'] && $this->runCommandLocal('test -d ' . $releasesDirectory . '/' . $currentRelease, $output)) {
                    if (isset($rsync_copy['copy_tool_rsync'])) {
                        $this->runCommandLocal("rsync -a {$this->excludes(array_merge($excludes, $rsync_copy['rsync_excludes']))} "
                                          . "$releasesDirectory/$currentRelease/ $releasesDirectory/{$this->getConfig()->getReleaseId()}", $output);
                    } else {
                        $this->runCommandLocal('cp -R ' . $releasesDirectory . '/' . $currentRelease . ' ' . $releasesDirectory . '/' . $this->getConfig()->getReleaseId(), $output);
                    }
                } else {
                    $this->runCommandLocal('mkdir -p ' . $releasesDirectory . '/' . $this->getConfig()->getReleaseId(), $output);
                }
            }
        }

        // Strategy Flags
        $strategyFlags = $this->getConfig()->deployment('strategy_flags', $this->getConfig()->general('strategy_flags', array()));
        if (isset($strategyFlags['rsync-local'])) {
            $strategyFlags = $strategyFlags['rsync-local'];
        } else {
            $strategyFlags = '';
        }

        $command = 'rsync -avz '
                 . $strategyFlags . ' '
                 . $this->excludes($excludes) . ' '
                 . $this->getConfig()->deployment('from') . ' '
                 . $this->excludesListFile($excludesListFilePath) . ' ' .$deployToDirectory;

        $result = $this->runCommandLocal($command, $output);

        return $result;
    }

    /**
     * Generates the Excludes for rsync
     * @param array $excludes
     * @return string
     */
    protected function excludes(Array $excludes)
    {
        $excludesRsync = '';
        foreach ($excludes as $exclude) {
            $excludesRsync .= ' --exclude=' . escapeshellarg($exclude) . ' ';
        }

        $excludesRsync = trim($excludesRsync);
        return $excludesRsync;
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
