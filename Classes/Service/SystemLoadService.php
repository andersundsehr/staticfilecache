<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SystemLoadService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected int $secondsMainLoopWaiting = 60;
    protected float $loadCap = 4;
    protected bool $loadWatchEnabled = false;

    public function __construct(ConfigurationService $configurationService)
    {
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
        $this->loadSettings($configurationService);
    }

    public function wait(): self
    {
        usleep($this->secondsMainLoopWaiting);
        return $this;
    }

    public function loadExceeded(): bool
    {
        return ((float)sys_getloadavg()[0]) > $this->loadCap;
    }

    public function enabled(): bool
    {
        if (!is_array(sys_getloadavg())) {
            $this->logger->error('Can not check load');
            return false;
        }
        return $this->loadWatchEnabled;
    }

    protected function loadSettings(ConfigurationService $configurationService): void
    {
        foreach (['secondsMainLoopWaiting', 'loadCap', 'loadWatchEnabled'] as $settingKey) {
            $settingValue = $configurationService->get($settingKey);
            if (null === $settingValue) {
                $this->logger->notice('Configuration for ' . $settingKey . ' not found, using default of ' . $this->{$settingKey});
                return;
            }
            switch ($settingKey) {
                case 'secondsMainLoopWaiting':
                    $this->secondsMainLoopWaiting = (int)$settingValue;
                    break;
                case 'loadCap':
                    $this->loadCap = (float)$settingValue;
                    break;
                case 'loadWatchEnabled':
                    $this->loadWatchEnabled = (bool)$settingValue;
                    break;
            }
        }
    }
}
