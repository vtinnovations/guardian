<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vtinnovations\Guardian\Job\JobRunner;
use Vtinnovations\Guardian\Job\UpdateJobManager;

/**
 * The CLI worker command.
 *
 * Invoked by UpdateJobManager::startWorker() as a detached subprocess. Reads
 * the job by ID, runs all its steps, persists status updates and logs.
 *
 * Can also be run manually for diagnostics:
 *   bin/contao-console guardian:run-job <job-id>
 */
#[AsCommand(
    name: 'guardian:run-job',
    description: 'Executes a queued update job (CLI worker, internal use)',
)]
class RunJobCommand extends Command
{
    public function __construct(
        private readonly UpdateJobManager $manager,
        private readonly JobRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job-id', InputArgument::REQUIRED, 'ID of the job to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $jobId  = (string) $input->getArgument('job-id');

        // Raise PHP limits — this is the worker, it should be able to take its time
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ignore_user_abort(true);

        $job = $this->manager->getCurrentJob();

        if ($job === null) {
            $io->error('No active job in var/updater/job.json');
            return Command::FAILURE;
        }

        if ($job->id !== $jobId) {
            $io->error(sprintf('Job ID mismatch — active job is "%s", requested "%s"', $job->id, $jobId));
            return Command::FAILURE;
        }

        $io->title("Updater Job: {$job->id}");
        $io->writeln('Type: ' . $job->type);
        $io->writeln('Steps: ' . implode(' → ', $job->steps));

        try {
            $this->runner->run($job);
            $io->success("Job {$job->id} completed successfully");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Job failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
