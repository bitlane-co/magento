<?php
/**
 * Bitlane Search for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace Bitlane\Search\Console\Command;

use Bitlane\Search\Model\Queue\Runner;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bin/magento bitlane:run [--seconds=50]: runs the extension's due jobs for up to that long.
 *
 * One runner at a time: the cron group's, this command's or the screen's.
 * For stores whose cron is late, or a process manager (Supervisor) that runs
 * it again as it ends. It runs in the cron's area, as the cron group does.
 */
class RunCommand extends Command
{
    /**
     * @param Runner $runner a proxy (etc/di.xml): bin/magento builds every command
     * @param State $appState
     */
    public function __construct(
        private readonly Runner $runner,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('bitlane:run')
            ->setDescription('Runs the Bitlane extension’s waiting jobs: the catalogue’s sync.')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'How long to run at most', '50');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException) {
            // Set already, by whatever ran the command.
            $output->writeln('', OutputInterface::VERBOSITY_DEBUG);
        }
        $ran = $this->runner->run(max(1, (int) $input->getOption('seconds')));
        $output->writeln($ran . ' jobs run.');

        return Command::SUCCESS;
    }
}
