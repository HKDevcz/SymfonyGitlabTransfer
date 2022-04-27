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
    name: 'app:download_export',
    description: 'Download Gitlab exports to the local file sistem',
)]
class DownloadExportCommand extends Command
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
            // read status
            $response = $this->gitlabClient->request('GET', sprintf("projects/%s/export", $project["id"]));
            $export = $response->toArray();
            if ($response->getStatusCode() !== 200 || $export['export_status'] !== 'finished') {
                $io->error(sprintf('Could not download export for %s; Status: %s', $project['name_with_namespace'], $response->getStatusCode()));
                continue;
            }
            // get file link
            $response = $this->gitlabClient->request('GET', $export['_links']['api_url']);
            $fileHandler = fopen(sprintf('var/%s.tar.gz', $project['path']), 'w');
            foreach ($this->gitlabClient->stream($response) as $chunk) {
                fwrite($fileHandler, $chunk->getContent());
            }
            fclose($fileHandler);
            $io->success(sprintf('Export %s downloaded', $project['name_with_namespace']));
        }

        $io->success('DONE');

        return Command::SUCCESS;
    }
}
