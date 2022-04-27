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
    name: 'app:export',
    description: 'Gitlab export project',
)]
class ExportCommand extends Command
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
            if ($response->toArray()['export_status'] !== 'none') {
                $io->warning(sprintf('Export %s already in progress / finished (not none)', $project['name_with_namespace']));
                continue;
            }

            // trying to avoid 429 too many requests
            sleep(10);

            // schedule export
            $response = $this->gitlabClient->request('POST', sprintf("projects/%s/export", $project["id"]));
            if ($response->getStatusCode() === 202) {
                $io->success(sprintf('Export %s scheduled', $project['name_with_namespace']));
            } else {
                $io->error(sprintf('Export %s is not created; Status code %s', $project['name_with_namespace'], $response->getStatusCode()));
            }
        }

        $io->success('DONE');

        return Command::SUCCESS;
    }
}
