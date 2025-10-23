<?php

namespace App\WorkflowRodoud\Command;

use App\Repository\AppRepository;
use App\Workflow\Jobs\Chatbot\PrepareInstructionJob;
use App\WorkflowRodoud\Services\WorkflowRedisTracker;
use App\WorkflowRodoud\Workflow;
use Redis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[AsCommand(
    name: 'rodoud:job:execute',
    description: 'Execute a workflow asynchronously',
)]
class JobWorkerCommand extends Command
{
    public function __construct(
        private ContainerInterface $container,
        private AppRepository      $appRepository,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'file containing the job definition');;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        // Save current error reporting level
        $errorReporting = error_reporting();

        // Disable deprecation notices
        error_reporting($errorReporting & ~E_USER_DEPRECATED & ~E_DEPRECATED);
        $dataFile = $input->getArgument('file');


        if (!file_exists($dataFile)) {
            echo json_encode([
                'success' => false,
                'error' => 'Job data file not found: ' . $dataFile,
                'result' => null,
                'logs' => [],
            ]);
            exit(1);
        }

        try {
            // Read job data
            $jobData = json_decode(file_get_contents($dataFile), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON in job data file');
            }


            // Deserialize the job
            $job_array = json_decode(base64_decode($jobData['job']), true);

            $job = $this->container->get($job_array['class']);

            if (!$job instanceof \App\WorkflowRodoud\Contracts\JobInterface) {
                throw new Exception('Invalid job object');
            }

            // Set job ID
            $job->setId($jobData['stepId']);

            $globals = unserialize($jobData['globals'], ["allowed_classes" => true]);

            // Prepare inputs (merge globals with specific inputs)
            $inputs = $jobData['inputs'] ?? [];

            // Create a minimal context object for the job
            // If your jobs need the full WorkflowContext, you'll need to serialize/deserialize it
            $context = new \App\WorkflowRodoud\WorkflowContext($jobData['workflowId'], "");
            $context->workflowId = $jobData['workflowId'];
            $context->globals = $globals;


            // Execute the job
            $result = $job->run($inputs, $context);
            // Collect logs and errors
            $logs = [];
            $errors = [];

            if (method_exists($job, 'getLogs')) {
                $logs = $job->getLogs();
            }

            if (method_exists($job, 'getErrors')) {
                $errors = $job->getErrors();
            }

            if (method_exists($job, 'hasErrors') && $job->hasErrors()) {
                throw new Exception('Job reported errors: ' . implode(', ', $errors));
            }

            // Return success response
            echo json_encode([
                'success' => true,
                'stepId' => $jobData['stepId'],
                'result' => $result,
                'logs' => $logs,
                'errors' => $errors,
                'memory_used' => memory_get_usage(),
                'peak_memory' => memory_get_peak_usage(),
            ]);


            return Command::SUCCESS;


        } catch (Throwable $e) {
            // Return error response
            echo json_encode([
                'success' => false,
                'stepId' => $jobData['stepId'] ?? 'unknown',
                'result' => null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'logs' => [],
            ]);

            return Command::FAILURE;
        }

    }


}
