<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:import',
    description: 'Gitlab import project',
)]
class ImportCommand extends Command
{

    public function __construct(
        private HttpClientInterface $gitlabClient,
        private HttpClientInterface $gitlab2Client,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('group', InputArgument::REQUIRED, 'Source group ID');
        $this->addArgument('group2', InputArgument::REQUIRED, 'Target group ID');
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $response = $this->gitlabClient->request('GET', sprintf('groups/%s/projects', $input->getArgument('group')), [
            'query' => [
                'order_by' => 'name',
                'per_page' => 100,
                'sort' => 'asc',
            ]
        ]);
        if ($response->getStatusCode() !== 200) {
            $io->error('Could not get project list for the group ID');

            return Command::FAILURE;
        }

        foreach ($response->toArray() as $project) {
            $question = new ConfirmationQuestion(sprintf('Do you want to import %s? [y/n] (default: n): ', $project['name_with_namespace']), false);
            if (!$helper->ask($input, $output, $question)) {
                continue;
            }

            $question = new Question(sprintf('Please enter the name? (default: %s): ', $project['name']), $project['name']);
            $name = $helper->ask($input, $output, $question);

            $question = new Question(sprintf('Please enter the path? (default: %s): ', $project['path']), $project['path']);
            $path = $helper->ask($input, $output, $question);

            $group2 = $input->getArgument('group2');
            $formFields = [
                'namespace' => $group2,
                'name' => $name,
                'path' => $path,
                'file' => DataPart::fromPath(sprintf('var/%s.tar.gz', $project['path'])),
            ];
            $formData = new FormDataPart($formFields);
            $response = $this->gitlab2Client->request('POST', 'projects/import', [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable()
            ]);
            if ($response->getStatusCode() === 201) {
                $io->success(sprintf('Project %s is scheduled for import.', $project['name_with_namespace']));
            }
        }

        $io->success('DONE');

        return Command::SUCCESS;
    }
}
