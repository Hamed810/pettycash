<?php

declare(strict_types=1);

namespace OCA\PettyCash\Command;

use OCA\PettyCash\Application\Evidence\EvidenceReconciliationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * T130: `occ pettycash:evidence:reconcile` — read-only evidence diagnostic.
 *
 * Prints orphan AppData files, missing binaries, and oversized files, then
 * exits 0 when clean and 1 when gaps were reported. Never deletes: there is
 * no delete path in this class or in the service it calls, so a run cannot
 * become a cleanup by flag typo.
 */
final class EvidenceReconcileCommand extends Command
{
    protected static $defaultName = 'pettycash:evidence:reconcile';

    public function __construct(
        private readonly EvidenceReconciliationService $reconciliation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Report orphan evidence files and missing binaries without deleting anything')
            ->addOption(
                'stored-keys',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Stored binary keys to reconcile (repeatable; empty means nothing to examine)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $keys */
        $keys = (array) $input->getOption('stored-keys');
        $report = $this->reconciliation->diagnose($keys);

        $output->writeln(sprintf(
            'Evidence reconciliation: %d file(s) examined, %d orphan(s), %d missing, %d oversized.',
            $report['filesExamined'],
            count($report['orphanFiles']),
            count($report['missingBinaries']),
            count($report['oversizedFiles'])
        ));
        foreach ($report['orphanFiles'] as $key) {
            $output->writeln('  orphan: ' . $key);
        }
        foreach ($report['missingBinaries'] as $key) {
            $output->writeln('  missing: ' . $key);
        }
        foreach ($report['oversizedFiles'] as $key) {
            $output->writeln('  oversized (>100MB): ' . $key);
        }

        return ($report['orphanFiles'] === [] && $report['missingBinaries'] === [] && $report['oversizedFiles'] === [])
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
