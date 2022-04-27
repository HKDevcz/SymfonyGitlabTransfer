<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:export_list',
    description: 'Gitlab export project list formatted for markdown',
)]
class ExportListCommand extends Command
{

    public function __construct(private HttpClientInterface $gitlabClient, private Filesystem $filesystem)
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
                'order_by' => 'name',
                'per_page' => 100,
                'sort' => 'asc',
            ]
        ]);
        if ($response->getStatusCode() !== 200) {
            $io->error('Could not get project list for the group ID');

            return Command::FAILURE;
        }

        $path = sprintf('var/group_%s.txt', $input->getArgument('group'));
        $this->filesystem->touch($path);

        foreach ($response->toArray() as $project) {
            // read status
            $response = $this->gitlabClient->request('GET', sprintf("projects/%s/export", $project["id"]));
            if ($response->getStatusCode() !== 200 || $response->toArray()['export_status'] !== 'finished') {
                $io->error(sprintf('Could not add project to the export list %s', $project['name_with_namespace']));
                continue;
            }
            $this->filesystem->appendToFile($path, sprintf('- [ ] %s  %s', $response->toArray()['name_with_namespace'], PHP_EOL));
            $io->success(sprintf('Export %s added to the list', $project['name_with_namespace']));
        }

        $io->success('DONE');

        return Command::SUCCESS;
    }
}
