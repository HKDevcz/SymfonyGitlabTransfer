<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:export_status',
    description: 'Gitlab export project status',
)]
class ExportStatusCommand extends Command
{

    public function __construct(private HttpClientInterface $gitlabClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('group', InputArgument::REQUIRED, 'Group ID');
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

        $response = $this->gitlabClient->request('GET', sprintf('groups/%s/projects', $input->getArgument('group')), [
            'query' => [
                'per_page' => 100,
            ]
        ]);
        if ($response->getStatusCode() !== 200) {
            $io->error('Could not get project list for the group ID');

            return Command::FAILURE;
        }

        foreach ($response->toArray() as $project) {
            // read status
            $response = $this->gitlabClient->request('GET', sprintf("projects/%s/export", $project["id"]));
            if ($response->getStatusCode() !== 200 || $response->toArray()['export_status'] === null) {
                $io->error(sprintf('Could not read export status for %s', $project['name_with_namespace']));
                continue;
            }
            $io->info(sprintf('Export %s status: %s', $project['name_with_namespace'], $response->toArray()['export_status']));
        }

        $io->success('DONE');

        return Command::SUCCESS;
    }
}
