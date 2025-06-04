<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Football2;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeFootballTips extends Command
{
    protected $signature = 'scrape:football-tips {--debug}';
    protected $description = 'Scrape football betting tips from over25tips.com';

    public function handle()
    {
        setlocale(LC_ALL, 'pt_BR', 'pt_BR.iso-8859-1', 'pt_BR.utf-8', 'portuguese');

        try {
            $this->info('Fetching data from website...');
            $response = Http::get('https://www.over25tips.com/free-football-betting-tips/');

            if ($response->failed()) {
                $this->error('Failed to connect to the website');
                return;
            }

            $html = $response->body();

            // Debug option to save HTML for inspection
            if ($this->option('debug')) {
                file_put_contents(storage_path('logs/football_tips.html'), $html);
                $this->info('HTML saved to storage/logs/football_tips.html for debugging');
            }

            $crawler = new Crawler($html);

            // More flexible table detection
            $table = $crawler->filter('table')->reduce(function (Crawler $node) {
                // Look for a table that contains prediction data
                return str_contains($node->html(), 'predictionsTable') ||
                       str_contains($node->html(), 'betting-tips');
            })->first();

            if ($table->count() === 0) {
                $this->error('Could not find predictions table on the page');
                $this->line('Trying alternative detection method...');

                // Alternative approach - look for table rows with specific classes
                $rows = $crawler->filter('tr')->reduce(function (Crawler $row) {
                    return $row->filter('td')->count() >= 5; // At least 5 columns
                });
            } else {
                $rows = $table->filter('tr')->reduce(function (Crawler $row) {
                    return $row->filter('td')->count() >= 5; // At least 5 columns
                });
            }

            if ($rows->count() === 0) {
                $this->error('No valid rows found in the table');
                return;
            }

            $this->info("Found {$rows->count()} potential rows to process");
            $insertedCount = 0;

            $rows->each(function (Crawler $row) use (&$insertedCount) {
                try {
                    $columns = $row->filter('td');

                    // Try to extract time (more flexible approach)
                    $time = $this->extractTime($row);

                    // Try to extract league
                    $league = $this->extractText($row, ['.league-name', 'span', 'td:nth-child(2)']);

                    // Try to extract teams
                    $homeTeam = $this->extractText($row, ['.home-team', '.team-home', 'td:nth-child(3)']);
                    $awayTeam = $this->extractText($row, ['.away-team', '.team-away', 'td:nth-child(5)']);

                    // Try to extract prediction
                    $prediction = $this->extractText($row, ['.prediction', 'td:nth-child(10)', 'td:last-child']);
                    $prediction = $this->translatePrediction($prediction);

                    // Try to extract stats
                    $homeScored = $this->extractStat($row, 'home-scored');
                    $homeConceded = $this->extractStat($row, 'home-conceded');
                    $awayScored = $this->extractStat($row, 'away-scored');
                    $awayConceded = $this->extractStat($row, 'away-conceded');

                    $data = [
                        'data' => date('Y-m-d'),
                        'horario' => $time,
                        'liga' => trim($league),
                        'casa' => trim($homeTeam),
                        'visitante' => trim($awayTeam),
                        'prediction' => trim($prediction),
                        'descricao' => $this->buildDescription(
                            $homeTeam, $awayTeam,
                            $homeScored, $homeConceded,
                            $awayScored, $awayConceded
                        )
                    ];

                    if ($this->option('debug')) {
                        $this->line('Extracted data:');
                        dump($data);
                    }

                    Football2::create($data);
                    $insertedCount++;

                } catch (\Exception $e) {
                    Log::error('Error processing row: ' . $e->getMessage());
                    if ($this->option('debug')) {
                        $this->warn('Error in row: ' . $e->getMessage());
                        $this->line($row->html());
                    }
                }
            });

            $this->info("Successfully inserted {$insertedCount} records");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::error("Error in scrape:football-tips: " . $e->getMessage());
        }
    }

    protected function extractTime(Crawler $row): string
    {
        try {
            // Multiple ways to try to find the time
            $time = $this->extractText($row, [
                '.hour_start',
                '.match-time',
                'td:nth-child(1)',
                'td:first-child'
            ]);

            if ($time && preg_match('/\d{1,2}:\d{2}/', $time, $matches)) {
                return $matches[0];
            }

            return date('H:i');
        } catch (\Exception $e) {
            return '00:00';
        }
    }

    protected function extractText(Crawler $row, array $selectors): string
    {
        foreach ($selectors as $selector) {
            try {
                if ($row->filter($selector)->count() > 0) {
                    return $row->filter($selector)->text();
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        return '';
    }

    protected function extractStat(Crawler $row, string $class): float
    {
        try {
            $text = $this->extractText($row, [".{$class}", ".{$class}-stat"]);
            return (float) filter_var($text, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function buildDescription($homeTeam, $awayTeam, $hs, $hc, $as, $ac): string
    {
        return sprintf(
            "%s: marca %.1f, sofre %.1f | %s: marca %.1f, sofre %.1f",
            $homeTeam, $hs, $hc, $awayTeam, $as, $ac
        );
    }

    protected function translatePrediction(string $prediction): string
    {
        $replacements = [
            'Under' => 'Abaixo de',
            'Over' => 'Acima de',
            'goals' => 'gols',
            'Home' => 'Time da casa',
            'Away' => 'Time visitante',
            'Win' => 'ganha',
            'and' => 'e',
            'Btts' => 'ambos os times marcam',
            'Yes' => 'Sim',
            'No' => 'Não'
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $prediction);
    }
}
// Script em PHP que coleta dados da tabela do site https://www.over25tips.com/free-football-betting-tips/
// le pega certos dados da tabela de hoje das dicas de apostas de resultados de futebol e coloca numa tabela MySQL. O comando SQL para criar a tabela compatível com o script está anexo.
